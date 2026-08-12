<?php

declare(strict_types=1);

namespace App\Message\DispatchHandler;

use App\Message\Dispatch\AvisarEnvioFallidoDispatch;
use App\Message\Entity\Message;
use App\Message\Service\Push\NotificadorPushConversacion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Un envío fallido se lo dice a quien lo escribió, y cuando el motivo es la ventana de 24 h se
 * lo dice con la salida delante.
 *
 * ── Lo que NO hace, y por qué ───────────────────────────────────────────────
 * Hubo una versión de esto que mandaba sola una plantilla «le hemos escrito, conteste» para
 * reabrir la ventana. Se descartó por decisión del dueño del producto, y el argumento es bueno:
 * si hay que gastar una plantilla —fuera de la ventana Meta las cobra— más vale que sea una que
 * el huésped agradezca. La guía, el menú de tours o el recordatorio de llegada abren
 * conversación igual de bien y además le sirven de algo; un «alguien quiere hablarte» no.
 *
 * Cuál toca no lo puede saber el sistema: depende de para qué le escribías. Así que el aviso
 * lleva la instrucción y la decisión se queda donde estaba, en la persona.
 *
 * Vive en un worker y no en el listener a propósito: mandar push es I/O de red y el listener
 * corre dentro de un flush de Doctrine.
 */
#[AsMessageHandler]
final readonly class AvisarEnvioFallidoDispatchHandler
{
    /**
     * Trozo del motivo que delata el caso. Sale de `WhatsappMetaSendEnqueuer`, que lo escribe en
     * dos frases distintas —con plantilla no oficial y sin plantilla— y las dos lo contienen.
     */
    private const string SEÑAL_VENTANA = 'ventana de 24 horas';

    public function __construct(
        private EntityManagerInterface $em,
        private NotificadorPushConversacion $notificador
    ) {}

    public function __invoke(AvisarEnvioFallidoDispatch $dispatch): void
    {
        $mensaje = $this->em->getRepository(Message::class)->find($dispatch->messageId);

        if (!$mensaje instanceof Message) {
            return;
        }

        // El worker es de vida larga y pudo cachear el mensaje de un trabajo anterior; además
        // entre la detección y este momento pudo reintentarse y salir.
        $this->em->refresh($mensaje);

        if ($mensaje->getStatus() !== Message::STATUS_FAILED) {
            return;
        }

        $this->notificador->avisarEnvioFallido($mensaje, $this->esPorLaVentana($mensaje));
    }

    /**
     * ¿Falló porque la ventana de 24 h estaba cerrada?
     *
     * Se mira el motivo que guardó `MessageDispatcher`, no el canal: el mismo mensaje puede
     * haber salido por Beds24 y haber fallado sólo en WhatsApp, y ese caso también cuenta —el
     * huésped de una OTA que además tiene WhatsApp merece enterarse por los dos sitios.
     */
    private function esPorLaVentana(Message $mensaje): bool
    {
        $metadata = $mensaje->getMetadata();
        $motivos = array_merge(
            is_array($metadata['dispatch_errors'] ?? null) ? $metadata['dispatch_errors'] : [],
            is_array($metadata['dispatch_partial_errors'] ?? null) ? $metadata['dispatch_partial_errors'] : []
        );

        foreach ($motivos as $motivo) {
            if (str_contains(mb_strtolower((string) $motivo), self::SEÑAL_VENTANA)) {
                return true;
            }
        }

        return false;
    }
}
