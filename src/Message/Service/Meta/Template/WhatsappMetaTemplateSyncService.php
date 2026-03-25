<?php

declare(strict_types=1);

namespace App\Message\Service\Meta\Template;

use App\Entity\Maestro\MaestroIdioma;
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
 * * REGLA DE NEGOCIO: Meta es la fuente de la verdad para textos y URLs. Sin embargo,
 * se preservan llaves internas de integración (como resolver_key) para mantener el funcionamiento
 * del sistema de variables local sin que Meta lo destruya.
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
            'accion' => 'FETCH_META_TEMPLATES'
        ]);

        if (!$endpoint) {
            throw new \RuntimeException('No se encontró el endpoint con acción FETCH_META_TEMPLATES asociado a la configuración de Meta.');
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
            $templates = $response['data'] ?? [];

            foreach ($templates as $templateData) {
                $status = strtoupper((string)($templateData['status'] ?? ''));

                if (in_array($status, ['APPROVED', 'PENDING', 'REJECTED']) && ($templateData['name'] ?? '') !== 'hello_world') {
                    $isNew = $this->processTemplateRecord($templateData, $templateCache, $allowedLanguages);

                    if ($isNew === true) {
                        $createdCount++;
                    } elseif ($isNew === false) {
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

    private function processTemplateRecord(array $data, array &$templateCache, array $allowedLanguages): ?bool
    {
        $metaName = (string)($data['name'] ?? '');
        $rawLanguage = (string)($data['language'] ?? '');
        $status = (string)($data['status'] ?? 'UNKNOWN');

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
                $targetTemplate = new MessageTemplate();
                $targetTemplate->setName(ucwords(str_replace('_', ' ', $metaName)));

                $generatedCode = sprintf('%s_meta', ($metaName));
                if (strlen($generatedCode) > 50) {
                    $generatedCode = substr($generatedCode, 0, 50);
                }
                $targetTemplate->setCode($generatedCode);

                $this->em->persist($targetTemplate);
                $isNew = true;
            }

            $templateCache[$metaName] = $targetTemplate;
        }

        $metaTmpl = $targetTemplate->getWhatsappMetaTmpl() ?? [];

        $metaTmpl['is_active'] = true;
        // MARCADO CRÍTICO: Todo lo que viene de la API es oficial de Meta.
        $metaTmpl['is_official_meta'] = true;
        $metaTmpl['meta_template_name'] = $metaName;
        $metaTmpl['category'] = $data['category'] ?? ($metaTmpl['category'] ?? 'UTILITY');

        // 4. Procesamiento del BODY
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
        unset($b);

        if (!$foundLangBody) {
            $bodyArray[] = [
                'language' => $language,
                'status'   => $status,
                'content'  => $bodyText
            ];
        }
        $metaTmpl['body'] = $bodyArray;

        // 5. Procesamiento de BOTONES (buttons_map) - Preservamos resolver_key
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
                    unset($txt);

                    if (!$foundText) {
                        $btnTextArray[] = ['language' => $language, 'content' => $btn['text'] ?? ''];
                    }
                    $bMap['button_text'] = $btnTextArray;

                    if (isset($btn['url'])) {
                        $bMap['content'] = $btn['url'];
                    }

                    $bMap['type'] = strtolower((string)($btn['type'] ?? 'url'));

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
                    'type'         => strtolower((string)($btn['type'] ?? 'url')),
                    'content'      => $btn['url'] ?? '',
                    'resolver_key' => null, // Lo inicializamos en null para que se llene vía EasyAdmin
                    'button_text'  => [
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

    private function getAllowedLanguages(): array
    {
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

    private function extractBodyText(array $components): string
    {
        foreach ($components as $component) {
            if (strtoupper((string)($component['type'] ?? '')) === 'BODY') {
                return (string)($component['text'] ?? '');
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