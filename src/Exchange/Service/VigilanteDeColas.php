<?php

declare(strict_types=1);

namespace App\Exchange\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Roles;
use App\Service\WebPushNotificationService;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Throwable;

/**
 * Vigila que nada se quede atascado en las colas de intercambio ni en la auditoría de webhooks.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * El 15/08/2026 tres cancelaciones de Booking entraron por webhook, el worker las abandonó a
 * medias por un `TypeError`, y las reservas se quedaron vivas en el calendario. **El dato estaba
 * ahí desde el primer minuto**: las auditorías quedaron en `partial_error` a las 08:11 y 108
 * ítems de cola en `failed`. Nadie lo vio hasta doce horas después, mirando el calendario por
 * casualidad.
 *
 * No hacía falta detectar el fallo: hacía falta que alguien mirase los estados que el sistema ya
 * escribía. Eso es todo lo que hace esta clase.
 *
 * ── Qué NO hace ─────────────────────────────────────────────────────────────
 * No reintenta nada. Reprocesar un webhook o una cola es una decisión con efectos —puede duplicar
 * un envío, puede pisar un dato editado a mano— y ésa la toma una persona desde el panel. Aquí
 * sólo se avisa.
 *
 * Y no avisa dos veces de lo mismo: sólo mira una ventana reciente, así que un fallo antiguo que
 * ya se decidió dejar estar no vuelve a sonar cada hora.
 */
final readonly class VigilanteDeColas
{
    /**
     * Las colas y su columna de estado.
     *
     * Se consulta por SQL directo a propósito: son siete tablas de módulos distintos, sin
     * ancestro común, y lo que se quiere es un recuento — no hidratar entidades que no se van a
     * usar. Añadir una cola nueva es una línea aquí.
     *
     * @var array<string, string>
     */
    private const COLAS = [
        'pms_bookings_pull_queue' => 'reservas (pull)',
        'pms_bookings_push_queue' => 'reservas (push)',
        'pms_rates_push_queue' => 'tarifas',
        'pms_beds24_invoice_receive_queue' => 'facturas',
        'msg_beds24_send_queue' => 'mensajes Beds24 (envío)',
        'msg_beds24_receive_queue' => 'mensajes Beds24 (recepción)',
        'msg_whatsapp_meta_send_queue' => 'WhatsApp (envío)',
    ];

    /** Auditorías de webhook y el estado que significa «entró y no se pudo procesar». */
    private const AUDITORIAS = [
        'pms_beds24_webhook_audit' => 'Beds24',
        'msg_meta_webhook_audit' => 'Meta',
    ];

    public function __construct(
        private readonly Connection $conexion,
        private readonly WebPushNotificationService $push,
        private readonly UserRepository $usuarios,
        private readonly RoleHierarchyInterface $jerarquia,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<string> Las líneas del informe, vacío si no hay nada que contar.
     */
    public function revisar(int $horas): array
    {
        $lineas = [];
        $fallidas = 0;

        foreach (self::AUDITORIAS as $tabla => $etiqueta) {
            $n = $this->contar(
                "SELECT COUNT(*) FROM {$tabla} WHERE status = 'partial_error' AND created_at >= (NOW() + INTERVAL :horas HOUR)",
                ['horas' => -max(1, $horas)],
            );

            if (null === $n) {
                ++$fallidas;
            } elseif ($n > 0) {
                $lineas[] = sprintf('%d webhook(s) de %s sin procesar', $n, $etiqueta);
            }
        }

        foreach (self::COLAS as $tabla => $etiqueta) {
            $n = $this->contar(
                "SELECT COUNT(*) FROM {$tabla} WHERE status = 'failed' AND updated_at >= (NOW() + INTERVAL :horas HOUR)",
                ['horas' => -max(1, $horas)],
            );

            if (null === $n) {
                ++$fallidas;
            } elseif ($n > 0) {
                $lineas[] = sprintf('%d en la cola de %s', $n, $etiqueta);
            }
        }

        $enFallo = $this->contar('SELECT COUNT(*) FROM messenger_messages WHERE queue_name = :cola', ['cola' => 'failed']);

        if (null === $enFallo) {
            ++$fallidas;
        } elseif ($enFallo > 0) {
            $lineas[] = sprintf('%d mensaje(s) en el transporte de fallos', $enFallo);
        }

        // ⚠️ Si NINGUNA consulta pudo correr, esto no es un «todo bien»: es que no se pudo mirar.
        // Callarse aquí sería reproducir el fallo que este vigilante existe para evitar — un
        // sistema que parece sano porque nadie está comprobando nada.
        if ($fallidas === count(self::AUDITORIAS) + count(self::COLAS) + 1) {
            return ['no se pudo consultar NINGUNA cola: revisa la conexión a la base'];
        }

        return $lineas;
    }

    /**
     * Manda el aviso a operaciones.
     *
     * Ni una excepción sale de aquí, por lo mismo que en el vigilante de domótica: un aviso que
     * no se puede entregar no puede tumbar la revisión que lo pidió.
     *
     * @param list<string> $lineas
     */
    public function avisar(array $lineas): void
    {
        if ([] === $lineas) {
            return;
        }

        $resumen = implode(' · ', $lineas);
        $this->logger->warning('[Colas] Hay trabajo atascado: ' . $resumen);

        try {
            $payload = [
                'title' => '⚠️ Cosas atascadas en las colas',
                'body' => $resumen . '. Revísalo en el panel antes de que se note en el calendario.',
                'actionUrl' => '/admin',
            ];

            foreach ($this->destinatarios() as $usuario) {
                $this->push->sendToUser($usuario, $payload);
            }
        } catch (Throwable $e) {
            $this->logger->error('[Colas] No se pudo avisar: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * El recuento, o `null` si la consulta no se pudo ni ejecutar.
     *
     * Distinguir «cero» de «no pude mirar» es el punto entero: devolver 0 en el fallo haría que
     * una base caída se leyera como «no hay nada atascado», que es exactamente la mentira que
     * este vigilante existe para impedir.
     *
     * @param array<string, mixed> $parametros
     */
    private function contar(string $sql, array $parametros = []): ?int
    {
        try {
            return (int) $this->conexion->fetchOne($sql, $parametros);
        } catch (Throwable $e) {
            // Una tabla que no exista —un módulo aún sin desplegar— no puede callar al resto.
            $this->logger->warning('[Colas] No se pudo consultar: ' . $e->getMessage());

            return null;
        }
    }

    /** @return list<User> */
    private function destinatarios(): array
    {
        $elegibles = [];

        foreach ($this->usuarios->findAll() as $usuario) {
            $roles = $this->jerarquia->getReachableRoleNames($usuario->getRoles());

            if (in_array(Roles::OPERACIONES_SHOW, $roles, true)) {
                $elegibles[] = $usuario;
            }
        }

        return $elegibles;
    }
}
