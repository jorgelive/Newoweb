<?php

declare(strict_types=1);

namespace App\Pms\Repository;

use App\Pms\Entity\PmsFinanzasTotalMoneda;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Lectura EN LOTE de los totales por moneda.
 *
 * Existe por una razón concreta: el calendario pinta un mes entero y necesita el saldo de
 * decenas de reservas de golpe. Preguntarlo ficha a ficha —o colgar una colección lazy de
 * `PmsInformacionFinanciera`— son 50-80 consultas por pintada. Por eso la tabla **no tiene lado
 * inverso** y se lee desde aquí.
 *
 * Para el saldo de UNA ficha que se acaba de tocar, esto NO sirve: el rollup se escribe con SQL
 * crudo en `postFlush` y esta tabla puede ir un paso por detrás dentro de la misma petición. Ahí
 * se usa el value object, que suma desde las colecciones de la ficha.
 *
 * @extends ServiceEntityRepository<PmsFinanzasTotalMoneda>
 */
class PmsFinanzasTotalMonedaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PmsFinanzasTotalMoneda::class);
    }

    /**
     * Los totales de varias fichas, indexados por id de ficha y luego por moneda.
     *
     * 🔴 SQL crudo con los uuids en BINARIO, y no DQL, por lo mismo que documenta
     * `PmsInformacionFinancieraRepository::findOneByReservaId()`: `informacion_id` es
     * `BINARY(16)` y un parámetro sin tipar se manda como texto, MySQL compara texto contra
     * binario y **devuelve cero filas sin dar ningún error**. Aquí además interesa no hidratar
     * entidades: son cifras para pintar, no objetos con los que trabajar.
     *
     * @param list<string> $informacionIds
     *
     * @return array<string, array<string, array{cargos: string, pagos: string, saldo: string}>>
     */
    public function totalesDe(array $informacionIds): array
    {
        if ($informacionIds === []) {
            return [];
        }

        $binarios = [];
        foreach ($informacionIds as $id) {
            try {
                $binarios[] = Uuid::fromString($id)->toBinary();
            } catch (Throwable) {
                // Un id ilegible se descarta él solo: no puede dejar sin cifras a los demás.
                continue;
            }
        }

        if ($binarios === []) {
            return [];
        }

        $marcadores = implode(',', array_fill(0, count($binarios), '?'));

        $filas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT informacion_id, moneda_id, total_cargos, total_pagos
             FROM pms_finanzas_total_moneda
             WHERE informacion_id IN ({$marcadores})
             ORDER BY moneda_id ASC",
            $binarios,
            array_fill(0, count($binarios), ParameterType::BINARY),
        );

        $porFicha = [];

        foreach ($filas as $fila) {
            // 🔴 `fromBinary()` y NO `fromString(HEX(...))`: `Uuid::fromString()` exige la forma
            // canónica con guiones y **lanza `Invalid UUID` con 32 caracteres hex seguidos**, que
            // es justo lo que devuelve `HEX()` de MySQL. Se devuelve el UUID canónico para que
            // quien llame pueda indexar con `(string) $info->getId()` sin traducir nada.
            $uuid = Uuid::fromBinary((string) $fila['informacion_id']);
            $cargos = (string) $fila['total_cargos'];
            $pagos = (string) $fila['total_pagos'];

            $porFicha[(string) $uuid][(string) $fila['moneda_id']] = [
                'cargos' => $cargos,
                'pagos' => $pagos,
                'saldo' => number_format((float) $cargos - (float) $pagos, 2, '.', ''),
            ];
        }

        return $porFicha;
    }
}
