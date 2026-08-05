<?php

declare(strict_types=1);

namespace App\Message\Service\Inbound;

use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Traduce la respuesta numérica de un huésped ("2", "opción 3") al payload del botón
 * correspondiente del menú que se le envió.
 *
 * ¿Por qué existe? Los canales de OTA que viajan por Beds24 (Airbnb, Booking) son TEXTO PLANO:
 * no tienen botones pulsables. `Beds24SendMappingStrategy::map()` emula el menú numerando los
 * `quick_reply` de la plantilla («1️⃣ Guía», «2️⃣ Tours») y cerrando con «responde con el número
 * de tu opción». Esta clase es la otra mitad de ese contrato: deshace la numeración al volver.
 *
 * En WhatsApp Meta los botones SÍ son pulsables y llegan como `button`/`interactive` con su
 * payload, sin pasar por aquí. Este resolutor es, en la práctica, la única puerta de entrada
 * del motor determinista para las OTA.
 *
 * ⚠️ La numeración tiene que seguir siendo simétrica con la del render: ambos lados filtran
 * SOLO los `quick_reply` y los cuentan 1..N ignorando los botones de tipo `url`. Si tocas uno,
 * tocas el otro. Ver docs/Mensajeria.md §8.
 */
final readonly class InboundMenuResolver
{
    /**
     * Longitud máxima del texto para considerarlo una elección de menú.
     * Un "2" suelto sí; "2 personas y llegamos tarde" no.
     */
    private const int MAX_LONGITUD = 20;

    /**
     * Ventana de validez del menú.
     *
     * Sin ella, un huésped que contesta «2» a la pregunta «¿cuántos sois?» reviviría el menú
     * que se le mandó semanas atrás y recibiría la lista de tours. El menú caduca.
     */
    private const string VENTANA_VALIDEZ = '-24 hours';

    /** Estados en los que un mensaje llegó de verdad a la conversación. */
    private const array ESTADOS_REALES = [
        Message::STATUS_SENT,      // 'sent' — comparte valor con STATUS_DELIVERED
        Message::STATUS_READ,
        Message::STATUS_RECEIVED,
    ];

    public function __construct(
        private EntityManagerInterface $em
    ) {}

    /**
     * Devuelve el `action_code` del botón elegido, o null si el texto no es una elección de
     * menú válida en el contexto actual de la conversación.
     */
    public function resolveActionCode(MessageConversation $conversation, string $textoRecibido): ?string
    {
        $texto = trim($textoRecibido);

        if ($texto === '' || mb_strlen($texto) > self::MAX_LONGITUD) {
            return null;
        }

        if (!preg_match('/^(?:opci[oó]n|opc|opt|option|n[uú]mero|num|#)?\s*(\d{1,2})$/i', $texto, $matches)) {
            return null;
        }

        $opcionElegida = (int) $matches[1];
        if ($opcionElegida < 1) {
            return null;
        }

        $ultimo = $this->ultimoMensajeReal($conversation);
        if ($ultimo === null) {
            return null;
        }

        $template = $ultimo->getTemplate();
        if ($template === null) {
            return null;
        }

        // El menú sólo vale si fue LO ÚLTIMO que pasó en el chat. Si después hubo cualquier otro
        // mensaje, el hilo se rompió y el número del huésped ya no se refiere a estas opciones.
        $efectiva = $ultimo->getScheduledAt() ?? $ultimo->getCreatedAt();
        if ($efectiva === null || $efectiva < new \DateTimeImmutable(self::VENTANA_VALIDEZ)) {
            return null;
        }

        $quickReplies = array_values(array_filter(
            $template->getWhatsappMetaTmpl()['buttons_map'] ?? [],
            static fn ($b) => strtolower($b['type'] ?? '') === 'quick_reply'
        ));

        $elegido = $quickReplies[$opcionElegida - 1] ?? null;
        if ($elegido === null) {
            return null;
        }

        $payload = $elegido['payload'] ?? $elegido['resolver_key'] ?? null;

        return is_string($payload) && $payload !== '' ? $payload : null;
    }

    /**
     * Último mensaje que el huésped pudo ver de verdad.
     *
     * 🔥 Se ordena por `COALESCE(scheduledAt, createdAt)` y NO por `createdAt` a secas: un
     * mensaje automático se CREA cuando el motor de reglas lo programa y se ENVÍA mucho después
     * —en producción hay desfases de meses—, así que `createdAt DESC` devolvía como «último»
     * un recordatorio programado para dentro de medio año que el huésped todavía no ha recibido.
     * Ése era el motivo real de que los menús numéricos casi nunca funcionaran en las OTA.
     *
     * Es el mismo criterio de fecha efectiva que usa `RebuildConversationContextCommand`.
     */
    private function ultimoMensajeReal(MessageConversation $conversation): ?Message
    {
        return $this->em->getRepository(Message::class)
            ->createQueryBuilder('m')
            ->addSelect('COALESCE(m.scheduledAt, m.createdAt) AS HIDDEN efectiva')
            ->where('m.conversation = :conv')
            ->andWhere('m.status IN (:estados)')
            // Nada del futuro: un programado que aún no ha salido no es «lo último que vio».
            ->andWhere('COALESCE(m.scheduledAt, m.createdAt) <= :ahora')
            ->setParameter('conv', $conversation)
            ->setParameter('estados', self::ESTADOS_REALES)
            ->setParameter('ahora', new \DateTimeImmutable())
            ->orderBy('efectiva', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
