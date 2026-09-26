<?php

declare(strict_types=1);

namespace App\Message\Service\Meta\Webhook;

use App\Exchange\Entity\MetaConfig;
use App\Exchange\Service\Context\SyncContext;
use App\Message\Dto\Meta\MetaContacto;
use App\Message\Dto\Meta\MetaEstado;
use App\Message\Dto\Meta\MetaLlamada;
use App\Message\Dto\Meta\MetaMensajeEntrante;
use App\Message\Dto\Meta\MetaWebhookSobre;
use App\Message\Service\Exchange\Tasks\WhatsappMetaReceive\WhatsappMetaReceivePersister;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Throwable;

/**
 * Lo que entra por el webhook de Meta: mensajes del huésped, estados de los nuestros y llamadas.
 *
 * Cada pieza va en su propia transacción: un mensaje que falla no se lleva por delante a los demás
 * del mismo sobre, y el error queda en la lista que devuelve {@see self::procesarSobre()}.
 */
final readonly class WhatsappMetaWebhookMessageFastTrackService
{
    public function __construct(
        private EntityManagerInterface       $em,
        private WhatsappMetaReceivePersister $persister,
        private SyncContext                  $syncContext
    )
    {
    }

    /**
     * Recorre el sobre entero y procesa cada pieza. El ÚNICO recorrido del sobre.
     *
     * 🔥 **Estaba copiado en dos sitios**: el webhook (`MetaWebhookController`) y el «reprocesar» del
     * panel de auditoría (`MetaWebhookAuditCrudController`), con un comentario en el segundo que ya
     * pedía traerlo aquí. Dos copias de un recorrido son dos sitios donde un cambio de formato de
     * Meta se arregla una vez y se olvida la otra.
     *
     * ⚠️ **Un mensaje sin contacto es un error, no se salta.** El recorrido anterior sólo miraba los
     * mensajes si venía `contacts`, así que uno sin contacto desaparecía sin rastro. Meta los manda
     * siempre juntos —0 de 456 mensajes reales sin contacto—, pero si un día no, el huésped escribió
     * y tiene que verse.
     *
     * @return array{responseDetails: array<string, list<string>>, globalErrors: list<array{type: string, id: string, error: string}>, processedAny: bool}
     */
    public function procesarSobre(MetaWebhookSobre $sobre): array
    {
        $detalles = [];
        $errores = [];
        $procesado = false;

        foreach ($sobre->cambios as $cambio) {
            foreach ($cambio->mensajes as $mensaje) {
                $procesado = true;
                try {
                    if ($cambio->contacto === null) {
                        throw new RuntimeException('Mensaje de Meta sin contacto: no se sabe quién lo escribió.');
                    }
                    $detalles['messages'][] = $this->processMessage($mensaje, $cambio->contacto)['id'];
                } catch (Throwable $e) {
                    $errores[] = ['type' => 'message', 'id' => $mensaje->id ?? 'unknown', 'error' => $e->getMessage()];
                }
            }

            foreach ($cambio->llamadas as $llamada) {
                $procesado = true;
                try {
                    $detalles['calls'][] = $this->processCall($llamada, $cambio->contacto)['id'];
                } catch (Throwable $e) {
                    $errores[] = ['type' => 'call', 'id' => $llamada->id ?? 'unknown', 'error' => $e->getMessage()];
                }
            }

            foreach ($cambio->estados as $estado) {
                $procesado = true;
                try {
                    $detalles['statuses'][] = $this->processStatus($estado)['id'];
                } catch (Throwable $e) {
                    $errores[] = ['type' => 'status', 'id' => $estado->id ?? 'unknown', 'error' => $e->getMessage()];
                }
            }
        }

        return ['responseDetails' => $detalles, 'globalErrors' => $errores, 'processedAny' => $procesado];
    }

    /**
     * Procesa UN solo mensaje entrante de un huésped.
     *
     * @return array{success: true, id: string}
     * @throws Throwable
     */
    public function processMessage(MetaMensajeEntrante $mensaje, MetaContacto $contacto): array
    {
        $this->validateConfig();
        $scope = $this->syncContext->enter(SyncContext::MODE_PULL, 'meta');
        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            // Delegamos al Persister Agnóstico
            $this->persister->upsertInboundMessage($mensaje, $contacto);
            $this->em->flush();
            $conn->commit();
            return ['success' => true, 'id' => $mensaje->id ?? 'unknown'];
        } catch (Throwable $e) {
            $conn->rollBack();
            throw $e;
        } finally {
            $scope->restore();
        }
    }

    /**
     * Valida que exista una configuración activa de Meta antes de procesar.
     */
    private function validateConfig(): void
    {
        $config = $this->em->getRepository(MetaConfig::class)->findOneBy(['activo' => true]);
        if (!$config instanceof MetaConfig) {
            throw new RuntimeException("No se encontró una configuración activa de Meta WhatsApp.");
        }
    }

    /**
     * Procesa UN solo cambio de estado (Enviado, Entregado, Leído, Fallido).
     *
     * @return array{success: true, id: string}
     * @throws Throwable
     */
    public function processStatus(MetaEstado $estado): array
    {
        $this->validateConfig();
        $scope = $this->syncContext->enter(SyncContext::MODE_PULL, 'meta');
        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            // Delegamos al Persister Agnóstico
            $this->persister->updateMessageStatus($estado);
            $this->em->flush();
            $conn->commit();
            return ['success' => true, 'id' => $estado->id ?? 'unknown'];
        } catch (Throwable $e) {
            $conn->rollBack();
            throw $e;
        } finally {
            $scope->restore();
        }
    }

    /**
     * Procesa un intento de llamada (Call Event).
     *
     * @return array{success: true, id: string}
     * @throws Throwable
     */
    public function processCall(MetaLlamada $llamada, ?MetaContacto $contacto): array
    {
        $this->validateConfig();
        $scope = $this->syncContext->enter(SyncContext::MODE_PULL, 'meta');
        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $this->persister->processCall($llamada, $contacto);
            $this->em->flush();
            $conn->commit();
            return ['success' => true, 'id' => $llamada->id ?? 'unknown'];
        } catch (Throwable $e) {
            $conn->rollBack();
            throw $e;
        } finally {
            $scope->restore();
        }
    }
}
