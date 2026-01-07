<?php

namespace App\Pms\Service\Beds24\Client;

use App\Pms\Entity\Beds24Config;
use App\Pms\Entity\PmsBeds24Endpoint;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Beds24AuthService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $em,
        private readonly string $beds24BaseUrl,
        private readonly int $leewaySeconds = 60,
    ) {}

    /**
     * Devuelve un authToken válido para Beds24.
     * Renueva automáticamente si no existe o está vencido.
     */
    public function getAuthToken(Beds24Config $config): string
    {
        if (!$config->isActivo()) {
            throw new \RuntimeException('Beds24Config inactiva.');
        }

        if (
            $config->getAuthToken()
            && $config->getAuthTokenExpiresAt()
            && !$this->isExpired($config->getAuthTokenExpiresAt())
        ) {
            return (string) $config->getAuthToken();
        }

        return $this->refreshToken($config);
    }

    private function isExpired(\DateTimeInterface $expiresAt): bool
    {
        $now = new \DateTimeImmutable();
        return $now >= $expiresAt->modify('-' . $this->leewaySeconds . ' seconds');
    }

    private function refreshToken(Beds24Config $config): string
    {
        if (!$config->getRefreshToken()) {
            throw new \RuntimeException('No existe refreshToken configurado.');
        }

        /** @var PmsBeds24Endpoint|null $endpoint */
        $endpoint = $this->em->getRepository(PmsBeds24Endpoint::class)->findOneBy([
            'accion' => 'GET_TOKEN',
            'activo' => true,
        ]);

        if (!$endpoint) {
            throw new \RuntimeException('Endpoint Beds24 getToken no encontrado o inactivo.');
        }

        $url = rtrim($this->beds24BaseUrl, '/') . '/' . ltrim((string) $endpoint->getEndpoint(), '/');

        $response = $this->httpClient->request(
            (string) $endpoint->getMetodo(), // Beds24 token endpoint: GET with refreshToken header
            $url,
            [
                'headers' => [
                    'Accept' => 'application/json',
                    'refreshToken' => (string) $config->getRefreshToken(),
                ],
            ]
        );

        $statusCode = $response->getStatusCode();
        $rawBody = $response->getContent(false);

        $data = null;
        if (is_string($rawBody) && $rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $data = $decoded;
            }
        }

        if (!is_array($data)) {
            throw new \RuntimeException(sprintf(
                'Respuesta inválida de Beds24 (no JSON). HTTP %s. Body: %s',
                (string) $statusCode,
                mb_substr((string) $rawBody, 0, 2000)
            ));
        }

        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf(
                'Error Beds24 al obtener token. HTTP %s. Body: %s',
                (string) $statusCode,
                mb_substr((string) $rawBody, 0, 2000)
            ));
        }

        $authToken =
            $data['token']
            ?? $data['authToken']
            ?? $data['auth_token']
            ?? $data['AuthToken']
            ?? null;

        $expiresIn = $data['expiresIn'] ?? $data['expires_in'] ?? null;
        $newRefreshToken = $data['refreshToken'] ?? $data['refresh_token'] ?? null;

        if (!$authToken) {
            throw new \RuntimeException(sprintf(
                'Respuesta inválida de Beds24: token ausente. Keys: %s. Body: %s',
                implode(', ', array_keys($data)),
                mb_substr((string) $rawBody, 0, 2000)
            ));
        }

        $expiresAt = is_numeric($expiresIn)
            ? (new \DateTimeImmutable())->modify('+' . (int) $expiresIn . ' seconds')
            : null;

        $config->setAuthToken((string) $authToken);
        $config->setAuthTokenExpiresAt($expiresAt);

        if ($newRefreshToken) {
            $config->setRefreshToken((string) $newRefreshToken);
        }

        $this->em->flush();

        return (string) $authToken;
    }
}