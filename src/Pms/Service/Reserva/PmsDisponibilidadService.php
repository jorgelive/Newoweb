<?php

declare(strict_types=1);

namespace App\Pms\Service\Reserva;

use App\Pms\Dto\PmsOcupacionDto;
use App\Pms\Dto\PmsUnidadDisponibleDto;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsUnidad;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bridge\Doctrine\Types\UuidType;
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
            // ⚠️ Con tipo `uuid`: sin él la cadena no casa con el BINARY(16) y el filtro por
            // establecimiento devolvía SIEMPRE cero unidades disponibles.
            $qb->andWhere('e.id = :establecimiento')
               ->setParameter('establecimiento', $establecimientoId, UuidType::NAME);
        }

        return array_map(
            static fn (PmsUnidad $u) => new PmsUnidadDisponibleDto(
                id:              (string) $u->getId(),
                nombre:          $u->getNombre() ?? 'Sin nombre',
                establecimiento: $u->getEstablecimiento()?->getNombreComercial() ?? '—',
                capacidad:       $u->getCapacidad(),
                tarifaBase:      $u->getTarifaBasePrecio(),
                moneda:          $u->getTarifaBaseMonedaId(),
                habitaciones:    $u->getHabitaciones(),
                camas:           $u->getCamas(),
                banos:           $u->getBanos(),
            ),
            $qb->getQuery()->getResult()
        );
    }

    /**
     * Quién ocupa las casitas entre dos fechas. El reverso exacto de {@see buscar()}.
     *
     * **Comparte `IMPIDEN_VENTA` y el solape por `DATE()` con la disponibilidad a propósito.**
     * Son la misma pregunta mirada por sus dos caras, así que si divergieran —una contando
     * `bloqueo` y la otra no— el operador vería 5 libres y 4 ocupadas de 7 casitas sin saber
     * qué creerse. Cuadran por construcción, no por disciplina.
     *
     * Devuelve también bloqueos y extensiones, que no son personas: `esEstancia` los separa.
     * Esconderlos habría descuadrado la suma justo en los casos raros, que son los que se
     * consultan.
     *
     * @param string|null $unidadId Una casita concreta; `null` para todo el parque.
     *
     * @return list<PmsOcupacionDto> Ordenado por casita y fecha de entrada.
     */
    public function ocupacion(
        DateTimeInterface $desde,
        DateTimeInterface $hasta,
        ?string $unidadId = null
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

        // SQL nativo por el mismo motivo que unidadesOcupadas(): el DATE() sobre las horas de
        // pared. Ver la nota de ese método.
        $sql = <<<'SQL'
            SELECT BIN_TO_UUID(e.id)             AS evento_id,
                   BIN_TO_UUID(e.reserva_id)     AS reserva_id,
                   BIN_TO_UUID(e.pms_unidad_id)  AS casita_id,
                   u.nombre                      AS casita,
                   est.nombre_comercial          AS establecimiento,
                   e.titulo_cache                AS huesped,
                   DATE(e.inicio)                AS entra,
                   DATE(e.fin)                   AS sale,
                   TIME_FORMAT(e.inicio, '%H:%i') AS hora_entrada,
                   TIME_FORMAT(e.fin,    '%H:%i') AS hora_salida,
                   e.estado_id                   AS estado,
                   e.is_ota                      AS es_ota,
                   -- ⚠️ El de la RESERVA, no el del evento (`e.localizador`, que también
                   -- existe y es distinto). El de la reserva es el que ve el huésped: es el
                   -- que arma la URL de su guía. Devolver el del evento hacía que dos skills
                   -- dieran códigos distintos del mismo huésped.
                   r.localizador                 AS localizador
            FROM pms_evento_calendario e
            JOIN pms_unidad u          ON u.id = e.pms_unidad_id
            LEFT JOIN pms_reserva r    ON r.id = e.reserva_id
            LEFT JOIN pms_establecimiento est ON est.id = u.establecimiento_id
            WHERE e.estado_id IN (:estados)
              AND DATE(e.inicio) < :hasta
              AND DATE(e.fin)    > :desde
              AND (:unidad IS NULL OR e.pms_unidad_id = UUID_TO_BIN(:unidad))
            ORDER BY u.nombre ASC, e.inicio ASC
        SQL;

        $filas = $this->em->getConnection()->executeQuery(
            $sql,
            [
                'estados' => PmsEventoEstado::IMPIDEN_VENTA,
                'desde'   => $desdeDia->format('Y-m-d'),
                'hasta'   => $hastaDia->format('Y-m-d'),
                'unidad'  => $unidadId,
            ],
            ['estados' => ArrayParameterType::STRING]
        )->fetchAllAssociative();

        return array_map(
            static fn (array $f) => new PmsOcupacionDto(
                casita:          (string) ($f['casita'] ?? '—'),
                casitaId:        (string) $f['casita_id'],
                establecimiento: (string) ($f['establecimiento'] ?? '—'),
                // Un bloqueo no tiene huésped: el título cacheado trae el motivo o va vacío.
                huesped:         ($f['huesped'] ?? '') !== '' ? (string) $f['huesped'] : null,
                entra:           (string) $f['entra'],
                sale:            (string) $f['sale'],
                horaEntrada:     $f['hora_entrada'] !== null ? (string) $f['hora_entrada'] : null,
                horaSalida:      $f['hora_salida'] !== null ? (string) $f['hora_salida'] : null,
                estado:          (string) $f['estado'],
                esEstancia:      in_array($f['estado'], PmsEventoEstado::OCUPAN_UNIDAD, true),
                esOta:           (bool) $f['es_ota'],
                localizador:     ($f['localizador'] ?? '') !== '' ? (string) $f['localizador'] : null,
                reservaId:       $f['reserva_id'] !== null ? (string) $f['reserva_id'] : null,
                eventoId:        (string) $f['evento_id'],
            ),
            $filas
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
    /**
     * ¿Está libre la casita justo ANTES de entrar y justo DESPUÉS de salir?
     *
     * Es lo que hay que mirar para decidir una entrada temprana o una salida tardía, y es la
     * pregunta que el operador se hacía a mano abriendo el calendario. Un late check-out crea
     * una extensión que **bloquea la noche del día de salida** (§7.1.b de
     * `PmsBeds24ReservasSync.md`): si esa noche ya está vendida, no hay nada que conceder.
     *
     * ⚠️ **No decide, informa.** Que la noche esté libre no significa que se autorice —hay
     * precio, limpieza y criterio de por medio—; significa que la conversación con el huésped
     * puede seguir. Al revés sí es concluyente: ocupada es que no.
     *
     * Se excluye el PROPIO evento y todo lo que cuelgue de él (sus extensiones), o una estancia
     * se detectaría a sí misma como el motivo de su propia ocupación.
     *
     * Misma regla que el resto del servicio —`IMPIDEN_VENTA` y solape por `DATE()`—, así que un
     * bloqueo por mantenimiento cuenta como ocupado: no es vendible aunque no haya huésped.
     *
     * @return array{
     *     antes: array{fecha: string, libre: bool, ocupa: ?string},
     *     despues: array{fecha: string, libre: bool, ocupa: ?string}
     * }
     */
    public function margenesDe(PmsEventoCalendario $evento): array
    {
        $inicio = $evento->getInicio();
        $fin = $evento->getFin();
        $unidadId = $evento->getPmsUnidad()?->getId();

        if ($inicio === null || $fin === null || $unidadId === null) {
            throw new InvalidArgumentException('La estancia no tiene fechas o casita asignada.');
        }

        $vispera = DateTimeImmutable::createFromInterface($inicio)->setTime(0, 0)->modify('-1 day');
        $salida = DateTimeImmutable::createFromInterface($fin)->setTime(0, 0);

        return [
            'antes' => $this->nocheDe($vispera, (string) $unidadId, $evento),
            // La noche del día de salida: la que ocuparía la extensión del late check-out.
            'despues' => $this->nocheDe($salida, (string) $unidadId, $evento),
        ];
    }

    /**
     * Estado de UNA noche concreta en una casita.
     *
     * @return array{fecha: string, libre: bool, ocupa: ?string}
     */
    private function nocheDe(DateTimeImmutable $noche, string $unidadId, PmsEventoCalendario $propio): array
    {
        $reservaPropia = $propio->getReserva()?->getId() !== null
            ? (string) $propio->getReserva()->getId()
            : null;
        $ocupada = null;

        foreach ($this->ocupacion($noche, $noche->modify('+1 day'), $unidadId) as $dto) {
            // Lo de la MISMA reserva no cuenta como ocupación ajena: es el propio evento, su
            // extensión, o su otro tramo en la misma casita. Se compara por reserva y no por
            // id de evento porque las extensiones son eventos aparte —cuelgan por
            // `eventoOrigen`— y buscarlas una a una sería una consulta extra por noche.
            if ($dto->reservaId !== null && $dto->reservaId === $reservaPropia) {
                continue;
            }

            // Se queda con el primero: al operador le basta saber que hay algo y de quién,
            // no la lista completa de lo que solapa.
            $ocupada = $dto->huesped ?: $dto->estado;
            break;
        }

        return [
            'fecha' => $noche->format('Y-m-d'),
            'libre' => $ocupada === null,
            'ocupa' => $ocupada,
        ];
    }

}
