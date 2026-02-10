<?php

namespace App\Service;

use Google\Cloud\Translate\V3\Client\TranslationServiceClient;
use Google\Cloud\Translate\V3\TranslateTextRequest; // Necesario para la compatibilidad
use Google\ApiCore\ApiException;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

/**
 * Servicio de Traducción Google V3.
 * Implementado para cumplir con estándares de configuración por entorno mediante Service Account.
 * * Este servicio utiliza Property Promotion de PHP 8 y vinculación de parámetros de Symfony
 * para asegurar un código limpio, funcional y con tipado estricto.
 */
class GoogleTranslateService
{
    /** @var string ID del proyecto extraído de las credenciales */
    private string $projectId;

    /** @var string Ubicación de la API, por defecto 'global' */
    private string $location = 'global';

    /**
     * @param TranslationServiceClient $client Cliente oficial de Google V3 (inyectado vía autowire).
     * @param array $googleTranslateCredentials Array del JSON de autenticación (inyectado vía bind).
     */
    public function __construct(
        private readonly TranslationServiceClient $client,
        private readonly array $googleTranslateCredentials
    ) {
        // Inicialización crítica: Extraemos el project_id necesario para el path de la API
        $this->projectId = $this->googleTranslateCredentials['project_id'] ?? '';

        if ($this->projectId === '') {
            throw new RuntimeException('Google Translate project_id no configurado');
        }
    }

    /**
     * Realiza la traducción de uno o varios textos.
     * * @param string|array $text Texto único o array de strings a traducir.
     * @param string $targetLanguage Código de idioma destino (ej: 'en', 'es').
     * @param string|null $sourceLanguage Código de idioma origen (opcional).
     * * @return array Array con los strings traducidos.
     * @throws ApiException Si ocurre un error en la comunicación con Google Cloud.
     */
    public function translate(string|array $text, string $targetLanguage, ?string $sourceLanguage = null, string $mimeType = 'text/plain'): array
    {
        $contents = is_array($text) ? $text : [$text];

        // Formato requerido por Google: projects/{project_id}/locations/{location}
        $parent = sprintf('projects/%s/locations/%s', $this->projectId, $this->location);

        // --- CORRECCIÓN TÉCNICA PARA EVITAR EL ERROR DE ARGUMENTO ---
        // El SDK exige ahora un objeto TranslateTextRequest en lugar de argumentos sueltos
        $request = (new TranslateTextRequest())
            ->setContents($contents)
            ->setTargetLanguageCode($targetLanguage)
            ->setParent($parent)
            ->setMimeType($mimeType);

        if ($sourceLanguage) {
            $request->setSourceLanguageCode($sourceLanguage);
        }
        // ------------------------------------------------------------

        try {
            // Llamada actualizada con el objeto Request
            $response = $this->client->translateText($request);

            $results = [];
            foreach ($response->getTranslations() as $translation) {
                $results[] = $translation->getTranslatedText();
            }

            return $results;
        } catch (ApiException $e) {
            // Documentamos que el error se relanza para ser manejado por el nivel superior
            throw $e;
        }
    }

    /**
     * Retorna la instancia del cliente de Google para usos avanzados.
     * * @return TranslationServiceClient
     */
    public function getClient(): TranslationServiceClient
    {
        return $this->client;
    }

    /**
     * Retorna el ID del proyecto cargado.
     * * @return string
     */
    public function getProjectId(): string
    {
        return $this->projectId;
    }

    /**
     * Permite cambiar la ubicación (Location) para la API si se requiere procesamiento regional.
     * * @param string $location
     * @return void
     */
    public function setLocation(string $location): void
    {
        $this->location = $location;
    }

    /**
     * Asegura el cierre de la conexión gRPC/REST al finalizar el ciclo de vida del objeto.
     * Importante para la gestión de recursos en procesos largos (Batch/Command).
     */
    public function __destruct()
    {
        $this->client->close();
    }
}