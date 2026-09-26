<?php

declare(strict_types=1);

namespace App\Message\Service\Meta\Template;

use App\Entity\Maestro\MaestroIdioma;
use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Entity\MetaConfig;
use App\Exchange\Service\Client\WhatsappMetaClient;
use App\Message\Dto\PlantillaMeta\PlantillaMeta;
use App\Message\Entity\MessageTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Servicio encargado de sincronizar las plantillas (Templates) desde WhatsApp Meta Cloud API
 * hacia la base de datos local del PMS.
 *
 * * REGLA DE NEGOCIO: Meta es la fuente de la verdad para textos y URLs. Sin embargo,
 * se preservan llaves internas de integración (como resolver_key) para mantener el funcionamiento
 * del sistema de variables local sin que Meta lo destruya.
 * * OPTIMIZACIÓN GREENFIELD: Ahora sincroniza componentes HEADER y FOOTER para descargar peso
 * del BODY y evitar romper el límite de 1024 caracteres de Meta.
 *
 * Lo que devuelve Meta se lee UNA vez, con {@see PlantillaMeta}; lo que se escribe es nuestro
 * JSON (`BloqueDeCanal` de `MessageTemplate`). Que el cambio al DTO no movió ni un carácter de lo
 * guardado lo comprueba `tools/pruebas/probar-dto-plantillas.php`. Ver `docs/Mensajeria.md` §18.
 */
final readonly class WhatsappMetaTemplateSyncService
{
    public function __construct(
        private EntityManagerInterface $em,
        private WhatsappMetaClient $metaClient,
        private LoggerInterface $logger
    ) {}


    /**
     * Ejecuta la sincronización de plantillas utilizando el cliente de Exchange.
     *
     * @return array<string, int> Resumen de la operación con contadores.
     * @throws RuntimeException Si no hay configuración o endpoint activo.
     */
    public function sync(): array
    {
        $config = $this->em->getRepository(MetaConfig::class)->findOneBy(['activo' => true]);

        if (!$config) {
            throw new RuntimeException('No hay ninguna configuración activa de Meta WhatsApp en el sistema.');
        }

        // Buscamos el endpoint configurado en BD para leer plantillas
        $endpoint = $this->em->getRepository(ExchangeEndpoint::class)->findOneBy([
            'accion' => 'FETCH_META_TEMPLATES'
        ]);

        if (!$endpoint) {
            throw new RuntimeException('No se encontró el endpoint con acción FETCH_META_TEMPLATES asociado a la configuración de Meta.');
        }

        $allowedLanguages = $this->getAllowedLanguages();

        if (empty($allowedLanguages)) {
            $this->logger->warning('Sincronización de Meta abortada: No hay idiomas activos (prioridad > 0) en MaestroIdioma.');
            return ['created' => 0, 'updated' => 0];
        }

        $createdCount = 0;
        $updatedCount = 0;

        /** @var array<string, MessageTemplate> $templateCache */
        $templateCache = [];

        try {
            // El cliente ya maneja la URL dinámica, los tokens y lanza excepciones si hay error HTTP
            $response = $this->metaClient->fetchTemplates($config, $endpoint);

            foreach (PlantillaMeta::listaDesdeRespuesta($response) as $plantilla) {
                $status = strtoupper($plantilla->estado ?? '');

                // Ya no hay lista de nombres a ignorar: el sincronizador no adopta nada, así que
                // una plantilla huérfana en Meta no puede fabricar una fila aquí. Ver
                // `processTemplateRecord()`.
                if (in_array($status, ['APPROVED', 'PENDING', 'REJECTED'], true)) {
                    $isNew = $this->processTemplateRecord($plantilla, $templateCache, $allowedLanguages);

                    if ($isNew === true) {
                        $createdCount++;
                    } elseif ($isNew === false) {
                        $updatedCount++;
                    }
                }
            }

            $this->em->flush();

        } catch (Throwable $e) {
            $this->logger->error('Error fatal sincronizando plantillas de Meta: ' . $e->getMessage());
            throw new RuntimeException('Falló la sincronización de plantillas de Meta. Revisa los logs.', 0, $e);
        }

        return [
            'created' => $createdCount,
            'updated' => $updatedCount
        ];
    }

    /**
     * Procesa y persiste una plantilla individual inyectándola en el JSON estructurado `whatsappMetaTmpl`.
     *
     * @param PlantillaMeta $plantilla Un registro (un idioma) tal como lo devuelve Meta.
     * @param array<string, MessageTemplate> $templateCache
     * @param list<string> $allowedLanguages
     */
    private function processTemplateRecord(PlantillaMeta $plantilla, array &$templateCache, array $allowedLanguages): ?bool
    {
        $metaName = $plantilla->nombre ?? '';
        $rawLanguage = $plantilla->idioma ?? '';
        // El estado se guarda TAL CUAL llega; el filtro de `sync()` lo compara en mayúsculas.
        $status = $plantilla->estado ?? 'UNKNOWN';

        if ($metaName === '' || $rawLanguage === '') {
            return null;
        }

        $languageParts = explode('_', $rawLanguage);
        $language = strtolower($languageParts[0]);

        if (!in_array($language, $allowedLanguages, true)) {
            return null;
        }

        $isNew = false;
        $targetTemplate = null;

        if (isset($templateCache[$metaName])) {
            $targetTemplate = $templateCache[$metaName];
        } else {
            $repo = $this->em->getRepository(MessageTemplate::class);
            $allTemplates = $repo->findAll();

            foreach ($allTemplates as $tpl) {
                if ($tpl->getWhatsappMetaName() === $metaName) {
                    $targetTemplate = $tpl;
                    break;
                }
            }

            if (!$targetTemplate) {
                // 🔒 NO SE CREA NADA. Meta trae un nombre que ninguna plantilla local reclama:
                // se avisa y se pasa de largo.
                //
                // ── Por qué se quitó la adopción automática (13/09/2026) ────────────────
                // Creaba una fila `<NOMBRE>_META`, y en toda su vida produjo DOS:
                //
                // - `WELCOME_BOOKING_META` (05/04/2026): activa y seleccionable en el chat, con un
                //   nombre casi idéntico al bueno y tres botones rotos — uno lanzaba una excepción
                //   que tumbaba el envío entero. Cero envíos legítimos.
                // - `BIENVENIDA_V1_META` (13/09/2026): el mismo caso, dos generaciones después.
                //
                // Las dos nacieron del MISMO gesto normal: lanzar una versión nueva. Como no se
                // edita una plantilla aprobada —se sube una `_vN` y se apunta a ella—, la anterior
                // se queda viva en Meta y sin dueño, y esto le fabricaba un gemelo esa noche.
                //
                // La defensa era una lista de nombres a ignorar, y obligaba a **editar código en
                // cada despliegue de plantilla nueva**. Eso no es una defensa, es una cuota.
                //
                // ⚠️ Lo que se pierde: adoptar sola una plantilla creada a mano en la consola de
                // Meta. Ese caso es raro, y sigue resuelto sin magia — se ve en «Ver plantillas en
                // Meta» como «sin dueño aquí» y se reclama poniéndole su «Nombre en Meta» a la
                // plantilla local que le corresponda. Un acto deliberado en vez de una fila que
                // aparece sola.
                $this->logger->warning(sprintf(
                    'Meta trae la plantilla «%s» y ninguna local la reconoce: NO se crea nada. Si '
                    . 'debería usarse, ponle ese «Nombre en Meta» a la plantilla local que toque; '
                    . 'si es una generación vieja, bórrala en la consola de Meta.',
                    $metaName
                ));

                return null;
            }

            $templateCache[$metaName] = $targetTemplate;
        }

        $metaTmpl = $targetTemplate->getWhatsappMetaTmpl() ?? [];

        $metaTmpl['is_active'] = true;
        // MARCADO CRÍTICO: Todo lo que viene de la API es oficial de Meta.
        $metaTmpl['is_official_meta'] = true;
        $metaTmpl['meta_template_name'] = $metaName;
        $metaTmpl['category'] = $plantilla->categoria ?? ($metaTmpl['category'] ?? 'UTILITY');

        // 4. Procesamiento del BODY. Del componente, el PRIMERO de su tipo (ver
        // `PlantillaMeta::componente()`); sin él, el texto es vacío y se guarda vacío. El `??`
        // cubre también que no haya componente: leer una propiedad de `null` dentro de `??` no
        // avisa, igual que un `isset()`.
        $bodyText = $plantilla->componente('BODY')->texto ?? '';
        $bodyArray = $metaTmpl['body'] ?? [];
        $foundLangBody = false;

        foreach ($bodyArray as &$b) {
            if (($b['language'] ?? '') === $language) {
                $b['status'] = $status;
                $b['content'] = $bodyText;
                $foundLangBody = true;
                break;
            }
        }
        unset($b);

        if (!$foundLangBody) {
            $bodyArray[] = [
                'language' => $language,
                'status'   => $status,
                'content'  => $bodyText
            ];
        }
        $metaTmpl['body'] = $bodyArray;

        // 5. Procesamiento de BOTONES (buttons_map) - Preservamos resolver_key.
        // Meta agrupa todos los botones dentro de un único componente `BUTTONS`; su posición en
        // esa lista es el `index` con el que se emparejan con los nuestros.
        $metaButtons = $plantilla->componente('BUTTONS')->botones ?? [];
        $buttonsMap = $metaTmpl['buttons_map'] ?? [];

        foreach ($metaButtons as $index => $btn) {
            $foundBtn = false;

            // Buscamos si el botón con este índice ya existe en nuestro JSON
            foreach ($buttonsMap as &$bMap) {
                if (($bMap['index'] ?? -1) === $index) {

                    // Actualizamos la traducción del label del botón
                    $btnTextArray = $bMap['button_text'] ?? [];
                    $foundText = false;
                    foreach ($btnTextArray as &$txt) {
                        if (($txt['language'] ?? '') === $language) {
                            $txt['content'] = $btn->texto ?? '';
                            $foundText = true;
                            break;
                        }
                    }
                    unset($txt);

                    if (!$foundText) {
                        $btnTextArray[] = ['language' => $language, 'content' => $btn->texto ?? ''];
                    }
                    $bMap['button_text'] = $btnTextArray;

                    if ($btn->url !== null) {
                        $bMap['content'] = $btn->url;
                    }

                    $bMap['type'] = strtolower($btn->tipo ?? 'url');

                    // IMPORTANTE: NO tocamos la llave 'resolver_key' aquí para preservarla

                    $foundBtn = true;
                    break;
                }
            }
            unset($bMap);

            // Si el botón no existía, lo creamos
            if (!$foundBtn) {
                $buttonsMap[] = [
                    'index'        => $index,
                    'type'         => strtolower($btn->tipo ?? 'url'),
                    'content'      => $btn->url ?? '',
                    'resolver_key' => null, // Lo inicializamos en null para que se llene vía EasyAdmin
                    'button_text'  => [
                        ['language' => $language, 'content' => $btn->texto ?? '']
                    ]
                ];
            }
        }
        $metaTmpl['buttons_map'] = $buttonsMap;

        // 6. Procesamiento del FOOTER. Meta lo maneja como componente propio, con tope de 60
        // caracteres: sincronizarlo descarga peso del body, que tiene el suyo de 1024.
        $footerText = $plantilla->componente('FOOTER')->texto ?? '';
        $footerArray = $metaTmpl['footer'] ?? [];
        $foundLangFooter = false;

        foreach ($footerArray as &$f) {
            if (($f['language'] ?? '') === $language) {
                $f['content'] = $footerText;
                $foundLangFooter = true;
                break;
            }
        }
        unset($f);

        // Si no existía y hay texto válido en Meta, lo agregamos
        if (!$foundLangFooter && $footerText !== '') {
            $footerArray[] = ['language' => $language, 'content' => $footerText];
        }
        $metaTmpl['footer'] = $footerArray;

        // 7. Procesamiento del HEADER. Puede ser TEXT, IMAGE, VIDEO o DOCUMENT; si es TEXT lleva
        // hasta 60 caracteres y puede incluir marcadores («Hola {{guest_name}}»).
        $header = $plantilla->componente('HEADER');
        if ($header !== null) {
            $headerData = [
                'format'  => strtoupper($header->formato ?? 'TEXT'),
                'content' => $header->texto ?? '',
            ];
            $headerArray = $metaTmpl['header'] ?? [];
            $foundLangHeader = false;

            foreach ($headerArray as &$h) {
                if (($h['language'] ?? '') === $language) {
                    $h['format'] = $headerData['format'];
                    $h['content'] = $headerData['content'];
                    $foundLangHeader = true;
                    break;
                }
            }
            unset($h);

            // Si no existía, agregamos el formato y el texto/variable del encabezado
            if (!$foundLangHeader) {
                $headerArray[] = [
                    'language' => $language,
                    'format'   => $headerData['format'],
                    'content'  => $headerData['content']
                ];
            }
            $metaTmpl['header'] = $headerArray;
        }

        $targetTemplate->setWhatsappMetaTmpl($metaTmpl);

        return $isNew;
    }

    /**
     * @return list<string>
     */
    private function getAllowedLanguages(): array
    {
        /** @var list<MaestroIdioma> $idiomas */
        $idiomas = $this->em->getRepository(MaestroIdioma::class)
            ->createQueryBuilder('m')
            ->where('m.prioridad > 0')
            ->getQuery()
            ->getResult();

        $allowed = [];
        /** @var MaestroIdioma $idioma */
        foreach ($idiomas as $idioma) {
            $id = $idioma->getId();
            if ($id !== null) {
                $allowed[] = strtolower($id);
            }
        }

        return $allowed;
    }
}
