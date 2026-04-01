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
use Throwable;

/**
 * Servicio encargado de despachar notificaciones WebPush encriptadas a los navegadores.
 * * ¿Por qué existe?: Desacopla la compleja lógica criptográfica y de red (HTTP/2) requerida
 * por el estándar VAPID. Actúa como una interfaz limpia para que cualquier parte de la
 * aplicación pueda enviar notificaciones a los usuarios de forma asíncrona hacia los servidores Push.
 */
class WebPushNotificationService
{
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;
    private WebPush $webPush;

    /**
     * Inicializa el servicio configurando las credenciales VAPID.
     * * @param EntityManagerInterface $entityManager Para limpiar suscripciones caducadas.
     * @param LoggerInterface $logger Para registrar el rastro de ejecución y fallos.
     * @param string $publicKey Clave pública VAPID (VITE_VAPID_PUBLIC_KEY en el cliente).
     * @param string $privateKey Clave privada VAPID (Secreta en el servidor).
     * @param string $subject Identificador de contacto (mailto o URL).
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

        $this->webPush = new WebPush($auth);
    }

    /**
     * Envía una notificación Push a todos los dispositivos registrados de un usuario.
     * * Procesa la cola de notificaciones y analiza los reportes de éxito o fracaso
     * provenientes de los servidores de mensajería (FCM para Chrome, APNs para Safari).
     * * @param User $user El usuario destinatario.
     * @param array $payload Datos de la notificación (title, body, actionUrl).
     * @return void
     * @throws \RuntimeException Si ocurre un error crítico de configuración o red.
     */
    public function sendToUser(User $user, array $payload): void
    {
        $this->logger->info("🚀 [WebPush] Iniciando envío para el usuario: " . $user->getUserIdentifier());

        $subscriptions = $this->entityManager->getRepository(PushSubscription::class)->findBy(['user' => $user]);

        if (empty($subscriptions)) {
            $this->logger->warning("⚠️ [WebPush] El usuario " . $user->getUserIdentifier() . " NO tiene dispositivos suscritos en la BD.");
            return;
        }

        $this->logger->info("📱 [WebPush] Se encontraron " . count($subscriptions) . " suscripciones para este usuario.");

        $payloadJson = json_encode([
            'type' => 'PUSH_TO_STORE',
            'payload' => $payload
        ]);

        if ($payloadJson === false) {
            $error = json_last_error_msg();
            $this->logger->critical("🔥 [WebPush] ERROR FATAL: El payload no se pudo codificar. Motivo: $error");
            throw new \RuntimeException("Payload inválido para WebPush: $error");
        }

        $this->logger->debug("📦 [WebPush] Payload JSON preparado: " . $payloadJson);

        try {
            foreach ($subscriptions as $dbSubscription) {
                $this->logger->info("🔗 [WebPush] Encolando para endpoint: " . substr($dbSubscription->getEndpoint(), 0, 50) . "...");

                $sub = Subscription::create([
                    'endpoint' => $dbSubscription->getEndpoint(),
                    'keys' => [
                        'p256dh' => $dbSubscription->getP256dhKey(),
                        'auth' => $dbSubscription->getAuthToken()
                    ],
                ]);

                $this->webPush->queueNotification($sub, $payloadJson);
            }

            $this->logger->info("📡 [WebPush] Ejecutando flush() hacia los servidores remotos...");
            $reports = $this->webPush->flush();
            $this->logger->info("✅ [WebPush] flush() completado. Procesando reportes...");

            foreach ($reports as $report) {
                $endpoint = $report->getRequest()->getUri()->__toString();

                if ($report->isSuccess()) {
                    $this->logger->info("✔️ [WebPush] ÉXITO: Notificación entregada a " . substr($endpoint, 0, 50) . "...");
                } else {
                    $response = $report->getResponse();
                    $statusCode = $response ? $response->getStatusCode() : 0;
                    $reason = $report->getReason();

                    if (in_array($statusCode, [404, 410], true)) {
                        $this->logger->info("🧹 [WebPush] Limpieza: Endpoint caducado (HTTP $statusCode). Eliminando de la BD.");
                        $this->removeExpiredSubscription($endpoint);
                        continue;
                    }

                    $errorMsg = "🔥 [WebPush] ERROR CRÍTICO a {$endpoint} | HTTP {$statusCode} | Motivo: {$reason}";
                    $this->logger->critical($errorMsg);
                    throw new \RuntimeException($errorMsg);
                }
            }

        } catch (Throwable $e) {
            $this->logger->emergency("🚨 [WebPush] EMERGENCIA: Fallo interno catastrófico. Detalle: " . $e->getMessage());
            throw new \RuntimeException("Fallo interno en WebPush: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Elimina una suscripción de la persistencia cuando el servidor remoto indica que ya no es válida.
     * * @param string $endpoint El identificador único del dispositivo en el servidor Push.
     */
    private function removeExpiredSubscription(string $endpoint): void
    {
        $repo = $this->entityManager->getRepository(PushSubscription::class);
        $subscription = $repo->findOneBy(['endpoint' => $endpoint]);

        if ($subscription) {
            $this->entityManager->remove($subscription);
            $this->entityManager->flush();
        }
    }
}