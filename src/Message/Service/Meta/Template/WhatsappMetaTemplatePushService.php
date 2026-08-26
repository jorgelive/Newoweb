<?php

declare(strict_types=1);

namespace App\Message\Service\Meta\Template;

use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Entity\MetaConfig;
use App\Exchange\Service\Client\WhatsappMetaClient;
use App\Message\Entity\MessageTemplate;
use App\Pms\Service\Message\PmsMessageDataResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Servicio encargado de sincronizar (Push/Edit) plantillas locales hacia WhatsApp Meta Cloud API.
 * * * AUTO-DISCOVERY: Detecta si el idioma existe en Meta para decidir si crear o editar.
 * * VALIDACIÓN ESTRICTA: Lanza excepción si un Quick Reply o URL no tiene 'resolver_key'.
 * * REGLA META: En la definición de estructura, los botones Quick Reply no llevan payload técnico.
 */
final readonly class WhatsappMetaTemplatePushService
{
    public function __construct(
        private EntityManagerInterface $em,
        private WhatsappMetaClient $metaClient,
        private PmsMessageDataResolver $previewResolver,
        private LoggerInterface $logger
    ) {}

    /**
     * Sincroniza la estructura de la plantilla con Meta.
     * * @param MessageTemplate $template Entidad con el JSON de Meta.
     * @return array Resumen de operaciones por idioma.
     */
    /**
     * @param list<string> $soloIdiomas Códigos a subir. VACÍO = todos los que tenga la
     *        plantilla.
     *
     *        Existe porque subir un idioma **reabre su revisión en Meta**: reenviar los
     *        siete porque uno fue rechazado devuelve a PENDING seis que ya estaban
     *        aprobadas y funcionando, y hasta que Meta los vuelva a mirar no se pueden
     *        usar fuera de la ventana de 24 h. Es un daño real, no una molestia.
     *
     * @param list<string> $soloIdiomas
     * @return array<string, mixed> Resumen de lo empujado, por idioma.
     */
    public function pushTemplateToMeta(MessageTemplate $template, array $soloIdiomas = [], bool $recrear = false): array
    {
        // 🔥 PROTECCIÓN CONTRA TIMEOUT: Damos 2 minutos de vida al script
        // Meta tarda mucho en procesar múltiples idiomas consecutivamente.
        set_time_limit(120);

        $config = $this->em->getRepository(MetaConfig::class)->findOneBy(['activo' => true]);
        if (!$config) {
            throw new RuntimeException('No se encontró una configuración de Meta activa.');
        }

        $pushEndpoint = $this->em->getRepository(ExchangeEndpoint::class)->findOneBy(['accion' => 'PUSH_META_TEMPLATE']);
        $fetchEndpoint = $this->em->getRepository(ExchangeEndpoint::class)->findOneBy(['accion' => 'FETCH_META_TEMPLATES']);

        // 🔥 CORRECCIÓN: Variable corregida a $pushEndpoint
        if (!$pushEndpoint || !$fetchEndpoint) {
            throw new RuntimeException('Endpoints PUSH_META_TEMPLATE o FETCH_META_TEMPLATES no configurados en Exchange.');
        }

        $metaTmpl = $template->getWhatsappMetaTmpl();
        if (empty($metaTmpl)) {
            throw new RuntimeException('La plantilla no tiene datos en el campo whatsappMetaTmpl.');
        }

        // ⚠️ Sin nombre de Meta no se sube NADA, y se dice aquí en vez de dejar que reviente
        // dentro del bucle.
        //
        // El nombre viaja como `name` en el payload (`buildSingleLanguagePayload()`) y es la
        // clave por la que Meta identifica la plantilla; también es por lo que empareja
        // `WhatsappMetaTemplateSyncService`. Con el campo vacío pasaban las dos cosas a la vez,
        // sin una sola línea de error: la subida mandaba `name: null` y el sincronizador nunca
        // la reconocía, así que la plantilla se quedaba con el `PENDING` que alguien escribió a
        // mano en el panel — un estado que parece de Meta y no lo es. `menu_tours` estuvo así
        // desde marzo: nadie podía aprobarla porque nunca llegó a enviarse.
        if (!is_string($metaTmpl['meta_template_name'] ?? null) || trim($metaTmpl['meta_template_name']) === '') {
            throw new RuntimeException(sprintf(
                'La plantilla «%s» no tiene «Nombre en Meta». Sin ese campo no se puede subir ni '
                . 'reconocer lo que Meta devuelva: rellénalo (suele ser el mismo código, «%s») y vuelve a intentarlo.',
                $template->getName() ?? '?',
                $template->getCode() ?? '?'
            ));
        }

        // Datos dummy del Resolver para los "examples" obligatorios de Meta
        $previewData = $this->previewResolver->getPreviewMessageVariables();

        // AUTO-DISCOVERY: Obtenemos lo que ya existe en Meta para no duplicar
        try {
            $metaResponse = $this->metaClient->fetchTemplates($config, $fetchEndpoint);
            $existingTemplates = $metaResponse['data'] ?? [];
        } catch (Throwable $e) {
            $this->logger->error('Error recuperando plantillas de Meta: ' . $e->getMessage());
            $existingTemplates = [];
        }

        $localLanguages = array_unique(array_map(fn($b) => $b['language'], $metaTmpl['body'] ?? []));

        // Filtro de idiomas. Se aplica aquí, sobre los que la plantilla tiene de verdad,
        // para que pedir uno inexistente no invente una subida vacía.
        if ($soloIdiomas !== []) {
            $localLanguages = array_values(array_intersect($localLanguages, $soloIdiomas));

            if ($localLanguages === []) {
                throw new RuntimeException('Ninguno de los idiomas seleccionados existe en esta plantilla.');
            }
        }
        $results = [];

        foreach ($localLanguages as $localLang) {
            // ⚠️ Los topes de Meta se comprueban AQUÍ, antes de la llamada.
            //
            // Meta contesta «Invalid parameter | El campo Body no puede superar los 1024
            // caracteres» sin decir cuánto mide el tuyo ni cuánto te pasas, y lo repite una vez
            // por idioma: siete líneas idénticas que no dicen nada accionable. Medido en local
            // se sabe de un vistazo si es cuestión de recortar dos frases o de que el contenido
            // no cabe en una plantilla —el menú de tours mide 3701-4228 caracteres, cuatro veces
            // el tope, y eso no se arregla podando: se arregla con un botón al catálogo.
            $exceso = $this->medirExcesos($metaTmpl, $localLang);

            if ($exceso !== null) {
                $results[$localLang] = ['status' => 'error', 'message' => $exceso];

                continue;
            }

            $metaLangCode = $this->mapLanguageToMeta($localLang);
            $templateName = $metaTmpl['meta_template_name'];

            try {
                // Construimos payload minimalista (sin payloads técnicos en botones)
                $payload = $this->buildSingleLanguagePayload($metaTmpl, $localLang, $metaLangCode, $previewData);

                $existingId = $this->findExistingTemplateId($existingTemplates, $templateName, $metaLangCode);

                if ($existingId && $recrear) {
                    // --- MODO RECREAR: borrar y volver a crear ---
                    //
                    // Existe porque Meta **no deja renombrar los marcadores** de una plantilla
                    // aprobada: editar el texto de alrededor sí, convertir `{{guest}}` en
                    // `{{huesped}}` no. Rechaza con «sólo puedes eliminar o añadir plantillas»,
                    // y esto es hacerle caso.
                    //
                    // 🔴 OJO: NO es «borrar y crear» en un paso, aunque lo parezca.
                    //
                    // Meta acepta el DELETE y luego rechaza el POST: «no es posible añadir
                    // contenido nuevo en <idioma> mientras se está eliminando el existente.
                    // Vuelve a intentarlo dentro de 4 weeks». Reserva el par nombre+idioma
                    // durante cuatro semanas, así que el resultado real de marcar esta casilla
                    // es **el idioma se queda sin plantilla hasta que pase el plazo**.
                    //
                    // Medido el 26/08/2026 sobre `aviso_escalado_interno`: los cinco idiomas
                    // se borraron y ninguno se recreó.
                    //
                    // Se deja así, y no se intenta esquivar, porque el plazo es de Meta y no
                    // hay vuelta: lo único que puede hacer el código es contarlo antes (el
                    // aviso del panel) y decir la verdad después (el resumen distingue
                    // RECREATED de DELETED_PENDING).
                    //
                    // Borrar además se lleva el historial y las métricas de esa versión.
                    $this->metaClient->deleteTemplateDefinition($config, $pushEndpoint, $templateName, $existingId);

                    try {
                        $response = $this->metaClient->pushTemplateDefinition($config, $pushEndpoint, $payload);
                        $results[$localLang] = ['status' => 'success', 'action' => 'RECREATED', 'meta_id' => $response['id'] ?? null];
                    } catch (Throwable $e) {
                        // El borrado YA entró. Decirlo con todas las letras importa: quien lo
                        // pulsó tiene que saber que ese idioma se quedó sin plantilla, no que
                        // «falló y todo sigue igual».
                        $results[$localLang] = [
                            'status' => 'error',
                            'action' => 'DELETED_PENDING',
                            'message' => 'BORRADA en Meta, pero NO se pudo recrear: ' . $e->getMessage()
                                . ' — ese idioma se queda sin plantilla hasta que Meta libere el nombre.',
                        ];
                    }
                } elseif ($existingId) {
                    // --- MODO EDICIÓN ---
                    $this->metaClient->editTemplateDefinition($config, $existingId, $payload['components']);
                    $results[$localLang] = ['status' => 'success', 'action' => 'EDITED', 'meta_id' => $existingId];
                } else {
                    // --- MODO CREACIÓN ---
                    $response = $this->metaClient->pushTemplateDefinition($config, $pushEndpoint, $payload);
                    $results[$localLang] = ['status' => 'success', 'action' => 'CREATED', 'meta_id' => $response['id'] ?? null];
                }

                $this->logger->info("Sincronización exitosa: $templateName ($metaLangCode)");

            } catch (Throwable $e) {
                $results[$localLang] = ['status' => 'error', 'message' => $e->getMessage()];

                // Si falta resolver_key, abortamos todo el proceso
                if ($e instanceof RuntimeException && str_contains($e->getMessage(), 'resolver_key')) {
                    throw $e;
                }
            }
        }

        return $results;
    }

    /**
     * Busca el ID de una plantilla existente en el pool de Meta.
     *
     * @param list<array<string, mixed>> $metaTemplates Lo que devuelve el listado de plantillas de Meta.
     */
    private function findExistingTemplateId(array $metaTemplates, string $name, string $langCode): ?string
    {
        foreach ($metaTemplates as $tpl) {
            if (($tpl['name'] ?? '') === $name && ($tpl['language'] ?? '') === $langCode) {
                return (string)($tpl['id'] ?? '');
            }
        }
        return null;
    }

    /**
     * Construye el payload JSON para un idioma específico.
     * @throws RuntimeException Si un Quick Reply carece de resolver_key.
     *
     * @param array<string, mixed> $metaTmpl
     * @param array<string, mixed> $previewData
     * @return array<string, mixed> El cuerpo que espera la API de Meta.
     */
    private function buildSingleLanguagePayload(array $metaTmpl, string $localLang, string $metaLangCode, array $previewData): array
    {
        $components = [];

        // --- HEADER ---
        $headerText = $this->extractTextByLanguage($metaTmpl['header'] ?? [], $localLang);
        if ($headerText !== '') {
            $headerComp = [
                'type'   => 'HEADER',
                'format' => 'TEXT',
                'text'   => $headerText
            ];
            $examples = $this->generateNamedExamples($headerText, $previewData);
            if (!empty($examples)) {
                $headerComp['example'] = ['header_text_named_params' => $examples];
            }
            $components[] = $headerComp;
        }

        // --- BODY ---
        $bodyText = $this->extractTextByLanguage($metaTmpl['body'] ?? [], $localLang);
        if ($bodyText !== '') {
            $bodyComp = [
                'type' => 'BODY',
                'text' => $bodyText
            ];
            $examples = $this->generateNamedExamples($bodyText, $previewData);
            if (!empty($examples)) {
                // Obligatorio para variables con nombre (NAMED)
                $bodyComp['example'] = ['body_text_named_params' => $examples];
            }
            $components[] = $bodyComp;
        }

        // --- FOOTER ---
        $footerText = $this->extractTextByLanguage($metaTmpl['footer'] ?? [], $localLang);
        if ($footerText !== '') {
            $components[] = [
                'type' => 'FOOTER',
                'text' => $footerText
            ];
        }

        // --- BUTTONS ---
        if (!empty($metaTmpl['buttons_map'])) {
            $buttons = [];
            foreach ($metaTmpl['buttons_map'] as $btnMap) {
                $btnText = $this->extractTextByLanguage($btnMap['button_text'] ?? [], $localLang);
                if ($btnText === '') continue;

                // VALIDACIÓN TRANSVERSAL: Ambos tipos de botones requieren resolver_key
                if (empty($btnMap['resolver_key'])) {
                    throw new RuntimeException(sprintf(
                        'Error de validación: El botón "%s" (tipo: %s) en el idioma [%s] NO tiene definida una "resolver_key".',
                        $btnText,
                        $btnMap['type'] ?? 'unknown',
                        $localLang
                    ));
                }

                if ($btnMap['type'] === 'url') {
                    $url = (string)($btnMap['content'] ?? '');
                    $btnComp = [
                        'type' => 'URL',
                        'text' => $btnText,
                        'url'  => $url
                    ];

                    // Las URLs en botones siguen usando formato posicional {{1}} en Meta
                    if (str_contains($url, '{{1}}')) {
                        $btnComp['example'] = [str_replace('{{1}}', 'H6Q49C', $url)];
                    }
                    $buttons[] = $btnComp;

                } elseif ($btnMap['type'] === 'quick_reply') {
                    // Para definición estructural en Meta, no enviamos el payload técnico
                    $buttons[] = [
                        'type' => 'QUICK_REPLY',
                        'text' => $btnText
                    ];
                }
            }

            if (!empty($buttons)) {
                $components[] = [
                    'type'    => 'BUTTONS',
                    'buttons' => $buttons
                ];
            }
        }

        return [
            'name'             => $metaTmpl['meta_template_name'],
            'language'         => $metaLangCode,
            'category'         => $metaTmpl['category'] ?? 'MARKETING',
            'components'       => $components,
            'parameter_format' => 'NAMED'
        ];
    }

    /**
     * Mapea el código ISO local al formato regional de Meta (especialmente pt -> pt_BR).
     */
    private function mapLanguageToMeta(string $localLang): string
    {
        $langMap = [
            'pt' => 'pt_BR',
            'es' => 'es',
            'en' => 'en',
            'it' => 'it',
            'fr' => 'fr',
            'de' => 'de',
            'nl' => 'nl'
        ];

        return $langMap[strtolower($localLang)] ?? strtolower($localLang);
    }

    /**
     * Extrae el contenido traducido para un idioma específico desde el array local.
     *
     * @param list<array<string, mixed>> $componentList
     */
    private function extractTextByLanguage(array $componentList, string $targetLang): string
    {
        foreach ($componentList as $item) {
            if (($item['language'] ?? '') === $targetLang) {
                return (string)($item['content'] ?? '');
            }
        }
        return '';
    }

    /**
     * Detecta variables {{name}} y genera el array de ejemplos para la validación de Meta.
     *
     * @param array<string, mixed> $previewVars
     * @return array{list<string>, list<string>} Nombres de variable y sus ejemplos, en paralelo.
     */
    private function generateNamedExamples(string $text, array $previewVars): array
    {
        preg_match_all('/\{\{([a-zA-Z0-9_]+)\}\}/', $text, $matches);
        // `preg_match_all` deja siempre el grupo, vacío si no hubo coincidencias.
        $varsInText = $matches[1];

        if (empty($varsInText)) {
            return [];
        }

        $namedExamples = [];
        foreach ($varsInText as $varName) {
            $namedExamples[] = [
                'param_name' => $varName,
                'example'    => (string)($previewVars[$varName] ?? 'Dato_Ejemplo')
            ];
        }

        return $namedExamples;
    }

    /**
     * Los topes de Meta por componente, en caracteres.
     *
     * No están en ninguna respuesta de la API: se descubren cuando te los saltas. Se dejan aquí
     * escritos para que el aviso llegue antes de gastar una llamada, y con el número medido.
     *
     * @see https://developers.facebook.com/docs/whatsapp/business-management-api/message-templates
     */
    private const int TOPE_BODY = 1024;

    private const int TOPE_HEADER = 60;

    private const int TOPE_FOOTER = 60;

    /**
     * ¿Se pasa algún componente de este idioma? Devuelve el aviso, o `null` si todo cabe.
     *
     * Se mide con `mb_strlen`: Meta cuenta caracteres, no bytes, y estos textos van llenos de
     * emojis y tildes. Contar bytes daría un falso positivo en cuanto haya un 🌄.
     *
     * @param array<string, mixed> $metaTmpl
     */
    private function medirExcesos(array $metaTmpl, string $idioma): ?string
    {
        $componentes = [
            'Body' => [self::TOPE_BODY, $this->contenidoDe($metaTmpl['body'] ?? [], $idioma)],
            'Header' => [self::TOPE_HEADER, $this->contenidoDe($metaTmpl['header'] ?? [], $idioma)],
            'Footer' => [self::TOPE_FOOTER, $this->contenidoDe($metaTmpl['footer'] ?? [], $idioma)],
        ];

        $problemas = [];

        foreach ($componentes as $nombre => [$tope, $texto]) {
            $largo = mb_strlen($texto);

            if ($largo > $tope) {
                $problemas[] = sprintf('%s mide %d caracteres y el tope de Meta son %d: sobran %d.', $nombre, $largo, $tope, $largo - $tope);
            }
        }

        return $problemas === [] ? null : implode(' ', $problemas);
    }

    /**
     * @param array<int, array{language?: string, content?: string}> $bloques
     */
    private function contenidoDe(array $bloques, string $idioma): string
    {
        foreach ($bloques as $bloque) {
            if (($bloque['language'] ?? '') === $idioma) {
                return (string) ($bloque['content'] ?? '');
            }
        }

        return '';
    }
}
