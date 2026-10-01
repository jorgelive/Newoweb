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
 * Las noches se cuentan como en la disponibilidad —`IMPIDEN_VENTA`, solape por `DATE()` y el
 * rango EFECTIVO de cada estancia, con su noche extra—, vía
 * {@see PmsDisponibilidadService::ocupacion()}. Pero NO todo lo que ocupa frena:
 *
 * - **Una estancia de otro huésped**, siempre — también si lo que choca es sólo su entrada
 *   temprana o su salida tardía. Es un huésped, aunque su barra no llegue a esa noche.
 * - **Un bloqueo, no.** Se usa para cerrar la casita en los canales y es normal crear una estancia
 *   directa encima: lo pidió Jorge el 28/09/2026.
 *
 * Lo de la MISMA reserva no cuenta, por la misma razón que en `margenesDe()`: es su propia
 * noche extra o su otro tramo.
 *
 * ### Y lo que se comprueba es la estancia ENTERA, con sus noches extra
 *
 * Desde el 01/10/2026 la noche extra no es un evento aparte: la deriva
 * `PmsEventoCalendario::nocheExtra()` de la casilla. Así que mover una estancia con entrada
 * temprana se puede —antes el día y la casita quedaban congelados— y se valida aquí con su
 * víspera incluida, igual que marcar la casilla valida esa víspera. Ver
 * docs/PlanHorarioExtraSinEventos.md.
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
        $this->comprobar($evento, $args->getObjectManager(), cambiaLaEstancia: true);
    }

    public function preUpdate(PmsEventoCalendario $evento, PreUpdateEventArgs $args): void
    {
        $cambiaLaEstancia = false;
        foreach (['inicio', 'fin', 'pmsUnidad', 'estado'] as $campo) {
            $cambiaLaEstancia = $cambiaLaEstancia || $args->hasChangedField($campo);
        }

        if ($cambiaLaEstancia || $args->hasChangedField('entradaTemprana') || $args->hasChangedField('salidaTardia')) {
            $this->comprobar($evento, $args->getObjectManager(), $cambiaLaEstancia);
        }
    }

    /**
     * @param bool $cambiaLaEstancia Si sólo cambió una casilla, las noches de la estancia no se
     *                               vuelven a mirar: no se han movido, y un solape que bajó del
     *                               canal no tiene por qué impedir marcar un horario extra que
     *                               no choca con nada.
     */
    private function comprobar(PmsEventoCalendario $evento, object $em, bool $cambiaLaEstancia): void
    {
        if (!$this->syncContext->isUi() || !$em instanceof EntityManagerInterface) {
            return;
        }

        $estado = $evento->getEstado()?->getId();
        $inicio = $evento->getInicio();
        $fin = $evento->getFin();

        if ($estado === null || !in_array($estado, PmsEventoEstado::IMPIDEN_VENTA, true)
            || $evento->getPmsUnidad() === null || $inicio === null || $fin === null
            || $fin->format('Y-m-d') <= $inicio->format('Y-m-d')) {
            return;
        }

        if ($cambiaLaEstancia) {
            $this->frenarSiOcupada($evento, $inicio, $fin, 'Libera esas noches o elige otra casita antes de guardar.');
        }

        foreach ($evento->nochesExtra() as $noche) {
            $this->frenarSiOcupada($evento, $noche->desde, $noche->hasta, sprintf(
                'La %s ocupa también esa noche: elige otra fecha o casita, o quita la casilla.',
                mb_strtolower($noche->etiqueta())
            ));
        }
    }

    private function frenarSiOcupada(
        PmsEventoCalendario $evento,
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

            // Un bloqueo no frena (ver la cabecera).
            if (!$otro->esEstancia) {
                continue;
            }

            [$entra, $sale] = $otro->nochesOcupadas();

            throw new DomainException(sprintf(
                '%s ya está ocupada del %s al %s por %s. %s',
                $unidad->getNombre(),
                (new \DateTimeImmutable($entra))->format('d/m'),
                (new \DateTimeImmutable($sale))->format('d/m'),
                $otro->quienOcupa(),
                $queHacer,
            ));
        }
    }
}
