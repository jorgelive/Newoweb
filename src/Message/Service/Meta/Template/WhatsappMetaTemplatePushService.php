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
    public function pushTemplateToMeta(MessageTemplate $template): array
    {
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
        $results = [];

        foreach ($localLanguages as $localLang) {
            $metaLangCode = $this->mapLanguageToMeta($localLang);
            $templateName = $metaTmpl['meta_template_name'];

            try {
                // Construimos payload minimalista (sin payloads técnicos en botones)
                $payload = $this->buildSingleLanguagePayload($metaTmpl, $localLang, $metaLangCode, $previewData);

                $existingId = $this->findExistingTemplateId($existingTemplates, $templateName, $metaLangCode);

                if ($existingId) {
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
     */
    private function generateNamedExamples(string $text, array $previewVars): array
    {
        preg_match_all('/\{\{([a-zA-Z0-9_]+)\}\}/', $text, $matches);
        $varsInText = $matches[1] ?? [];

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
}