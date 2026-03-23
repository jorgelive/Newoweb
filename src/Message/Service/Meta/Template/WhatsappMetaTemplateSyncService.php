<?php

declare(strict_types=1);

namespace App\Message\Service\Meta\Template;

use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Entity\MetaConfig;
use App\Exchange\Service\Client\WhatsappMetaClient;
use App\Message\Entity\MessageTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Servicio encargado de sincronizar las plantillas (Templates) desde WhatsApp Meta Cloud API
 * hacia la base de datos local del PMS.
 *
 * * OPTIMIZACIÓN GREENFIELD: Compatible con la nueva estructura JSON centralizada (`whatsappMetaTmpl`).
 * Lee el cuerpo de Meta y los botones, guardando el estado de aprobación nativo directamente
 * en el nodo `body` y agrupando las URLs dinámicas en `buttons_map`.
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
     * @throws \RuntimeException Si no hay configuración o endpoint activo.
     */
    public function sync(): array
    {
        $config = $this->em->getRepository(MetaConfig::class)->findOneBy(['activo' => true]);

        if (!$config) {
            throw new \RuntimeException('No hay ninguna configuración activa de Meta WhatsApp en el sistema.');
        }

        // Buscamos el endpoint configurado en BD para leer plantillas
        $endpoint = $this->em->getRepository(ExchangeEndpoint::class)->findOneBy([
            'config' => $config,
            'accion' => 'FETCH_META_TEMPLATES' // Asegúrate de tener este endpoint configurado en BD
        ]);

        if (!$endpoint) {
            throw new \RuntimeException('No se encontró el endpoint con acción FETCH_META_TEMPLATES asociado a la configuración de Meta.');
        }

        $createdCount = 0;
        $updatedCount = 0;

        try {
            // El cliente ya maneja la URL dinámica, los tokens y lanza excepciones si hay error HTTP
            $response = $this->metaClient->fetchTemplates($config, $endpoint);
            $templates = $response['data'] ?? [];

            foreach ($templates as $templateData) {
                // Solo sincronizamos plantillas válidas, ignoramos borradores incompletos
                $status = strtoupper($templateData['status'] ?? '');
                if (in_array($status, ['APPROVED', 'PENDING', 'REJECTED'])) {
                    $isNew = $this->processTemplateRecord($templateData);
                    if ($isNew) {
                        $createdCount++;
                    } else {
                        $updatedCount++;
                    }
                }
            }

            $this->em->flush();

        } catch (\Throwable $e) {
            $this->logger->error('Error fatal sincronizando plantillas de Meta: ' . $e->getMessage());
            throw new \RuntimeException('Falló la sincronización de plantillas de Meta. Revisa los logs.', 0, $e);
        }

        return [
            'created' => $createdCount,
            'updated' => $updatedCount
        ];
    }

    /**
     * Procesa y persiste una plantilla individual inyectándola en el JSON estructurado `whatsappMetaTmpl`.
     */
    private function processTemplateRecord(array $data): bool
    {
        $metaName = $data['name'] ?? null;
        $language = $data['language'] ?? null;
        $status = $data['status'] ?? 'UNKNOWN';

        if (!$metaName || !$language) {
            return false;
        }

        $repo = $this->em->getRepository(MessageTemplate::class);

        // 1. Buscar si la plantilla base ya existe por su nombre oficial en Meta
        $allTemplates = $repo->findAll();
        $targetTemplate = null;

        foreach ($allTemplates as $tpl) {
            if ($tpl->getWhatsappMetaName() === $metaName) {
                $targetTemplate = $tpl;
                break;
            }
        }

        $isNew = false;

        // 2. Si no existe la plantilla base, la creamos desde cero
        if (!$targetTemplate) {
            $targetTemplate = new MessageTemplate();
            $targetTemplate->setName(ucwords(str_replace('_', ' ', $metaName)));
            $targetTemplate->setCode(sprintf('%s_META', strtoupper($metaName)));

            $this->em->persist($targetTemplate);
            $isNew = true;
        }

        // Obtenemos la configuración JSON actual para actualizarla sin perder otros idiomas
        $metaTmpl = $targetTemplate->getWhatsappMetaTmpl() ?? [];
        $metaTmpl['is_active'] = true;
        $metaTmpl['meta_template_name'] = $metaName;
        $metaTmpl['category'] = $data['category'] ?? $metaTmpl['category'] ?? 'UTILITY';

        // 3. Procesamiento del BODY (Sincroniza Texto y Estado)
        $bodyText = $this->extractBodyText($data['components'] ?? []);
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
        unset($b); // CRÍTICO: Destruimos la referencia para evitar corrupción de memoria

        if (!$foundLangBody) {
            $bodyArray[] = [
                'language' => $language,
                'status'   => $status,
                'content'  => $bodyText
            ];
        }
        $metaTmpl['body'] = $bodyArray;

        // 4. Procesamiento de BOTONES (buttons_map)
        $metaButtons = $this->extractButtons($data['components'] ?? []);
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
                            $txt['content'] = $btn['text'] ?? '';
                            $foundText = true;
                            break;
                        }
                    }
                    unset($txt); // CRÍTICO: Limpiamos la referencia interna

                    if (!$foundText) {
                        $btnTextArray[] = ['language' => $language, 'content' => $btn['text'] ?? ''];
                    }
                    $bMap['button_text'] = $btnTextArray;

                    // Preservamos la variable personalizada si existe
                    if (empty($bMap['content']) && isset($btn['url'])) {
                        $bMap['content'] = $btn['url'];
                    }

                    $foundBtn = true;
                    break;
                }
            }
            unset($bMap); // CRÍTICO: Limpiamos la referencia del nivel superior

            // Si el botón no existía, lo creamos
            if (!$foundBtn) {
                $buttonsMap[] = [
                    'index'       => $index,
                    'type'        => strtolower((string)($btn['type'] ?? 'url')),
                    'content'     => $btn['url'] ?? '',
                    'button_text' => [
                        ['language' => $language, 'content' => $btn['text'] ?? '']
                    ]
                ];
            }
        }
        $metaTmpl['buttons_map'] = $buttonsMap;

        // Guardamos el JSON completo en la entidad
        $targetTemplate->setWhatsappMetaTmpl($metaTmpl);

        return $isNew;
    }

    /**
     * Extrae el texto del componente BODY.
     * Ya no necesitamos contar variables ni extraerlas manualmente gracias a la arquitectura dinámica.
     * Solo queremos el texto en crudo para mostrarlo en el panel y usarlo en el envío.
     *
     * @param array $components Arreglo de componentes (HEADER, BODY, FOOTER, BUTTONS).
     * @return string El texto del cuerpo de la plantilla.
     */
    private function extractBodyText(array $components): string
    {
        foreach ($components as $component) {
            if (strtoupper((string)($component['type'] ?? '')) === 'BODY') {
                return $component['text'] ?? '';
            }
        }
        return '';
    }

    /**
     * Extrae el array de botones físicos configurados en Meta.
     * ¿Por qué existe? Meta agrupa todos los botones dentro de un único componente de tipo 'BUTTONS'.
     * Este método aísla ese sub-array para poder indexarlos correctamente en nuestra base de datos.
     *
     * @param array $components Arreglo de componentes de Meta.
     * @return array<int, array> Lista de botones encontrados en el payload.
     */
    private function extractButtons(array $components): array
    {
        foreach ($components as $component) {
            if (strtoupper((string)($component['type'] ?? '')) === 'BUTTONS') {
                return $component['buttons'] ?? [];
            }
        }
        return [];
    }
}