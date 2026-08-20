<?php

declare(strict_types=1);

namespace App\Message\Service\Conversacion;

use App\Message\Entity\Message;
use Psr\Log\LoggerInterface;

/**
 * De qué ASUNTO es un mensaje: se estampa cuando es deducible y se valida cuando lo dicen.
 *
 * ── El agujero que cierra ───────────────────────────────────────────────────
 * `Message::setAsunto()` tenía **un solo llamador en todo el proyecto**: el motor de reglas. Y
 * eso deja los números así en producción (20/08/2026):
 *
 * ```
 * programado por regla     asunto estampado   1011
 * programado por regla     asunto NULL          10   ← legado, anterior a la columna
 * todo lo demás            asunto NULL        4889   ← entrante, panel y agente
 * ```
 *
 * O sea que `NULL` significaba tres cosas a la vez y no había forma de distinguirlas: legado,
 * «no hay asunto» (un walk-in, y es verdad), y «sí lo hay pero nadie lo estampó». La tercera es
 * el 99 % y es la peligrosa: era inofensiva mientras cada hilo tuviera un solo asunto —el
 * respaldo acertaba porque no había con qué equivocarse— y deja de serlo justo donde se
 * fusionaron los hilos por persona.
 *
 * ── La regla, y por qué el número de asuntos la decide ──────────────────────
 * ```
 * 0 asuntos   → NULL. Es la verdad: un walk-in no tiene ninguno.
 * 1 asunto    → se ESTAMPA. Determinista, y da el mismo valor que ya calcula el respaldo:
 *               cambio neutro hoy, correcto en cuanto el hilo tenga dos.
 * 2 o más     → NULL, que ahora significa «ambiguo, que lo diga quien escribe».
 * ```
 *
 * Con eso `NULL` vuelve a ser una respuesta y no una laguna.
 *
 * ── Lo que llega de fuera se comprueba ──────────────────────────────────────
 * ⚠️ Un asunto que venga en la petición **no se cree**: se contrasta con los enlaces reales del
 * hilo. Es la misma regla que ya se aplica a los `tema_id` que elige el modelo —«lo que decide
 * otro, valídalo con código»— y aquí importa más, porque un asunto equivocado no es un texto
 * raro: es un mensaje aterrizando en la reserva de otra persona.
 *
 * Si no casa se **borra** en vez de aceptarse: sin asunto el mensaje cae al respaldo de la
 * conversación, que es el comportamiento de siempre. Aceptarlo lo mandaría a otro sitio.
 */
final readonly class AsuntoDelMensaje
{
    public function __construct(
        private EnlacesDeConversacion $enlaces,
        private LoggerInterface $logger,
    ) {
    }

    public function estampar(Message $mensaje): void
    {
        $conversacion = $mensaje->getConversation();

        if ($conversacion === null) {
            return;
        }

        $asuntos = $this->enlaces->de($conversacion);
        $pedido = $mensaje->getAsuntoType() !== null && $mensaje->getAsuntoId() !== null
            ? $mensaje->getAsuntoType() . '|' . $mensaje->getAsuntoId()
            : null;

        $llaves = [];
        foreach ($asuntos as $asunto) {
            $llaves[$asunto->getContextType() . '|' . $asunto->getContextId()] = $asunto;
        }

        if ($pedido !== null) {
            if (isset($llaves[$pedido])) {
                return;
            }

            $this->logger->warning('Asunto que no cuelga de esta conversación: se descarta.', [
                'conversacion' => (string) $conversacion->getId(),
                'pedido' => $pedido,
                'asuntos' => array_keys($llaves),
            ]);

            $mensaje->setAsunto(null, null);

            return;
        }

        // Deducible sólo cuando no hay con qué equivocarse.
        if (count($llaves) !== 1) {
            return;
        }

        $unico = reset($llaves);
        $mensaje->setAsunto($unico->getContextType(), $unico->getContextId());
    }
}
