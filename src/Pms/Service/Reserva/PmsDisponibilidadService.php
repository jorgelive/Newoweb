<?php

declare(strict_types=1);

namespace App\Pms\Service\Reserva;

use App\Pms\Dto\PmsUnidadDisponibleDto;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsUnidad;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * ¿Qué casitas puedo vender entre dos fechas?
 *
 * Nació para el agente interno del panel («¿qué tengo libre del 12 al 15?»), pero no
 * sabe nada de IA: es una consulta de negocio reutilizable desde el drawer de reservas,
 * cotizaciones o cualquier endpoint público que se quiera abrir mañana.
 *
 * Ver docs/PmsDisponibilidad.md.
 */
final readonly class PmsDisponibilidadService
{
    /** Techo de noches por consulta. Evita que una fecha mal tecleada barra años enteros. */
    private const int MAX_NOCHES = 365;

    public function __construct(
        private EntityManagerInterface $em
    ) {}

    /**
     * Casitas libres TODAS las noches del rango [desde, hasta).
     *
     * El rango es semiabierto, como una estancia: del 12 al 15 son las noches 12, 13 y 14,
     * y el día 15 la casita vuelve a estar libre. Por eso una reserva que se va el 12 no
     * estorba a una consulta que empieza el 12.
     *
     * @param int|null $pax Si se indica, descarta las casitas cuya capacidad no llega.
     *                      Las que no tienen capacidad declarada NO se descartan: se
     *                      prefiere ofrecerlas y que el operador decida, antes que
     *                      esconder inventario por un dato sin rellenar.
     *
     * @return list<PmsUnidadDisponibleDto>
     */
    public function buscar(
        DateTimeInterface $desde,
        DateTimeInterface $hasta,
        ?int $pax = null,
        ?string $establecimientoId = null
    ): array {
        $desdeDia = DateTimeImmutable::createFromInterface($desde)->setTime(0, 0);
        $hastaDia = DateTimeImmutable::createFromInterface($hasta)->setTime(0, 0);

        if ($hastaDia <= $desdeDia) {
            throw new InvalidArgumentException('La fecha de salida debe ser posterior a la de entrada.');
        }

        if ($desdeDia->diff($hastaDia)->days > self::MAX_NOCHES) {
            throw new InvalidArgumentException(sprintf(
                'El rango no puede superar %d noches.',
                self::MAX_NOCHES
            ));
        }

        $ocupadas = $this->unidadesOcupadas($desdeDia, $hastaDia);

        $qb = $this->em->getRepository(PmsUnidad::class)
            ->createQueryBuilder('u')
            ->addSelect('e')
            ->join('u.establecimiento', 'e')
            ->where('u.activo = true')
            ->orderBy('e.nombreComercial', 'ASC')
            ->addOrderBy('u.nombre', 'ASC');

        if ($ocupadas !== []) {
            // 🔥 Binarios crudos + ArrayParameterType::BINARY, el mismo patrón que
            // Beds24SendQueueRepository::hydrateItems(). `pms_unidad.id` es BINARY(16), y ni
            // los UUID canónicos ni los objetos Uuid casan en un `IN` de DQL: la consulta
            // devuelve TODAS las casitas como libres, sin lanzar ningún error. Un fallo mudo
            // que además sobrevende, así que la prueba de que esto funciona es que una casita
            // ocupada DESAPAREZCA del resultado, no que la consulta no reviente.
            $qb->andWhere('u.id NOT IN (:ocupadas)')
               ->setParameter(
                   'ocupadas',
                   array_map(static fn (string $id) => Uuid::fromString($id)->toBinary(), $ocupadas),
                   ArrayParameterType::BINARY
               );
        }

        if ($pax !== null && $pax > 0) {
            // `capacidad IS NULL` entra: ver la nota del @param.
            $qb->andWhere('u.capacidad IS NULL OR u.capacidad >= :pax')->setParameter('pax', $pax);
        }

        if ($establecimientoId !== null && $establecimientoId !== '') {
            $qb->andWhere('e.id = :establecimiento')->setParameter('establecimiento', $establecimientoId);
        }

        return array_map(
            static fn (PmsUnidad $u) => new PmsUnidadDisponibleDto(
                id:              (string) $u->getId(),
                nombre:          $u->getNombre() ?? 'Sin nombre',
                establecimiento: $u->getEstablecimiento()?->getNombreComercial() ?? '—',
                capacidad:       $u->getCapacidad(),
                tarifaBase:      $u->getTarifaBasePrecio(),
                moneda:          $u->getTarifaBaseMonedaId(),
            ),
            $qb->getQuery()->getResult()
        );
    }

    /**
     * IDs de las unidades con algún evento que impide vender dentro del rango.
     *
     * 🔥 Va en SQL nativo por el `DATE()`: `inicio` y `fin` llevan la hora de check-in y
     * check-out (hora de pared, §12.5.5 de docs/PmsBeds24ReservasSync.md), y comparar los
     * instantes tal cual da un falso positivo en el día de salida — un check-out del 12 a
     * las 10:00 es `> 12T00:00`, así que parecería ocupar la noche del 12 cuando la
     * casita ya está libre. Comparando por DÍA el solape encaja con la semántica hotelera.
     *
     * @return list<string> UUIDs canónicos
     */
    private function unidadesOcupadas(DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT BIN_TO_UUID(e.pms_unidad_id) AS unidad
            FROM pms_evento_calendario e
            WHERE e.pms_unidad_id IS NOT NULL
              AND e.estado_id IN (:estados)
              AND DATE(e.inicio) < :hasta
              AND DATE(e.fin)    > :desde
        SQL;

        $filas = $this->em->getConnection()->executeQuery(
            $sql,
            [
                'estados' => PmsEventoEstado::IMPIDEN_VENTA,
                'desde'   => $desde->format('Y-m-d'),
                'hasta'   => $hasta->format('Y-m-d'),
            ],
            ['estados' => ArrayParameterType::STRING]
        )->fetchFirstColumn();

        return array_map('strval', $filas);
    }
}
