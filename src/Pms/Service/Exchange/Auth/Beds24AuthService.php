<?php
declare(strict_types=1);

namespace App\Pms\Service\Exchange\Auth;

use App\Pms\Entity\Beds24Config;
use App\Pms\Entity\Beds24Endpoint;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use RuntimeException;

final class Beds24AuthService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $em
    ) {}

    public function getAuthHeaders(Beds24Config $config): array
    {
        return ['token' => $this->getAuthToken($config)];
    }

    public function getAuthToken(Beds24Config $config): string
    {
        if ($config->getAuthToken() && $config->getAuthTokenExpiresAt() > new \DateTimeImmutable('+1 minute')) {
            return $config->getAuthToken();
        }
        return $this->refreshToken($config);
    }

    private function refreshToken(Beds24Config $config): string
    {
        $endpoint = $this->em->getRepository(Beds24Endpoint::class)->findOneBy(['accion' => 'GET_TOKEN']);
        if (!$endpoint) throw new RuntimeException('Endpoint GET_TOKEN no definido.');

        // Usamos la baseUrl de la configuración
        $url = rtrim($config->getBaseUrl(), '/') . '/' . ltrim($endpoint->getEndpoint(), '/');

        $response = $this->httpClient->request($endpoint->getMetodo(), $url, [
            'headers' => ['refreshToken' => $config->getRefreshToken()]
        ]);

        $data = $response->toArray();
        $token = $data['token'] ?? $data['authToken'] ?? null;

        if (!$token) throw new RuntimeException('Error obteniendo token de Beds24');

        $config->setAuthToken($token);
        $config->setAuthTokenExpiresAt((new \DateTimeImmutable())->modify('+' . ($data['expiresIn'] ?? 3600) . ' seconds'));

        $this->em->flush();
        return $token;
    }
}