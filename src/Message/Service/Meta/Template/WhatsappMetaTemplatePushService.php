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
 * Servicio encargado de EMPUJAR (Push) plantillas locales hacia WhatsApp Meta Cloud API.
 * * Esta versión está optimizada para la CREACIÓN de nuevas plantillas, forzando el formato
 * de parámetros NOMBRADOS para evitar errores de validación en la interfaz de Meta.
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
     * Envía la estructura completa de la plantilla a Meta para su revisión.
     * Itera sobre cada idioma definido en el JSON local.
     *
     * @param MessageTemplate $template Entidad que contiene el JSON de Meta.
     * @return array<string, array<string, mixed>> Resultados por idioma.
     */
    public function pushTemplateToMeta(MessageTemplate $template): array
    {
        $config = $this->em->getRepository(MetaConfig::class)->findOneBy(['activo' => true]);
        if (!$config) {
            throw new RuntimeException('No se encontró una configuración de Meta activa.');
        }

        $endpoint = $this->em->getRepository(ExchangeEndpoint::class)->findOneBy([
            'accion' => 'PUSH_META_TEMPLATE'
        ]);

        if (!$endpoint) {
            throw new RuntimeException('Endpoint PUSH_META_TEMPLATE no configurado en Exchange.');
        }

        $metaTmpl = $template->getWhatsappMetaTmpl();
        if (empty($metaTmpl)) {
            throw new RuntimeException('La plantilla no tiene datos en el campo whatsappMetaTmpl.');
        }

        // Datos dummy del Resolver para los "examples" obligatorios de Meta
        $previewData = $this->previewResolver->getPreviewMessageVariables();

        // Extraemos los idiomas únicos definidos en el bloque body
        $localLanguages = [];
        foreach ($metaTmpl['body'] ?? [] as $b) {
            if (!empty($b['language'])) {
                $localLanguages[] = $b['language'];
            }
        }
        $localLanguages = array_unique($localLanguages);

        $results = [];

        foreach ($localLanguages as $localLang) {
            $metaLangCode = $this->mapLanguageToMeta($localLang);

            try {
                // Construimos el payload con parameter_format => NAMED
                $payload = $this->buildSingleLanguagePayload($metaTmpl, $localLang, $metaLangCode, $previewData);

                $response = $this->metaClient->pushTemplateDefinition($config, $endpoint, $payload);

                $results[$localLang] = [
                    'status' => 'success',
                    'meta_id' => $response['id'] ?? null,
                    'meta_lang' => $metaLangCode
                ];

                $this->logger->info(sprintf('Push exitoso: %s (%s)', $metaTmpl['meta_template_name'], $metaLangCode));

            } catch (Throwable $e) {
                $results[$localLang] = [
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
                $this->logger->error(sprintf('Fallo en Push %s: %s', $metaLangCode, $e->getMessage()));
            }
        }

        return $results;
    }

    /**
     * Construye el JSON individual por idioma con la estructura exacta que Meta exige.
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
            'parameter_format' => 'NAMED' // Indica que usamos {{variable_name}}
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