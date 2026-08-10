<?php

declare(strict_types=1);

namespace App\Pms\Service\Reserva;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsReserva;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Qué hay ANTES y DESPUÉS de una estancia en su misma casita.
 *
 * Responde las dos preguntas que deciden si cabe algo de flexibilidad en la hora de entrada o
 * de salida, y que el agente no tenía forma de saber:
 *
 * - ¿La casita está libre la noche anterior a su llegada, o hay alguien saliendo ese día?
 * - ¿Entra alguien el día que él se va?
 *
 * Nace del caso de Alejandra Rodríguez (docs/Mensajeria.md §17.1): avisó de que llegaba a las
 * 12 con el check-in a las 14:00 y el agente contestó «te esperamos a esa hora». Acertó de
 * casualidad —la salida de ese día estaba cancelada y la casita llevaba dos días vacía—, pero
 * respondió sin ningún dato.
 *
 * ### Lo que NO puede responder, y por qué importa
 *
 * **Si el huésped anterior ya se fue de verdad, y a qué hora.** No existe en el sistema:
 * `PmsEventoCalendario` guarda el `fin` PREVISTO y un booleano `salidaTardia`, pero no hay
 * registro de la salida real. Tampoco de si la limpieza terminó: `pms_event_assignment` dice
 * quién tiene asignada la actividad, sin fecha ni estado.
 *
 * Por eso lo que sale de aquí sirve para **descartar** («hoy hay alguien saliendo, ni lo
 * plantees») y para **matizar** («está libre, pero la limpieza puede estar en marcha»), nunca
 * para conceder. La decisión sigue siendo de una persona.
 *
 * ### Se cuenta como ocupación lo mismo que en el calendario
 *
 * `PmsEventoEstado::IMPIDEN_VENTA` es la fuente única: canceladas y consultas de Airbnb no
 * ocupan. Reimplementar el criterio aquí habría hecho que el agente y el calendario contaran
 * noches distintas — y la que se equivoca siempre es la que nadie mira.
 */
final readonly class PmsEspacioEstancia
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    /**
     * @return array{
     *     libre_la_vispera: bool,
     *     sale_alguien_el_dia_que_llega: string|null,
     *     entra_alguien_el_dia_que_se_va: string|null,
     *     desde_cuando_libre: string|null
     * }|null  `null` si la reserva no tiene estancia con fechas.
     */
    public function alrededorDe(PmsReserva $reserva): ?array
    {
        $propio = $this->estanciaPrincipal($reserva);

        if ($propio === null) {
            return null;
        }

        $unidad = $propio->getPmsUnidad();
        $inicio = $propio->getInicio();
        $fin = $propio->getFin();

        if ($unidad === null || $inicio === null || $fin === null) {
            return null;
        }

        $vecinos = $this->em->createQueryBuilder()
            ->select('e')
            ->from(PmsEventoCalendario::class, 'e')
            ->join('e.estado', 'es')
            ->where('e.pmsUnidad = :unidad')
            ->andWhere('e.id != :propio')
            ->andWhere('es.id IN (:ocupan)')
            ->andWhere('e.fin >= :desde')
            ->andWhere('e.inicio <= :hasta')
            ->setParameter('unidad', $unidad)
            ->setParameter('propio', $propio->getId())
            ->setParameter('ocupan', PmsEventoEstado::IMPIDEN_VENTA)
            // Una noche por cada lado basta: sólo interesa quién pega con su estancia.
            ->setParameter('desde', (new DateTimeImmutable($inicio->format('Y-m-d')))->modify('-1 day'))
            ->setParameter('hasta', (new DateTimeImmutable($fin->format('Y-m-d')))->modify('+1 day'))
            ->getQuery()
            ->getResult();

        $diaLlegada = $inicio->format('Y-m-d');
        $diaSalida = $fin->format('Y-m-d');

        $saleEseDia = null;
        $entraEseDia = null;
        $ocupadaLaVispera = false;

        foreach ($vecinos as $vecino) {
            /** @var PmsEventoCalendario $vecino */
            $vInicio = $vecino->getInicio();
            $vFin = $vecino->getFin();

            if ($vFin !== null && $vFin->format('Y-m-d') === $diaLlegada) {
                $saleEseDia = $vFin->format('H:i');
                $ocupadaLaVispera = true;
            }

            // Ocupa la víspera cualquiera que siga dentro esa noche, aunque se vaya más tarde.
            if ($vInicio !== null && $vFin !== null
                && $vInicio->format('Y-m-d') < $diaLlegada
                && $vFin->format('Y-m-d') >= $diaLlegada) {
                $ocupadaLaVispera = true;
            }

            if ($vInicio !== null && $vInicio->format('Y-m-d') === $diaSalida) {
                $entraEseDia = $vInicio->format('H:i');
            }
        }

        return [
            'libre_la_vispera' => !$ocupadaLaVispera,
            'sale_alguien_el_dia_que_llega' => $saleEseDia,
            'entra_alguien_el_dia_que_se_va' => $entraEseDia,
            'desde_cuando_libre' => $ocupadaLaVispera ? null : $this->libreDesde($vecinos, $inicio),
        ];
    }

    /**
     * La estancia que manda: la primera por fecha de las que no están canceladas.
     *
     * Con varias casitas en la misma reserva ésta es la de la llegada, que es de lo que se
     * habla al preguntar por el check-in. El cambio de unidad a mitad de estancia es otro
     * problema y todavía no se resuelve aquí.
     */
    private function estanciaPrincipal(PmsReserva $reserva): ?PmsEventoCalendario
    {
        $vivas = [];

        foreach ($reserva->getEventosCalendario() as $evento) {
            $codigo = $evento->getEstado()?->getId();

            if ($codigo !== null && in_array($codigo, PmsEventoEstado::IMPIDEN_VENTA, true)) {
                $vivas[] = $evento;
            }
        }

        usort($vivas, static fn (PmsEventoCalendario $a, PmsEventoCalendario $b) => ($a->getInicio()?->getTimestamp() ?? 0) <=> ($b->getInicio()?->getTimestamp() ?? 0));

        return $vivas[0] ?? null;
    }

    /**
     * Desde cuándo lleva vacía, para poder decir «lleva dos días libre» en vez de un «sí» seco.
     *
     * @param list<PmsEventoCalendario> $vecinos
     */
    private function libreDesde(array $vecinos, \DateTimeInterface $inicio): ?string
    {
        $ultimaSalida = null;

        foreach ($vecinos as $vecino) {
            $vFin = $vecino->getFin();

            if ($vFin !== null && $vFin < $inicio && ($ultimaSalida === null || $vFin > $ultimaSalida)) {
                $ultimaSalida = $vFin;
            }
        }

        return $ultimaSalida?->format('d/m');
    }
}
