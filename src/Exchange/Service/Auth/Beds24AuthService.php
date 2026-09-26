<?php
declare(strict_types=1);

namespace App\Exchange\Service\Auth;

use App\Dto\Lee;
use App\Exchange\Entity\Beds24Config;
use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Enum\ConnectivityProvider;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Beds24AuthService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $em
    ) {}

    /** @return array<string, string> La cabecera `token` que pide Beds24, y nada más. */
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
        $endpoint = $this->em->getRepository(ExchangeEndpoint::class)->findOneBy([
            'provider' => ConnectivityProvider::BEDS24,
            'accion' => 'GET_TOKEN',
            'activo' => true
        ]);
        if (!$endpoint) throw new RuntimeException('Endpoint GET_TOKEN no definido.');

        // Usamos la baseUrl de la configuración
        // Sin ruta, la URL quedaría en la base a secas y pediría el token donde no es.
        $ruta = $endpoint->getEndpoint();
        if ($ruta === null || $ruta === '') {
            throw new RuntimeException('Endpoint GET_TOKEN sin ruta configurada.');
        }
        $url = rtrim($config->getBaseUrl(), '/') . '/' . ltrim($ruta, '/');

        $response = $this->httpClient->request($endpoint->getMetodo(), $url, [
            'headers' => ['refreshToken' => $config->getRefreshToken()]
        ]);

        $data = $response->toArray();
        $token = Lee::texto($data['token'] ?? $data['authToken'] ?? null);

        if (!$token) throw new RuntimeException('Error obteniendo token de Beds24');

        // Un `expiresIn` que no sea un número daba `+ seconds`, que `modify()` no entiende: el
        // token quedaba con una caducidad basura. Se usa la hora de siempre.
        $segundos = Lee::entero($data['expiresIn'] ?? null) ?? 3600;

        $config->setAuthToken($token);
        $config->setAuthTokenExpiresAt((new \DateTimeImmutable())->modify('+' . $segundos . ' seconds'));

        $this->em->flush();
        return $token;
    }
}