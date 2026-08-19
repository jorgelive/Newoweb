<?php

declare(strict_types=1);

namespace App\Message\Service\Queue;

use App\Message\Contract\ConversacionEnlaceInterface;
use App\Contract\ConversationMilestoneInterface;
use App\Contract\VinculoComercial;
use App\Message\Entity\MessageConversation;

/**
 * Una AGENDA de envíos: todo lo que el motor de reglas necesita de UN asunto para programar.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * `MessageRuleEngine` leía los hitos, el origen y la agencia directamente de la conversación,
 * y por eso cada reserva necesitaba la suya (docs/Mensajeria.md §20). Para programar por
 * asunto, el motor tiene que poder iterar N agendas sobre el mismo hilo — pero durante la
 * transición conviven dos fuentes de esos datos:
 *
 *   - el JSON `contextData` de la conversación (la era anterior, hoy la mayoría), y
 *   - los enlaces persistidos ({@see ConversacionEnlaceInterface}).
 *
 * Este objeto es el adaptador que las iguala: el motor programa contra agendas y no sabe —ni
 * debe saber— de cuál de las dos eras salió cada una. La alternativa era ramificar cada método
 * del motor con «¿tiene enlaces?», y ése es exactamente el tipo de doble criterio que ya se
 * desincronizó una vez con la segmentación (ver `MessageRule::matchesSegmentation()`).
 *
 * ── Legado vs. enlace: la única diferencia que el motor sí necesita ─────────
 * `esLegado` no es un detalle de implementación: la barrera de estado se decide distinto.
 * En la era anterior, «la reserva se canceló» y «la conversación se cerró» eran el mismo hecho
 * (el factory cierra el hilo al cancelarse la reserva). Con varios asuntos por hilo dejan de
 * serlo: cancelar UNA reserva no puede apagar las agendas de las demás ni exigir que el hilo
 * de la persona esté cerrado. Ver `MessageRuleEngine::ruleAppliesToAgenda()`.
 */
final readonly class AgendaDeAsunto
{
    /**
     * @param array<string, string> $milestones Claves de {@see ConversationMilestoneInterface}.
     */
    private function __construct(
        public string $contextType,
        public string $contextId,
        public array $milestones,
        public ?string $origen,
        public ?string $agencia,
        public bool $esLegado,
        public bool $esNueva,
        public ?VinculoComercial $vinculo,
    ) {}

    /**
     * La agenda de la era anterior: los datos aplastados en el JSON de la conversación.
     *
     * `esNueva` es siempre `false` a propósito: en este modo la novedad la sigue diciendo el
     * trigger `INSERT` (una conversación nueva ES un asunto nuevo), exactamente como hoy.
     */
    public static function deConversacion(MessageConversation $conversacion): self
    {
        return new self(
            contextType: $conversacion->getContextType(),
            contextId: $conversacion->getContextId(),
            milestones: $conversacion->getContextMilestones(),
            origen: $conversacion->getContextOrigin(),
            agencia: $conversacion->getContextAgency(),
            esLegado: true,
            esNueva: false,
            // El motor de la era anterior nunca miró el vínculo, y una agenda legada tiene que
            // comportarse EXACTAMENTE como él: `null` aquí es «no participa», no «no lo sé».
            vinculo: null,
        );
    }

    /**
     * La agenda de un asunto persistido.
     *
     * `esNueva` sólo cuando el enlace aún no se flusheó (`createdAt === null`): es la señal de
     * «reserva de última hora» cuando el hilo ya existía y el trigger llega como `UPDATE`.
     * Deliberadamente NO es «creado hace poco»: un poblado masivo flushea antes de que el motor
     * corra, así que sus enlaces nunca disparan rescates ni bienvenidas retroactivas.
     */
    public static function deEnlace(ConversacionEnlaceInterface $enlace): self
    {
        return new self(
            contextType: $enlace->getContextType(),
            contextId: $enlace->getContextId(),
            milestones: $enlace->getMilestones(),
            origen: $enlace->getOrigen(),
            agencia: $enlace->getAgencia(),
            esLegado: false,
            esNueva: $enlace->getCreatedAt() === null,
            vinculo: $enlace->getVinculo(),
        );
    }

    /**
     * ¿El asunto está cancelado? Sólo tiene sentido preguntárselo a una agenda de enlace: en
     * las legadas la cancelación viaja en el ESTADO de la conversación, no en el hito.
     */
    public function estaCancelada(): bool
    {
        return ($this->milestones[ConversationMilestoneInterface::CANCELLED] ?? '') !== '';
    }

    /**
     * ¿El asunto está MUERTO para la mensajería automática? Cancelado, o con el vínculo ya
     * terminado. La regla es del dueño del producto y es categórica: «cuando regenere, el
     * engine que no se muera» — un asunto muerto no encola NI UN mensaje, porque la base trae
     * asuntos basura (filas canceladas duplicadas por un bug de guardado) y regenerar sobre
     * ellos significaba una tanda de envíos reales por cada duplicado.
     */
    public function estaMuerta(): bool
    {
        return $this->estaCancelada() || $this->vinculo === VinculoComercial::Terminado;
    }

    /** La llave con la que un mensaje encolado se reconoce como de este asunto. */
    public function llave(): string
    {
        return $this->contextType . '|' . $this->contextId;
    }
}
