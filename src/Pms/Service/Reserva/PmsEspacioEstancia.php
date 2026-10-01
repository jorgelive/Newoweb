<?php

declare(strict_types=1);

namespace App\Pms\Service\Reserva;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsReserva;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;

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
     *     libre_la_noche_que_se_va: bool,
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
        $fin = $this->finDeLaEstancia($reserva, $propio);

        if ($unidad === null || $inicio === null || $fin === null) {
            return null;
        }

        /** @var list<PmsEventoCalendario> $vecinos */
        $vecinos = $this->em->createQueryBuilder()
            ->select('e')
            ->from(PmsEventoCalendario::class, 'e')
            ->join('e.estado', 'es')
            ->where('e.pmsUnidad = :unidad')
            // Fuera TODA la reserva, no sólo la estancia principal: una noche extra se carga
            // como un segundo evento de la misma reserva, y comparando sólo contra el primero
            // el huésped aparecía como «otro huésped que entra el día que se va».
            ->andWhere('e.reserva IS NULL OR e.reserva != :reserva')
            ->andWhere('es.id IN (:ocupan)')
            ->andWhere('e.fin >= :desde')
            ->andWhere('e.inicio <= :hasta')
            // ⚠️ Los ids con tipo `uuid`, no la entidad ni el objeto `Uuid` a secas: en DQL
            // se serializan como cadena RFC contra una columna BINARY(16) y la consulta
            // devuelve CERO filas sin fallar — aquí eso significa «no hay vecinos», y el
            // early check-in / late check-out se ofrecería sobre una casita ocupada.
            ->setParameter('unidad', $unidad->getId(), UuidType::NAME)
            ->setParameter('reserva', $reserva->getId(), UuidType::NAME)
            ->setParameter('ocupan', PmsEventoEstado::IMPIDEN_VENTA)
            // Sólo interesa quién pega con su estancia: una noche por cada lado, más otra para
            // alcanzar al vecino cuya noche extra es la que pega (el que entra al día siguiente
            // con entrada temprana).
            ->setParameter('desde', (new DateTimeImmutable($inicio->format('Y-m-d')))->modify('-1 day'))
            ->setParameter('hasta', (new DateTimeImmutable($fin->format('Y-m-d')))->modify('+2 day'))
            ->getQuery()
            ->getResult();

        $diaLlegada = $inicio->format('Y-m-d');
        $diaSalida = $fin->format('Y-m-d');

        $saleEseDia = null;
        $entraEseDia = null;
        $ocupadaLaVispera = false;
        $ocupadaLaNocheQueSeVa = false;

        foreach ($vecinos as $vecino) {
            $vInicio = $vecino->getInicio();
            $vFin = $vecino->getFin();

            if ($vInicio === null || $vFin === null) {
                continue;
            }

            if ($vFin->format('Y-m-d') === $diaLlegada) {
                $saleEseDia = $vFin->format('H:i');
            }

            if ($vInicio->format('Y-m-d') === $diaSalida) {
                $entraEseDia = $vInicio->format('H:i');
            }

            // Las noches que ocupa DE VERDAD, con su horario extra: una salida tardía del que se
            // va el día anterior a su llegada también le quita la víspera, y una entrada temprana
            // del que llega al día siguiente de irse, la noche de su salida.
            $desde = $vInicio->format('Y-m-d');
            $hasta = $vFin->format('Y-m-d');
            foreach ($vecino->nochesExtra() as $noche) {
                $desde = min($desde, $noche->desde->format('Y-m-d'));
                $hasta = max($hasta, $noche->hasta->format('Y-m-d'));
            }

            $vispera = (new DateTimeImmutable($diaLlegada))->modify('-1 day')->format('Y-m-d');
            if ($desde <= $vispera && $hasta > $vispera) {
                $ocupadaLaVispera = true;
            }
            if ($desde <= $diaSalida && $hasta > $diaSalida) {
                $ocupadaLaNocheQueSeVa = true;
            }
        }

        return [
            'libre_la_vispera' => !$ocupadaLaVispera,
            'libre_la_noche_que_se_va' => !$ocupadaLaNocheQueSeVa,
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
     * Hasta cuándo se queda de verdad en ESA casita.
     *
     * 🔥 Caso MMQSB2 (José, 27/09/2026): reservó del 25 al 27 y alargó una noche. La noche extra
     * entra como un segundo evento de la misma reserva y la misma casita (27→28). Con el `fin`
     * del primero, el agente creía que se iba el 27 y que ese día «entraba otro huésped a las
     * 14:00» — que era él mismo. La estancia es la cadena de eventos contiguos de la reserva
     * en la casita de la llegada; si cambia de casita a mitad, se corta ahí.
     */
    private function finDeLaEstancia(PmsReserva $reserva, PmsEventoCalendario $propio): ?\DateTimeInterface
    {
        $fin = $propio->getFin();
        $unidad = $propio->getPmsUnidad();

        $siguientes = [];
        foreach ($reserva->getEventosCalendario() as $evento) {
            $codigo = $evento->getEstado()?->getId();

            if ($evento !== $propio
                && $evento->getPmsUnidad() === $unidad
                && $codigo !== null && in_array($codigo, PmsEventoEstado::IMPIDEN_VENTA, true)) {
                $siguientes[] = $evento;
            }
        }

        usort($siguientes, static fn (PmsEventoCalendario $a, PmsEventoCalendario $b) => ($a->getInicio()?->getTimestamp() ?? 0) <=> ($b->getInicio()?->getTimestamp() ?? 0));

        foreach ($siguientes as $evento) {
            $vInicio = $evento->getInicio();
            $vFin = $evento->getFin();

            if ($fin === null || $vInicio === null || $vFin === null) {
                continue;
            }

            // Contiguo: empieza el mismo día (o antes) de que acabe lo que ya llevamos.
            if ($vInicio->format('Y-m-d') <= $fin->format('Y-m-d') && $vFin > $fin) {
                $fin = $vFin;
            }
        }

        return $fin;
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
