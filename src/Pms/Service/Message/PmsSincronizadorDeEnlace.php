<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Contract\ConversationMilestoneInterface;
use App\Message\Contract\MessageContextInterface;
use App\Message\Contract\SincronizadorDeEnlaceInterface;
use App\Contract\VinculoComercial;
use App\Message\Entity\MessageConversation;
use App\Pms\Entity\PmsConversacionEnlace;
use App\Pms\Entity\PmsReserva;
use Doctrine\ORM\EntityManagerInterface;

/**
 * El enlace de alojamiento, siempre al día con su reserva.
 *
 * Corre en el mismo sitio donde ya se refrescaba el `contextData` de la conversación —cada
 * cambio de reserva pasa por `PmsReservaRecalculoService` → `upsertFromContext()`—, así que un
 * enlace no puede quedarse atrás sin que también se quedara atrás la conversación.
 *
 * ── Los hitos se RECALCULAN, no se copian ───────────────────────────────────
 * Es la diferencia con el poblado inicial, que copiaba el JSON de la conversación tal cual. Aquí
 * se derivan de los tramos con {@see PmsHitosDeEstancia}, así que un evento que se mueve, se
 * añade o **se borra** cambia los hitos en el acto: aparece o desaparece la salida temporal, el
 * reingreso o el cambio de casita. Copiarlos habría heredado el defecto de origen —`start` y
 * `end` son el mínimo y el máximo de todos los tramos— justo cuando el objetivo es dejar de
 * aplastar la estancia en dos fechas.
 *
 * ── Qué pasa cuando la reserva se cancela ───────────────────────────────────
 * El enlace **se retira**. No hay nada que atender ni nada que programar, y dejarlo vivo es la
 * puerta por la que un asunto muerto vuelve a generar mensajes: de las 310 conversaciones de
 * alojamiento, 106 cuelgan de una reserva cancelada, así que no es un caso de laboratorio.
 * El motor tiene su propia barrera para asuntos muertos; ésta es la de antes, en el origen.
 */
final readonly class PmsSincronizadorDeEnlace implements SincronizadorDeEnlaceInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private PmsHitosDeEstancia $hitos,
        private PmsProveedorDeEnlaces $proveedor,
    ) {}

    public function supports(?string $contextType): bool
    {
        return $contextType === PmsConversacionEnlace::CONTEXT_TYPE;
    }

    public function sincronizar(MessageConversation $conversacion, MessageContextInterface $contexto): void
    {
        $reserva = $this->em->getRepository(PmsReserva::class)->find($contexto->getContextId());

        if ($reserva === null) {
            return;
        }

        $enlace = $this->enlaceDe($conversacion, $reserva);
        $cancelada = $contexto->isCancelled() || $reserva->isCancelada();

        // ── Cancelada: se MARCA, no se borra ────────────────────────────────
        // La primera versión lo borraba, y era un error con dos caras:
        //
        //  1. Se perdía el rastro. Un asunto cancelado sigue siendo parte de la historia de esa
        //     persona —qué reservó, qué pasó— y borrarlo deja el hilo contando una versión
        //     incompleta de lo que ocurrió.
        //  2. Y se cerraba la puerta a avisar de la cancelación. `AgendaDeAsunto::estaMuerta()`
        //     lee el hito `cancelled_at` **del enlace**: sin enlace no hay hito, así que esa
        //     rama era código muerto de hecho y el aviso quedaba mudo para siempre, no por
        //     decisión sino por accidente.
        //
        // Marcado, el asunto sigue muerto para la mensajería automática —la guarda del motor no
        // cambia— pero **el dato existe**, que es lo que hace falta para que producto pueda
        // decidir después si el aviso vuelve y con qué condiciones.
        if ($cancelada && $enlace === null) {
            $enlace = new PmsConversacionEnlace($conversacion, $reserva);
            $this->em->persist($enlace);
        }

        if ($enlace === null) {
            $enlace = new PmsConversacionEnlace($conversacion, $reserva);
            $this->em->persist($enlace);
        }

        $enlace->setVinculo($cancelada ? VinculoComercial::Terminado : $contexto->getVinculo());
        $enlace->setOrigen($contexto->getOrigin());
        $enlace->setAgencia($contexto->getAgencyId());
        $enlace->setStatusTag($contexto->getStatusTag());

        // ⚠️ El mapa COMPLETO primero, y los hitos derivados encima.
        //
        // El contexto trae hitos que no salen de los tramos —`created_at` (cuándo se reservó) y
        // `expected_arrival` (a qué hora dijo el huésped que llega)— y hay reglas colgadas de los
        // dos: la bienvenida y el aviso previo a la llegada. Poner sólo los derivados dejaba
        // esas dos claves ausentes, y una regla sin su hito **no se programa y no avisa de
        // nada**: toda reserva nueva se habría quedado sin bienvenida, en silencio.
        //
        // Después `setHitos()` reescribe `start` y `end` con los derivados de los tramos, que
        // son mejores que los del contexto: éstos son el mínimo y el máximo de la reserva, y
        // aquéllos distinguen la estancia partida.
        // ── ¿Se cayó algo por el camino? ────────────────────────────────────
        // Se compara lo que cubría el enlace ANTES con lo que cubre ahora. Si desaparece una
        // casita y quedan otras, la reserva no está cancelada —el huésped sigue viniendo— pero
        // ha perdido algo concreto, y eso hay que contárselo: es el caso que hasta ahora era
        // invisible, porque los hitos se recalculaban en silencio y nadie avisaba.
        //
        // Si NO queda ninguna, es una cancelación total y la trata la rama de arriba: ahí no
        // hace falta ser específico, basta un aviso genérico.
        $antes = $enlace->unidadesDerivadas();

        $enlace->setMilestones($contexto->getMilestones());
        $enlace->setHitos($this->hitos->para($reserva));

        $ahora = $enlace->unidadesDerivadas();
        $perdidas = array_values(array_diff($antes, $ahora));

        if ($perdidas !== [] && $ahora !== []) {
            $enlace->anotarCancelacionParcial(implode(' + ', $perdidas));
        }

        // El hito de cancelación se pone DESPUÉS de los derivados, porque `setHitos()` reescribe
        // `start`/`end` y no toca las claves ajenas. Con la reserva cancelada, los hitos
        // derivados vienen vacíos —no hay tramos vivos— y lo único que queda es este.
        if ($cancelada) {
            $enlace->marcarCancelado($contexto->getMilestones()->obtener(ConversationMilestoneInterface::CANCELLED));
        }
    }

    /**
     * El enlace de ESTA reserva en ESTE hilo, o `null`.
     *
     * Lo resuelve el proveedor del dominio, que junta lo guardado con lo que la unidad de
     * trabajo tiene pendiente — hace falta lo segundo porque un enlace recién creado en esta
     * misma petición no lo vería una consulta, y sin eso se crearía un duplicado.
     */
    private function enlaceDe(MessageConversation $conversacion, PmsReserva $reserva): ?PmsConversacionEnlace
    {
        return $this->proveedor->paraReserva($conversacion, $reserva);
    }
}
