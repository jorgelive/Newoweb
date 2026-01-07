<?php
declare(strict_types=1);

namespace App\Pms\Service\Beds24\Client;

use App\Pms\Entity\Beds24Config;
use App\Pms\Entity\PmsBeds24Endpoint;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client PUSH para /bookings:
 * - POST_BOOKINGS
 * - DELETE_BOOKINGS
 *
 * Importante:
 * - NO hardcodea path ni método.
 * - Usa PmsBeds24Endpoint (tabla de endpoints).
 * - Es el client que debe usar el Beds24LinkQueueProcessor.
 */
final class Beds24BookingsPostDeleteClient
{
    /**
     * Última respuesta (debug / troubleshooting).
     *
     * Nota:
     * - En bookings, Beds24 puede devolver éxito parcial en un array indexado.
     * - Aquí guardamos el HTTP code y el body raw por si toca revisar un caso "no JSON" o "success=false".
     */
    private ?int $lastHttpCode = null;

    /** @var string|null Raw body (máx lo que entregue el HttpClient). */
    private ?string $lastRawBody = null;

    /** @var string|null URL final usada en el request (útil para auditar endpoint mal configurado). */
    private ?string $lastUrl = null;

    /** @var string|null Método usado en el request (GET/POST/DELETE). */
    private ?string $lastMethod = null;

    public function getLastHttpCode(): ?int
    {
        return $this->lastHttpCode;
    }

    public function getLastRawBody(): ?string
    {
        return $this->lastRawBody;
    }

    public function getLastUrl(): ?string
    {
        return $this->lastUrl;
    }

    public function getLastMethod(): ?string
    {
        return $this->lastMethod;
    }

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly Beds24AuthService $authService,
        private readonly string $beds24BaseUrl,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Ejecuta POST_BOOKINGS usando la definición del endpoint en BD.
     *
     * @param array<int,array<string,mixed>> $items
     * @param array<string,mixed> $query
     * @return array<int, array<string, mixed>>|array<string, mixed>
     */
    public function postBookings(Beds24Config $config, array $items, array $query = []): array
    {
        $endpoint = $this->resolveEndpointOrFail('POST_BOOKINGS');

        return $this->requestEndpoint($config, $endpoint, [
            'query' => $query,
            'json' => $items,
        ]);
    }

    /**
     * Ejecuta DELETE_BOOKINGS usando la definición del endpoint en BD.
     *
     * @param array<int,array<string,mixed>> $items
     * @param array<string,mixed> $query
     * @return array<int, array<string, mixed>>|array<string, mixed>
     */
    public function deleteBookings(Beds24Config $config, array $items, array $query = []): array
    {
        $endpoint = $this->resolveEndpointOrFail('DELETE_BOOKINGS');

        return $this->requestEndpoint($config, $endpoint, [
            'query' => $query,
            'json' => $items,
        ]);
    }

    /**
     * Variante que retorna metadata (HTTP code + data) para auditoría.
     *
     * Importante:
     * - Mantiene la misma validación global del client.
     * - Si Beds24 devuelve éxito parcial (array indexado), NO se lanza excepción aquí.
     *   El processor decide por item.
     *
     * @param array<int,array<string,mixed>> $items
     * @param array<string,mixed> $query
     * @return array{httpCode:int, data: array<int, array<string, mixed>>|array<string, mixed>}
     */
    public function postBookingsWithMeta(Beds24Config $config, array $items, array $query = []): array
    {
        $endpoint = $this->resolveEndpointOrFail('POST_BOOKINGS');

        return $this->requestEndpointWithMeta($config, $endpoint, [
            'query' => $query,
            'json' => $items,
        ]);
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @param array<string,mixed> $query
     * @return array{httpCode:int, data: array<int, array<string, mixed>>|array<string, mixed>}
     */
    public function deleteBookingsWithMeta(Beds24Config $config, array $items, array $query = []): array
    {
        $endpoint = $this->resolveEndpointOrFail('DELETE_BOOKINGS');

        return $this->requestEndpointWithMeta($config, $endpoint, [
            'query' => $query,
            'json' => $items,
        ]);
    }

    private function resolveEndpointOrFail(string $accion): PmsBeds24Endpoint
    {
        $repo = $this->em->getRepository(PmsBeds24Endpoint::class);

        $endpoint = $repo->findOneBy([
            'accion' => $accion,
            'activo' => true,
        ]);

        if (!$endpoint instanceof PmsBeds24Endpoint) {
            throw new \RuntimeException('No existe endpoint activo para ' . $accion);
        }

        return $endpoint;
    }

    /**
     * Request usando endpoint configurado en BD.
     *
     * Importante:
     * - Beds24 procesa altas/updates con POST (no PUT).
     * - Para DELETE, se mantiene DELETE.
     *
     * @param array{query?: array<string,mixed>, json?: mixed} $options
     * @return array<int, array<string, mixed>>|array<string, mixed>
     */
    private function requestEndpoint(Beds24Config $config, PmsBeds24Endpoint $endpoint, array $options = []): array
    {
        $method = strtoupper((string) ($endpoint->getMetodo() ?? 'GET'));

        // Seguridad: si alguien configuró PUT por error, lo forzamos a POST.
        if ($method === 'PUT') {
            $method = 'POST';
        }

        $path = '/' . ltrim((string) ($endpoint->getEndpoint() ?? ''), '/');

        return $this->request($config, $method, $path, $options);
    }

    /**
     * Igual que requestEndpoint(), pero retorna metadata.
     *
     * @param array{query?: array<string,mixed>, json?: mixed} $options
     * @return array{httpCode:int, data: array<int, array<string, mixed>>|array<string, mixed>}
     */
    private function requestEndpointWithMeta(Beds24Config $config, PmsBeds24Endpoint $endpoint, array $options = []): array
    {
        $method = strtoupper((string) ($endpoint->getMetodo() ?? 'GET'));

        // Seguridad: si alguien configuró PUT por error, lo forzamos a POST.
        if ($method === 'PUT') {
            $method = 'POST';
        }

        $path = '/' . ltrim((string) ($endpoint->getEndpoint() ?? ''), '/');

        return $this->requestWithMeta($config, $method, $path, $options);
    }

    /**
     * @param array{query?: array<string,mixed>, json?: mixed} $options
     * @return array<int, array<string, mixed>>|array<string, mixed>
     */
    private function request(Beds24Config $config, string $method, string $path, array $options = []): array
    {
        $meta = $this->requestWithMeta($config, $method, $path, $options);
        return $meta['data'];
    }

    /**
     * Request "base" que guarda metadata útil para debugging.
     *
     * Importante sobre validación:
     * - Si HTTP >= 400 => error global => se lanza excepción.
     * - Si Beds24 devuelve {success:false,...} (objeto) => error global => se lanza excepción.
     * - Si Beds24 devuelve un array indexado (batch por item), puede haber éxito parcial.
     *   En ese caso NO hay `success` a nivel raíz, y el processor decide por item.
     *
     * @param array{query?: array<string,mixed>, json?: mixed} $options
     * @return array{httpCode:int, data: array<int, array<string, mixed>>|array<string, mixed>}
     */
    private function requestWithMeta(Beds24Config $config, string $method, string $path, array $options = []): array
    {
        $token = $this->authService->getAuthToken($config);
        $url = rtrim($this->beds24BaseUrl, '/') . $path;

        // Guardar para debug (muy útil cuando un endpoint en BD queda mal configurado)
        $this->lastUrl = $url;
        $this->lastMethod = $method;

        $response = $this->httpClient->request($method, $url, [
            'headers' => [
                'accept' => 'application/json',
                'token' => $token,
            ],
            'query' => $options['query'] ?? [],
            // Para POST/DELETE usamos JSON payload (Beds24 lo acepta para bookings)
            'json' => $options['json'] ?? null,
        ]);

        $status = $response->getStatusCode();
        $raw = (string) $response->getContent(false);

        // Snapshot debug
        $this->lastHttpCode = $status;
        $this->lastRawBody = $raw;

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            throw new \RuntimeException(sprintf(
                'Beds24 %s %s no JSON. HTTP %s. Body: %s',
                $method,
                $path,
                (string) $status,
                mb_substr($raw, 0, 2000)
            ));
        }

        // Error global (no confundir con error parcial por-item)
        // - Si `success` existe a nivel raíz y es false => error global.
        // - Si es batch indexado, normalmente no existe `success` a nivel raíz.
        if ($status >= 400 || (array_key_exists('success', $data) && ($data['success'] === false))) {
            throw new \RuntimeException(sprintf(
                'Beds24 %s %s error. HTTP %s. Body: %s',
                $method,
                $path,
                (string) $status,
                mb_substr($raw, 0, 2000)
            ));
        }

        return [
            'httpCode' => $status,
            'data' => $data,
        ];
    }
}