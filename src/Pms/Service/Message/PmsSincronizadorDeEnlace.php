<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Message\Contract\MessageContextInterface;
use App\Message\Contract\SincronizadorDeEnlaceInterface;
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

        // Cancelada: fuera el enlace si lo había, y no se crea si no lo había.
        if ($contexto->isCancelled() || $reserva->isCancelada()) {
            if ($enlace !== null) {
                $conversacion->removeEnlacePms($enlace);
                $this->em->remove($enlace);
            }

            return;
        }

        if ($enlace === null) {
            $enlace = new PmsConversacionEnlace($conversacion, $reserva);
            $conversacion->addEnlacePms($enlace);
            $this->em->persist($enlace);
        }

        $enlace->setVinculo($contexto->getVinculo());
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
        $enlace->setMilestones($contexto->getMilestones());
        $enlace->setHitos($this->hitos->para($reserva));
    }

    /**
     * El enlace de ESTA reserva en ESTE hilo, o `null`.
     *
     * Se busca en la colección ya cargada y no con una consulta: la conversación acaba de pasar
     * por la factoría, así que sus enlaces están en memoria, y una conversación tiene unos pocos
     * —no cientos—. Además así funciona con enlaces recién creados en la misma unidad de trabajo,
     * que una consulta a la base todavía no vería.
     */
    private function enlaceDe(MessageConversation $conversacion, PmsReserva $reserva): ?PmsConversacionEnlace
    {
        foreach ($conversacion->getEnlacesPms() as $enlace) {
            if ($enlace->getReserva()?->getId()?->equals($reserva->getId()) === true) {
                return $enlace;
            }
        }

        return null;
    }
}
