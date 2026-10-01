<?php

declare(strict_types=1);

namespace App\Pms\Service\Reserva;

use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoCalendario;

/**
 * Los links extra de una estancia: la `black` que bloquea en Beds24 cada noche de horario extra.
 *
 * Sustituye a `PmsExtensionEstanciaService` (fase 4 de docs/PlanHorarioExtraSinEventos.md). Aquel
 * mantenía un EVENTO hermano por noche y había que recolocarlo cada vez que la estancia se movía;
 * éste sólo asegura que existan los links, y **no guarda ninguna fecha**: la noche la calcula
 * `PmsEventoCalendario::nocheExtra()` y el push la lee en el momento de enviar.
 *
 * ### Lo que hace
 *
 * Por cada rol (`extra_entrada`, `extra_salida`) con su noche activa, un link por cada mapa activo
 * de la casita — igual que la estancia tiene uno por establecimiento virtual: la noche tiene que
 * estar cerrada en los dos listings, o el gemelo la vendería.
 *
 * Reparte como `PmsEventoCalendarioFactory::internalHydrate()` reparte los espejos: un link que ya
 * existe se reutiliza para el mapa de su MISMO establecimiento virtual, conservando su `bookId`.
 * Mover la estancia de casita es entonces un UPDATE de habitación en Beds24, no una reserva nueva.
 *
 * ### Lo que NO hace, y es deliberado
 *
 * - **No borra ningún link que haya llegado a Beds24.** Si la noche se apaga (casilla desmarcada,
 *   estancia cancelada) o el link se queda en una casita que ya no es la de la estancia, el link
 *   sigue ahí y `PmsEventoBeds24Link::nocheQueBloquea()` dice `null`: el push manda su `black`
 *   como `cancelled`. Volver a marcar la casilla la revive con el mismo `bookId`. Beds24 no deja
 *   borrar reservas activas, y un DELETE perdido deja la noche cerrada para siempre.
 * - **No crea links con la noche apagada.** Uno que nunca salió (sin `bookId`) y cuya noche se
 *   apagó tampoco se borra: se queda y la cola no manda nada por él
 *   (`Beds24BookingsPushQueueCreator::enqueueForLink()`).
 * - **No hace flush.** Lo hace quien llama.
 */
final readonly class NochesExtraDeEstancia
{
    private const array ROLES = [PmsEventoBeds24Link::ROL_EXTRA_ENTRADA, PmsEventoBeds24Link::ROL_EXTRA_SALIDA];

    public function sincronizar(PmsEventoCalendario $estancia): void
    {
        $mapas = $estancia->getPmsUnidad()?->getBeds24MapsActivos() ?? [];

        foreach (self::ROLES as $rol) {
            if ($mapas === [] || $estancia->nocheExtra($rol) === null) {
                continue;
            }

            $porVirtual = [];
            $sinVirtual = [];
            foreach ($estancia->getBeds24Links() as $link) {
                if ($link->getRol() !== $rol) {
                    continue;
                }

                $codigo = $link->getUnidadBeds24Map()?->getVirtualEstablecimiento()?->getCodigo();
                if ($codigo !== null && !isset($porVirtual[$codigo])) {
                    $porVirtual[$codigo] = $link;
                } else {
                    $sinVirtual[] = $link;
                }
            }

            foreach ($mapas as $mapa) {
                $codigo = $mapa->getVirtualEstablecimiento()?->getCodigo();
                $link = null;

                if ($codigo !== null && isset($porVirtual[$codigo])) {
                    $link = $porVirtual[$codigo];
                    unset($porVirtual[$codigo]);
                } else {
                    // Sólo se recicla uno que nunca llegó a Beds24: uno con `bookId` llevado a
                    // otro establecimiento virtual dejaría su `black` viva allí sin dueño.
                    foreach ($sinVirtual as $i => $candidato) {
                        if ($candidato->getBeds24BookId() === null) {
                            $link = $candidato;
                            unset($sinVirtual[$i]);
                            break;
                        }
                    }
                }

                if ($link === null) {
                    $link = (new PmsEventoBeds24Link())->setRol($rol);
                    $estancia->addBeds24Link($link);
                }

                $link->setUnidadBeds24Map($mapa);
                $link->markActive();
            }
        }
    }
}
