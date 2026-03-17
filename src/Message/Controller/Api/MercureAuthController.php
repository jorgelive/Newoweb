<?php

declare(strict_types=1);

namespace App\Message\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class MercureAuthController extends AbstractController
{
    public function __construct(
        // 🔥 Inyectamos las variables de entorno directamente vía Autowire
        #[Autowire(env: 'MERCURE_JWT_SECRET')]
        private readonly string $jwtSecret,

        #[Autowire(env: 'MERCURE_PUBLIC_URL')]
        private readonly string $publicUrl
    ) {}

    #[Route('/message/mercure/auth', name: 'api_message_mercure_auth', methods: ['GET'])]
    public function getMercureToken(): JsonResponse
    {
        // 1. Validar que el usuario esté logueado en Symfony
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'No autorizado'], 401);
        }

        // 2. Configuramos la librería JWT con el secreto inyectado
        $configuration = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($this->jwtSecret)
        );

        // 3. Armamos el Token.
        // La clave aquí es el array "mercure.subscribe".
        // El '*' significa que le damos permiso para escuchar TODOS los tópicos.
        $token = $configuration->builder()
            ->withClaim('mercure', ['subscribe' => ['*']])
            ->getToken($configuration->signer(), $configuration->signingKey())
            ->toString();

        // 4. Devolvemos la URL pública del Hub y el Token a la PWA en Vue
        return $this->json([
            'hubUrl' => $this->publicUrl,
            'token'  => $token
        ]);
    }
}