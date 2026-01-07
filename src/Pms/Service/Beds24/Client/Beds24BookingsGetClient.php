<?php
declare(strict_types=1);

namespace App\Pms\Service\Beds24\Client;

use App\Pms\Entity\Beds24Config;
use App\Pms\Entity\PmsBeds24Endpoint;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Beds24BookingsGetClient
{
    /**
     * Acción esperada en la tabla pms_beds24_endpoint
     * para el pull de bookings.
     */
    public const ACCION_GET_BOOKINGS = 'GET_BOOKINGS';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly Beds24AuthService $authService,
        private readonly string $beds24BaseUrl,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Ejecuta GET /bookings (PULL)
     *
     * Importante:
     * - El path y el método NO están hardcodeados.
     * - Se resuelven desde la tabla pms_beds24_endpoint.
     *
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    public function getBookings(Beds24Config $config, array $query): array
    {
        $endpoint = $this->resolveEndpoint();

        return $this->requestEndpoint($config, $endpoint, [
            'query' => $query,
        ]);
    }

    /**
     * Resuelve el endpoint activo desde BD.
     *
     * Esto permite:
     * - Cambiar path / método sin tocar código
     * - Mantener simetría con PUSH
     */
    private function resolveEndpoint(): PmsBeds24Endpoint
    {
        $repo = $this->em->getRepository(PmsBeds24Endpoint::class);

        $endpoint = $repo->findOneBy([
            'accion' => self::ACCION_GET_BOOKINGS,
            'activo' => true,
        ]);

        if (!$endpoint instanceof PmsBeds24Endpoint) {
            throw new \RuntimeException(
                'No existe endpoint activo para GET_BOOKINGS'
            );
        }

        return $endpoint;
    }

    /**
     * Ejecuta request HTTP usando definición del endpoint.
     *
     * Nota:
     * - Para GET_BOOKINGS forzamos método GET por seguridad.
     *
     * @param array{query?: array<string,mixed>, json?: mixed} $options
     * @return array<string,mixed>
     */
    private function requestEndpoint(
        Beds24Config $config,
        PmsBeds24Endpoint $endpoint,
        array $options = []
    ): array {
        $method = strtoupper((string) ($endpoint->getMetodo() ?? 'GET'));

        // Seguridad: GET bookings siempre debe ser GET
        if ($method !== 'GET') {
            $method = 'GET';
        }

        $path = '/' . ltrim((string) $endpoint->getEndpoint(), '/');

        return $this->request($config, $method, $path, $options);
    }

    /**
     * Request HTTP real.
     *
     * Mantiene exactamente la misma semántica que antes.
     */
    private function request(
        Beds24Config $config,
        string $method,
        string $path,
        array $options = []
    ): array {
        $token = $this->authService->getAuthToken($config);
        $url = rtrim($this->beds24BaseUrl, '/') . $path;

        $response = $this->httpClient->request($method, $url, [
            'headers' => [
                'accept' => 'application/json',
                'token' => $token,
            ],
            'query' => $options['query'] ?? [],
            'json' => $options['json'] ?? null,
        ]);

        $status = $response->getStatusCode();
        $raw = (string) $response->getContent(false);

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

        if ($status >= 400 || (($data['success'] ?? true) === false)) {
            throw new \RuntimeException(sprintf(
                'Beds24 %s %s error. HTTP %s. Body: %s',
                $method,
                $path,
                (string) $status,
                mb_substr($raw, 0, 2000)
            ));
        }

        return $data;
    }
}