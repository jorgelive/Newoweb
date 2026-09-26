<?php
declare(strict_types=1);

namespace App\Exchange\Service\Client;

use App\Exchange\Dto\Beds24\Beds24Respuesta;
use App\Exchange\Entity\Beds24Config;
use App\Exchange\Service\Auth\Beds24AuthService;
use App\Exchange\Service\Common\ExchangeNetworkResult;
use App\Exchange\Service\Contract\ExchangeClientInterface;
use App\Exchange\Service\Mapping\MappingResult;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Beds24ExchangeClient implements ExchangeClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly Beds24AuthService $authService,
        private readonly LoggerInterface $logger
    ) {}

    public static function getClientAlias(): string {
        return 'beds24';
    }

    public function send(MappingResult $mapping): ExchangeNetworkResult
    {
        $config = $mapping->config;

        if (!$config instanceof Beds24Config) {
            throw new InvalidArgumentException(sprintf(
                'Beds24ExchangeClient requiere una instancia de Beds24Config, se recibió: %s',
                get_debug_type($config)
            ));
        }

        $allDecodedData = null;
        $finalStatusCode = 200;

        $currentUrl = $mapping->fullUrl;
        $currentPayload = $mapping->payload; // Para GET va en query, para POST en json

        try {
            // 🔥 EL BUCLE TRANSPARENTE DE PAGINACIÓN
            do {
                $options = [
                    'headers' => array_merge(
                        $this->authService->getAuthHeaders($config),
                        ['Accept' => 'application/json', 'Content-Type' => 'application/json']
                    ),
                    ($mapping->method === 'GET' ? 'query' : 'json') => $currentPayload
                ];

                $response = $this->httpClient->request($mapping->method, $currentUrl, $options);

                $rawContent = $response->getContent(false);
                $finalStatusCode = $response->getStatusCode(); // Guardamos el status de la última iteración

                // FIX UTF-8: Forzamos la codificación correcta para salvar Emojis (📍, 🏠)
                $currentEncoding = mb_detect_encoding($rawContent, 'UTF-8, ISO-8859-1', true);
                if ($currentEncoding !== 'UTF-8') {
                    // `mb_convert_encoding()` devuelve `false` si la conversión no se puede
                    // hacer. Si eso pasa se sigue con el original: una página en la codificación
                    // rara es mejor que `false`, que abajo entra a `json_decode()` como cadena
                    // vacía y hace parecer que el canal no devolvió nada.
                    $rawContent = mb_convert_encoding($rawContent, 'UTF-8', $currentEncoding ?: 'ISO-8859-1')
                        ?: $rawContent;
                }

                // Decodificación Segura de la página actual
                $decoded = [];
                try {
                    $decoded = json_decode($rawContent, true, 512, JSON_THROW_ON_ERROR);
                } catch (\Throwable) {
                    // Fallo silencioso en decodificación (ej: error 504 HTML): se sigue con la
                    // página vacía, que no trae ni datos ni página siguiente.
                }

                // La página se lee UNA vez, por el DTO: `data` para fusionar y `pages` para
                // saber si hay otra. Una página que no es un objeto (un JSON escalar) no trae
                // ninguna de las dos cosas.
                $pagina = Beds24Respuesta::fromArray(is_array($decoded) ? $decoded : []);

                // 🏗️ FUSIÓN DE DATOS (Merge)
                if ($allDecodedData === null) {
                    // Es la primera página, inicializamos el objeto maestro. Si no es un array
                    // (un `null` JSON) se queda sin inicializar, como antes: la siguiente página,
                    // si la hay, hará de maestra.
                    $allDecodedData = is_array($decoded) ? $decoded : null;
                } elseif ($pagina->datos !== null) {
                    // Son páginas siguientes, solo agregamos los items al array 'data'
                    $acumulados = $allDecodedData['data'] ?? [];
                    $allDecodedData['data'] = array_merge(is_array($acumulados) ? $acumulados : [], $pagina->datos);
                }

                // 🧭 EVALUAR PAGINACIÓN (Solo aplica para peticiones GET que tengan nextPageExists)
                $hasNextPage = false;
                if ($mapping->method === 'GET' && $pagina->siguientePagina !== null) {
                    $hasNextPage = true;
                    $currentUrl = $pagina->siguientePagina;

                    // IMPORTANTE: Al usar el nextPageLink, Beds24 ya incluye los query parameters originales
                    // (ej: ?status=confirmed&page=2). Debemos vaciar el payload para que Symfony no los duplique.
                    $currentPayload = [];
                }

            } while ($hasNextPage);

            // 📦 Para la auditoría (LastResponseRaw), re-codificamos el array combinado
            // Así en la base de datos podrás ver todo lo que se procesó en un solo JSON.
            // Para la auditoría: si no se puede serializar, se guarda el motivo en vez de
            // `false`, que en la columna se vería como celda vacía y parecería que no hubo
            // respuesta.
            $finalRawContent = json_encode($allDecodedData, JSON_UNESCAPED_UNICODE)
                ?: '{"_error":"la respuesta combinada no se pudo serializar"}';

            return new ExchangeNetworkResult($allDecodedData ?? [], $finalRawContent, $finalStatusCode);

        } catch (\Throwable $e) {
            $this->logger->error("Beds24 Client Error: " . $e->getMessage(), ['url' => $currentUrl]);
            throw $e;
        }
    }
}