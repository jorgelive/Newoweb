<?php

declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Exchange\Service\Context\SyncContext;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Service\Reserva\PmsDisponibilidadService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use DomainException;

/**
 * Nadie ocupa una casita que ya está ocupada: ni el calendario, ni el panel, ni el agente.
 *
 * 🔥 Caso casita 4, noche del 27/09/2026. Lizbeth llegaba a las 7 de la mañana del 28 y su
 * entrada temprana bloqueaba la noche anterior (HJNNWM, desde el 10/09). El 26/09 se creó
 * desde el calendario la noche extra de José (55XPMS) encima, sin ningún aviso: el bloqueo de
 * una entrada temprana no se pinta como una barra, es una marca pequeña en la estancia de al
 * lado. Susan lo resolvió mandando a Lizbeth primero a la casita 1.
 *
 * Beds24 sí lo habría rechazado por el canal, pero una reserva creada por la API no pasa por
 * esa comprobación: la única barrera tiene que estar aquí.
 *
 * ### Qué cuenta como ocupado
 *
 * Las noches se cuentan como en la disponibilidad —`IMPIDEN_VENTA` y solape por `DATE()`, vía
 * {@see PmsDisponibilidadService::ocupacion()}—, pero NO todo lo que ocupa frena:
 *
 * - **Una estancia de otro huésped**, siempre.
 * - **La noche de una entrada temprana o una salida tardía ya negociada**: un bloqueo o una
 *   extensión que cuelga de su estancia (`eventoOrigen`). Es un huésped, aunque no se vea.
 * - **Un bloqueo suelto, no.** Se usa para cerrar la casita en los canales y es normal crear
 *   una estancia directa encima: lo pidió Jorge el 28/09/2026.
 *
 * Lo de la MISMA reserva no cuenta, por la misma razón que en `margenesDe()`: son su propio
 * evento, sus extensiones o su otro tramo.
 *
 * ### Sólo lo que decide una persona
 *
 * Se comprueba con el contexto en UI (panel, calendario, agente). Lo que baja de Beds24 es
 * la verdad del canal: si llega un solape, ya ha pasado, y rechazarlo rompería la
 * sincronización sin deshacerlo. Tampoco en el push, que sólo refleja lo ya guardado.
 */
#[AsEntityListener(event: Events::prePersist, method: 'prePersist', entity: PmsEventoCalendario::class)]
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: PmsEventoCalendario::class)]
final class PmsEventoCalendarioSolapeListener
{
    public function __construct(
        private readonly SyncContext $syncContext,
        private readonly PmsDisponibilidadService $disponibilidad,
    ) {}

    public function prePersist(PmsEventoCalendario $evento, PrePersistEventArgs $args): void
    {
        $this->comprobar($evento, $args->getObjectManager());
    }

    public function preUpdate(PmsEventoCalendario $evento, PreUpdateEventArgs $args): void
    {
        $em = $args->getObjectManager();

        foreach (['inicio', 'fin', 'pmsUnidad', 'estado'] as $campo) {
            if ($args->hasChangedField($campo)) {
                $this->comprobar($evento, $em);
                break;
            }
        }

        // ⏰ La noche del horario extra se comprueba AQUÍ, al marcar la casilla, y no cuando nace
        // su evento.
        //
        // Ese evento lo crea `PmsExtensionEstanciaService` en el `postFlush` de la estancia: para
        // entonces la casilla YA está guardada. Frenarlo allí dejaba la estancia marcada con
        // entrada temprana y sin noche bloqueada ni en el PMS ni en Beds24 — peor que el solape
        // que se quería evitar, porque la marca dice que está protegida. Aquí el guardado entero
        // se rechaza y no queda nada a medias.
        $reactivada = $args->hasChangedField('estado');

        if (($args->hasChangedField('entradaTemprana') || $reactivada) && $evento->isEntradaTemprana()) {
            $this->comprobarNocheExtra($evento, $em, esEntrada: true);
        }

        if (($args->hasChangedField('salidaTardia') || $reactivada) && $evento->isSalidaTardia()) {
            $this->comprobarNocheExtra($evento, $em, esEntrada: false);
        }
    }

    private function comprobar(PmsEventoCalendario $evento, object $em): void
    {
        // Una extensión no se comprueba por su cuenta: su noche se validó al marcar la casilla
        // de su estancia (ver `preUpdate`). Hacerlo aquí sería hacerlo en `postFlush`, tarde.
        if (!$this->syncContext->isUi() || !$em instanceof EntityManagerInterface || $evento->esExtension()) {
            return;
        }

        $estado = $evento->getEstado()?->getId();
        $unidad = $evento->getPmsUnidad();
        $inicio = $evento->getInicio();
        $fin = $evento->getFin();

        if ($estado === null || !in_array($estado, PmsEventoEstado::IMPIDEN_VENTA, true)
            || $unidad === null || $inicio === null || $fin === null
            || $fin->format('Y-m-d') <= $inicio->format('Y-m-d')) {
            return;
        }

        $this->frenarSiOcupada($evento, $em, $inicio, $fin, 'Libera esas noches o elige otra casita antes de guardar.');
    }

    /**
     * La noche que bloquearía la entrada temprana (la víspera) o la salida tardía (la del día de
     * salida): si ya es de otro huésped, la casilla no se puede marcar.
     */
    private function comprobarNocheExtra(PmsEventoCalendario $evento, object $em, bool $esEntrada): void
    {
        $estado = $evento->getEstado()?->getId();
        $borde = $esEntrada ? $evento->getInicio() : $evento->getFin();

        // Una estancia cancelada no bloquea nada: su extensión se retira, no se crea.
        if (!$this->syncContext->isUi() || !$em instanceof EntityManagerInterface || $borde === null
            || $evento->getPmsUnidad() === null || $estado === PmsEventoEstado::CODIGO_CANCELADA) {
            return;
        }

        $dia = \DateTimeImmutable::createFromInterface($borde)->setTime(0, 0);
        [$desde, $hasta] = $esEntrada ? [$dia->modify('-1 day'), $dia] : [$dia, $dia->modify('+1 day')];

        $this->frenarSiOcupada($evento, $em, $desde, $hasta, sprintf(
            'No se puede marcar la %s: esa noche no está libre.',
            $esEntrada ? 'entrada temprana' : 'salida tardía'
        ));
    }

    private function frenarSiOcupada(
        PmsEventoCalendario $evento,
        EntityManagerInterface $em,
        \DateTimeInterface $desde,
        \DateTimeInterface $hasta,
        string $queHacer,
    ): void {
        $unidad = $evento->getPmsUnidad();

        if ($unidad === null) {
            return;
        }

        $propioId = (string) $evento->getId();
        $reservaId = $evento->getReserva()?->getId() !== null ? (string) $evento->getReserva()->getId() : null;

        foreach ($this->disponibilidad->ocupacion($desde, $hasta, (string) $unidad->getId()) as $otro) {
            if ($otro->eventoId === $propioId || ($reservaId !== null && $otro->reservaId === $reservaId)) {
                continue;
            }

            if ($otro->esEstancia) {
                $quien = $otro->huesped ?? 'otra estancia';
            } else {
                // Bloqueo o extensión: sólo frena si es el horario extra de una estancia.
                $origen = $em->find(PmsEventoCalendario::class, $otro->eventoId)?->getEventoOrigen();

                if ($origen === null) {
                    continue;
                }

                $quien = sprintf(
                    'la entrada temprana o salida tardía de %s',
                    $origen->getTituloCache() ?? $otro->huesped ?? 'otro huésped'
                );
            }

            throw new DomainException(sprintf(
                '%s ya está ocupada del %s al %s por %s. %s',
                $unidad->getNombre(),
                (new \DateTimeImmutable($otro->entra))->format('d/m'),
                (new \DateTimeImmutable($otro->sale))->format('d/m'),
                $quien,
                $queHacer,
            ));
        }
    }
}
