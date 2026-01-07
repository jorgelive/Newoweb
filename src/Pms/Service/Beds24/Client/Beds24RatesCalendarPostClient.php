<?php
declare(strict_types=1);

namespace App\Pms\Service\Beds24\Client;

use App\Pms\Entity\Beds24Config;
use App\Pms\Entity\PmsBeds24Endpoint;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpClient\Exception\TransportExceptionInterface;

final class Beds24RatesCalendarPostClient
{
    private ?int $lastHttpCode = null;
    private ?string $lastRawBody = null;
    private ?string $lastUrl = null;
    private ?string $lastMethod = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly Beds24AuthService $authService,
        private readonly string $beds24BaseUrl,
        private readonly EntityManagerInterface $em,
    ) {}

    public function getLastHttpCode(): ?int { return $this->lastHttpCode; }
    public function getLastRawBody(): ?string { return $this->lastRawBody; }
    public function getLastUrl(): ?string { return $this->lastUrl; }
    public function getLastMethod(): ?string { return $this->lastMethod; }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $query
     * @return array<int, array<string, mixed>>|array<string, mixed>
     */
    public function calendarPost(Beds24Config $config, array $items, array $query = []): array
    {
        $meta = $this->calendarPostWithMeta($config, $items, $query);
        return $meta['data'];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $query
     * @return array{httpCode:int, data: array<int, array<string, mixed>>|array<string, mixed>}
     */
    public function calendarPostWithMeta(Beds24Config $config, array $items, array $query = []): array
    {
        $endpoint = $this->resolveEndpointOrFail('CALENDAR_POST');

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
     * @param array{query?: array<string,mixed>, json?: mixed} $options
     * @return array{httpCode:int, data: array<int, array<string, mixed>>|array<string, mixed>}
     */
    private function requestEndpointWithMeta(Beds24Config $config, PmsBeds24Endpoint $endpoint, array $options = []): array
    {
        $method = strtoupper((string) ($endpoint->getMetodo() ?? 'POST'));

        // Beds24: para calendar normalmente es POST
        if ($method === 'PUT') {
            $method = 'POST';
        }

        $path = '/' . ltrim((string) ($endpoint->getEndpoint() ?? ''), '/');

        return $this->requestWithMeta($config, $method, $path, $options);
    }

    /**
     * @param array{query?: array<string,mixed>, json?: mixed} $options
     * @return array{httpCode:int, data: array<int, array<string, mixed>>|array<string, mixed>}
     */
    private function requestWithMeta(Beds24Config $config, string $method, string $path, array $options = []): array
    {
        $token = $this->authService->getAuthToken($config);
        $url = rtrim($this->beds24BaseUrl, '/') . $path;

        $this->lastUrl = $url;
        $this->lastMethod = $method;

        try {
            $response = $this->httpClient->request($method, $url, [
                'headers' => [
                    'accept' => 'application/json',
                    'token' => $token,
                ],
                'query' => $options['query'] ?? [],
                'json' => $options['json'] ?? null,
            ]);
        } catch (TransportExceptionInterface $e) {
            $this->lastHttpCode = null;
            $this->lastRawBody = null;
            throw new \RuntimeException('Beds24 transport error: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getContent(false);

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

        // Error global:
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