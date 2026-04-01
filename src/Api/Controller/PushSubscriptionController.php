<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controlador encargado de la gestión de suscripciones WebPush.
 * * Este controlador permite que los diferentes clientes (PWA Pax, PWA Util, etc.)
 * registren y eliminen los identificadores únicos de los navegadores de los usuarios.
 */
class PushSubscriptionController extends AbstractController
{
    /**
     * @var EntityManagerInterface Manejador de persistencia para operaciones de base de datos.
     */
    private EntityManagerInterface $entityManager;

    /**
     * Constructor del controlador.
     * * @param EntityManagerInterface $entityManager Inyección del ORM para persistencia de suscripciones.
     */
    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Registra o actualiza una suscripción de notificaciones Push para el usuario autenticado.
     * * El método recibe un payload JSON desde Axios con las llaves criptográficas
     * generadas por el navegador del cliente. Si el endpoint ya existe, se actualiza
     * el usuario vinculado para asegurar que las notificaciones sigan al usuario logueado.
     * * @param Request $request Contiene el cuerpo de la petición con endpoint, p256dh y auth.
     * @return JsonResponse Respuesta estandarizada sobre el estado del registro.
     */
    #[Route('/user/push-subscription', name: 'api_user_push_subscription', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function subscribe(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Usuario no identificado o sesión inválida.'], Response::HTTP_UNAUTHORIZED);
        }

        $content = $request->getContent();
        if (empty($content)) {
            return new JsonResponse(['error' => 'El cuerpo de la petición no puede estar vacío.'], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($content, true);

        $endpoint = $data['endpoint'] ?? null;
        $p256dh   = $data['p256dh'] ?? null;
        $auth     = $data['auth'] ?? null;

        if (!$endpoint || !$p256dh || !$auth) {
            return new JsonResponse(['error' => 'Datos de suscripción incompletos (endpoint, p256dh o auth ausentes).'], Response::HTTP_BAD_REQUEST);
        }

        $subscriptionRepository = $this->entityManager->getRepository(PushSubscription::class);
        $subscription = $subscriptionRepository->findOneBy(['endpoint' => $endpoint]);

        if (null === $subscription) {
            $subscription = new PushSubscription();
            $subscription->setEndpoint((string) $endpoint);
        }

        $subscription->setUser($user);
        $subscription->setP256dhKey((string) $p256dh);
        $subscription->setAuthToken((string) $auth);

        try {
            $this->entityManager->persist($subscription);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Error interno al persistir la suscripción.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'status' => 'success',
            'message' => 'Suscripción registrada correctamente para el usuario ' . $user->getUserIdentifier()
        ], Response::HTTP_CREATED);
    }

    /**
     * Elimina una suscripción Push de la base de datos.
     * * Se llama asíncronamente desde el frontend justo antes de que el usuario
     * cierre su sesión. Esto garantiza que el dispositivo físico deje de recibir notificaciones
     * de un usuario que ya se desconectó.
     * * @param Request $request
     * @return JsonResponse
     */
    #[Route('/user/push-unsubscribe', name: 'api_user_push_unsubscribe', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function unsubscribe(Request $request): JsonResponse
    {
        $content = $request->getContent();
        if (empty($content)) {
            return new JsonResponse(['error' => 'Petición vacía.'], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($content, true);
        $endpoint = $data['endpoint'] ?? null;

        if (!$endpoint) {
            return new JsonResponse(['error' => 'Falta el endpoint a desuscribir.'], Response::HTTP_BAD_REQUEST);
        }

        $subscriptionRepository = $this->entityManager->getRepository(PushSubscription::class);
        $subscription = $subscriptionRepository->findOneBy(['endpoint' => $endpoint]);

        if ($subscription) {
            if ($subscription->getUser() === $this->getUser()) {
                $this->entityManager->remove($subscription);
                $this->entityManager->flush();
            }
        }

        return new JsonResponse(['status' => 'success', 'message' => 'Desuscripción completada.'], Response::HTTP_OK);
    }
}