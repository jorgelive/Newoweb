<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Servicio encargado de despachar notificaciones WebPush encriptadas a los navegadores.
 * * ¿Por qué existe?: Desacopla la compleja lógica criptográfica y de red (HTTP/2) requerida
 * por el estándar VAPID. Actúa como una interfaz limpia para que cualquier parte de la
 * aplicación (controladores, comandos, eventos) pueda enviar notificaciones a los usuarios.
 */
class WebPushNotificationService
{
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;
    private WebPush $webPush;

    /**
     * Inicializa el servicio configurando las credenciales VAPID.
     * * @param EntityManagerInterface $entityManager Para limpiar suscripciones caducadas.
     * @param LoggerInterface $logger Para registrar fallos en el envío.
     * @param string $publicKey Clave pública VAPID desde el .env
     * @param string $privateKey Clave privada VAPID desde el .env
     * @param string $subject Correo de contacto VAPID desde el .env
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
        #[Autowire('%env(VAPID_PUBLIC_KEY)%')] string $publicKey,
        #[Autowire('%env(VAPID_PRIVATE_KEY)%')] string $privateKey,
        #[Autowire('%env(VAPID_SUBJECT)%')] string $subject
    ) {
        $this->entityManager = $entityManager;
        $this->logger = $logger;

        $auth = [
            'VAPID' => [
                'subject' => $subject,
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ],
        ];

        // Se inicializa la instancia principal de envío con las credenciales de OpenPeru
        $this->webPush = new WebPush($auth);
    }

    /**
     * Envía una notificación Push a todos los dispositivos registrados de un usuario.
     * * ¿Por qué existe?: Es el método principal de uso. Recibe un usuario y el contenido,
     * formatea el payload y lo encola para su despacho masivo. Luego procesa las respuestas
     * para eliminar endpoints muertos (Error 410).
     * * * @example
     * $payload = ['title' => 'Nuevo Mensaje', 'body' => 'Hola', 'actionUrl' => '/chat/1'];
     * $pushService->sendToUser($user, $payload);
     * * @param User $user El usuario destinatario de la notificación.
     * @param array $payload Datos de la notificación (title, body, type, actionUrl).
     * @return void
     */
    public function sendToUser(User $user, array $payload): void
    {
        $subscriptions = $this->entityManager->getRepository(PushSubscription::class)->findBy(['user' => $user]);

        if (empty($subscriptions)) {
            return; // El usuario no tiene ningún dispositivo con permisos Push
        }

        // El payload debe ser un JSON string para que el navegador lo entienda
        $payloadJson = json_encode([
            'type' => 'PUSH_TO_STORE',
            'payload' => $payload
        ]);

        // 1. Encolar los mensajes para cada dispositivo del usuario
        foreach ($subscriptions as $dbSubscription) {
            $sub = Subscription::create([
                'endpoint' => $dbSubscription->getEndpoint(),
                'keys' => [
                    'p256dh' => $dbSubscription->getP256dhKey(),
                    'auth' => $dbSubscription->getAuthToken()
                ],
            ]);

            $this->webPush->queueNotification($sub, $payloadJson);
        }

        // 2. Disparar todos los mensajes encolados simultáneamente
        $reports = $this->webPush->flush();

        // 3. Evaluar los resultados y limpiar la base de datos si es necesario
        foreach ($reports as $report) {
            $endpoint = $report->getRequest()->getUri()->__toString();

            if (!$report->isSuccess()) {
                $this->logger->warning("Fallo al enviar Push a {$endpoint}: {$report->getReason()}");

                // El error 410 o 404 indica que la suscripción expiró o fue revocada permanentemente.
                // DEBEMOS eliminarla de la base de datos para no seguir intentando en el futuro.
                $response = $report->getResponse();
                if ($response && in_array($response->getStatusCode(), [404, 410], true)) {
                    $this->removeExpiredSubscription($endpoint);
                }
            }
        }
    }

    /**
     * Elimina internamente una suscripción basándose en su endpoint.
     * * @param string $endpoint La URL del servidor Push caducada.
     */
    private function removeExpiredSubscription(string $endpoint): void
    {
        $repo = $this->entityManager->getRepository(PushSubscription::class);
        $subscription = $repo->findOneBy(['endpoint' => $endpoint]);

        if ($subscription) {
            $this->entityManager->remove($subscription);
            $this->entityManager->flush();
            $this->logger->info("Suscripción Push caducada eliminada de la BD: {$endpoint}");
        }
    }
}