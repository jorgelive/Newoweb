<?php

declare(strict_types=1);

namespace App\Message\Service\Meta\Template;

use App\Exchange\Entity\MetaConfig;
use App\Message\Entity\MessageTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Servicio encargado de sincronizar las plantillas (Templates) desde WhatsApp Meta Cloud API
 * hacia la base de datos local del PMS.
 *
 * Este servicio conecta con el endpoint del WhatsApp Business Account (WABA),
 * descarga las plantillas aprobadas/rechazadas, extrae sus componentes y detecta
 * automáticamente las variables requeridas (ej: {{1}}, {{2}}) para inicializar el mapeo.
 */
final readonly class WhatsappMetaTemplateSyncService
{
    public function __construct(
        private EntityManagerInterface $em,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger
    ) {}

    /**
     * Ejecuta la sincronización completa de plantillas desde Meta.
     * Maneja la paginación nativa de la API Graph de Facebook.
     *
     * @example
     * $syncService->sync(); // Retorna: ['created' => 5, 'updated' => 12]
     *
     * @return array<string, int> Resumen de la operación con contadores.
     * @throws \RuntimeException Si no hay configuración activa o faltan credenciales críticas.
     */
    public function sync(): array
    {
        $config = $this->em->getRepository(MetaConfig::class)->findOneBy(['activo' => true]);

        if (!$config) {
            throw new \RuntimeException('No hay ninguna configuración activa de Meta WhatsApp en el sistema.');
        }

        // Para leer plantillas necesitamos el WABA ID (WhatsApp Business Account ID), no el Phone ID.
        $wabaId = $config->getCredential('wabaId');
        $accessToken = $config->getCredential('accessToken');

        if (!$wabaId || !$accessToken) {
            throw new \RuntimeException('La configuración de Meta no tiene el WABA ID o el Access Token configurados.');
        }

        // Endpoint base de Graph API para leer plantillas
        $baseUrl = rtrim($config->getBaseUrl(), '/');
        $endpoint = sprintf('%s/%s/message_templates', $baseUrl, $wabaId);

        $createdCount = 0;
        $updatedCount = 0;
        $nextPageUrl = $endpoint;

        // Bucle de paginación (Meta devuelve las plantillas por lotes)
        while ($nextPageUrl !== null) {
            try {
                $response = $this->httpClient->request('GET', $nextPageUrl, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                        'Accept'        => 'application/json',
                    ],
                    // Traemos un límite razonable por página
                    'query' => [
                        'limit' => 50
                    ]
                ]);

                $statusCode = $response->getStatusCode();
                if ($statusCode !== 200) {
                    $this->logger->error('Error HTTP al sincronizar plantillas de Meta.', [
                        'status' => $statusCode,
                        'response' => $response->getContent(false)
                    ]);
                    throw new \RuntimeException(sprintf('Error de la API de Meta al sincronizar plantillas. HTTP %s', $statusCode));
                }

                $data = $response->toArray();
                $templates = $data['data'] ?? [];

                foreach ($templates as $templateData) {
                    $isNew = $this->processTemplateRecord($templateData);
                    if ($isNew) {
                        $createdCount++;
                    } else {
                        $updatedCount++;
                    }
                }

                // Manejo del cursor de paginación de Facebook Graph API
                $nextPageUrl = $data['paging']['next'] ?? null;

            } catch (TransportExceptionInterface $e) {
                $this->logger->error('Error de red conectando con Meta API: ' . $e->getMessage());
                throw new \RuntimeException('Error de red conectando con Meta API.', 0, $e);
            }
        }

        $this->em->flush();

        return [
            'created' => $createdCount,
            'updated' => $updatedCount
        ];
    }

    /**
     * Procesa y persiste una plantilla individual devuelta por la API.
     *
     * @param array $data El arreglo asociativo con los datos de la plantilla de Meta.
     * @return bool True si la plantilla fue creada, False si fue actualizada.
     */
    private function processTemplateRecord(array $data): bool
    {
        $metaName = $data['name'] ?? null;
        $language = $data['language'] ?? null;
        $status = $data['status'] ?? 'UNKNOWN'; // APPROVED, PENDING, REJECTED

        if (!$metaName || !$language) {
            return false;
        }

        $repo = $this->em->getRepository(MessageTemplate::class);

        // La combinación de Nombre + Idioma es única en WhatsApp Meta
        $template = $repo->findOneBy([
            'whatsappMetaName' => $metaName,
            // Asumimos que tienes relación con Idioma, o guardas el string ISO ('es', 'en')
            // Ajusta este campo según tu esquema local. Aquí asumo un campo string para simplificar.
            'languageCode' => $language
        ]);

        $isNew = false;

        if (!$template) {
            $template = new MessageTemplate();
            $template->setWhatsappMetaName($metaName);
            $template->setLanguageCode($language);

            // Generamos un código interno único y legible para el sistema
            $template->setCode(sprintf('%s_%s', strtoupper($metaName), strtoupper($language)));

            $this->em->persist($template);
            $isNew = true;
        }

        // Extraemos el cuerpo principal y las variables requeridas
        $parsedBody = $this->extractBodyAndVariables($data['components'] ?? []);

        $template->setBodyContent($parsedBody['text']);
        $template->setMetaStatus($status); // APPROVED, REJECTED, etc.

        // Solo inicializamos el params_map si es una plantilla nueva o si el mapa está vacío.
        // No queremos sobreescribir el mapa si el usuario ya vinculó las entidades en EasyAdmin.
        $existingMap = $template->getWhatsappMetaParamsMap();

        if (empty($existingMap) && $parsedBody['variable_count'] > 0) {
            $initialMap = [];
            for ($i = 1; $i <= $parsedBody['variable_count']; $i++) {
                $initialMap[] = [
                    'meta_var' => (string) $i,
                    'entity_field' => null // Listo para que el administrador lo llene en el CRUD
                ];
            }
            $template->setWhatsappMetaParamsMap($initialMap);
        }

        return $isNew;
    }

    /**
     * Extrae el texto del componente BODY y detecta la cantidad máxima de variables.
     * Meta utiliza el formato {{1}}, {{2}}, etc.
     *
     * @param array $components Arreglo de componentes (HEADER, BODY, FOOTER, BUTTONS).
     * @return array{text: string, variable_count: int}
     */
    private function extractBodyAndVariables(array $components): array
    {
        $bodyText = '';
        $maxVariableIndex = 0;

        foreach ($components as $component) {
            if (($component['type'] ?? '') === 'BODY') {
                $bodyText = $component['text'] ?? '';

                // Usamos Regex para buscar todos los patrones {{numero}}
                // Esto nos permite saber cuántas variables dinámicas requiere esta plantilla.
                if (preg_match_all('/\{\{(\d+)\}\}/', $bodyText, $matches)) {
                    // Extraemos los números, los convertimos a entero y sacamos el mayor
                    $numbers = array_map('intval', $matches[1]);
                    $maxVariableIndex = max($numbers);
                }
                break;
            }
        }

        return [
            'text' => $bodyText,
            'variable_count' => $maxVariableIndex
        ];
    }
}