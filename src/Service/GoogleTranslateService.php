<?php

declare(strict_types=1);

namespace App\Service;

use Google\Cloud\Translate\V3\Client\TranslationServiceClient;
use Google\Cloud\Translate\V3\TranslateTextRequest;
use Google\ApiCore\ApiException;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

/**
 * Servicio de Traducción Google V3 mejorado.
 * Implementa detección automática de idioma y traducción en una sola llamada.
 * Optimizado para el ciclo de vida de Symfony y tipado estricto PHP 8.
 */
class GoogleTranslateService
{
    /** @var string ID del proyecto extraído de las credenciales */
    private string $projectId;

    /** @var string Ubicación de la API, por defecto 'global' */
    private string $location = 'global';

    /**
     * @param TranslationServiceClient $client Cliente oficial de Google V3.
     * @param array $googleTranslateCredentials JSON de autenticación.
     */
    public function __construct(
        private readonly TranslationServiceClient $client,
        private readonly array $googleTranslateCredentials
    ) {
        $this->projectId = $this->googleTranslateCredentials['project_id'] ?? '';

        if ($this->projectId === '') {
            throw new RuntimeException('Google Translate project_id no configurado en las credenciales.');
        }
    }

    /**
     * Realiza la traducción simple con idiomas definidos.
     *
     * @param string|array $text Texto o array de textos.
     * @param string $targetLanguage Idioma destino (ISO).
     * @param string|null $sourceLanguage Idioma origen (ISO).
     * @param string $mimeType Formato del texto (por defecto text/plain).
     * @return array Lista de strings traducidos.
     * @throws ApiException Si ocurre un error en la comunicación con Google Cloud.
     */
    public function translate(string|array $text, string $targetLanguage, ?string $sourceLanguage = null, string $mimeType = 'text/plain'): array
    {
        $contents = is_array($text) ? $text : [$text];
        $parent = sprintf('projects/%s/locations/%s', $this->projectId, $this->location);

        $request = (new TranslateTextRequest())
            ->setContents($contents)
            ->setTargetLanguageCode($targetLanguage)
            ->setParent($parent)
            ->setMimeType($mimeType);

        if ($sourceLanguage) {
            $request->setSourceLanguageCode($sourceLanguage);
        }

        try {
            $response = $this->client->translateText($request);
            $results = [];
            foreach ($response->getTranslations() as $translation) {
                $results[] = $translation->getTranslatedText();
            }

            return $results;
        } catch (ApiException $e) {
            throw $e;
        }
    }

    /**
     * Traduce y detecta el idioma de origen automáticamente.
     * Útil para mensajes entrantes de clientes donde el idioma puede variar.
     *
     * @param string|array $text Contenido a traducir.
     * @param string $targetLanguage Idioma al que queremos traducir (baseLanguage).
     * @param string $mimeType Formato del texto (por defecto text/plain).
     * @return array Contiene 'translations' (array de strings) y 'detectedLanguage' (string ISO).
     * @throws ApiException Si ocurre un error en la comunicación con Google Cloud.
     */
    public function translateWithDetection(string|array $text, string $targetLanguage, string $mimeType = 'text/plain'): array
    {
        $contents = is_array($text) ? $text : [$text];
        $parent = sprintf('projects/%s/locations/%s', $this->projectId, $this->location);

        // Al NO setear setSourceLanguageCode, Google activa la detección automática.
        $request = (new TranslateTextRequest())
            ->setContents($contents)
            ->setTargetLanguageCode($targetLanguage)
            ->setParent($parent)
            ->setMimeType($mimeType);

        try {
            $response = $this->client->translateText($request);

            $results = [
                'translations' => [],
                'detectedLanguage' => null
            ];

            foreach ($response->getTranslations() as $translation) {
                $results['translations'][] = $translation->getTranslatedText();

                // Extraemos el código detectado del primer fragmento que lo contenga
                if (null === $results['detectedLanguage'] && $translation->getDetectedLanguageCode()) {
                    $results['detectedLanguage'] = $translation->getDetectedLanguageCode();
                }
            }

            return $results;
        } catch (ApiException $e) {
            throw $e;
        }
    }

    /**
     * Retorna la instancia del cliente para operaciones personalizadas.
     *
     * @return TranslationServiceClient
     */
    public function getClient(): TranslationServiceClient
    {
        return $this->client;
    }

    /**
     * Retorna el ID del proyecto cargado.
     *
     * @return string
     */
    public function getProjectId(): string
    {
        return $this->projectId;
    }

    /**
     * Define la ubicación regional de la API.
     *
     * @param string $location
     */
    public function setLocation(string $location): void
    {
        $this->location = $location;
    }

    /**
     * Cierra la conexión gRPC al destruir el servicio.
     * Importante para la gestión de recursos en workers de Messenger.
     */
    public function __destruct()
    {
        $this->client->close();
    }
}