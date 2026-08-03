<?php

declare(strict_types=1);

namespace App\Pms\Service\Finance;

use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Recalcula `total_cargos` y `total_pagos` de la cabecera financiera (PmsInformacionFinanciera)
 * a partir de sus hijos (PmsCargoFinanciero / PmsPagoFinanciero), vía SQL crudo (mismo enfoque
 * que PmsReservaRecalculoService::recalcularDesdeEventos()).
 *
 * COHERENCIA DE MONEDA: los totales quedan expresados en la moneda DE LA CABECERA. Cada hijo
 * se convierte a esa moneda con SU PROPIO tipo de cambio (venta USD→PEN) antes de sumar. Ej.:
 * cabecera en soles + un cargo en dólares → el cargo se multiplica por su TC antes de sumar.
 * Si un hijo no tiene TC y su moneda difiere de la cabecera, no se puede valorizar con certeza:
 * aporta 0 al total (no se inventa una cifra) en vez de romper el cálculo.
 */
final class PmsInformacionFinancieraRecalculoService
{
    /**
     * Expresión SQL de conversión: convierte `$montoExpr` desde la moneda de la fila (`$monedaCol`,
     * con TC en `$tcCol`) hacia la moneda de la cabecera (`i.moneda_id`). Sólo soporta el par USD/PEN
     * (las únicas monedas operativas del PMS).
     */
    private function expresionConvertida(string $montoExpr, string $monedaCol, string $tcCol, string $cabeceraAlias): string
    {
        return <<<SQL
            COALESCE(
                CASE
                    WHEN COALESCE({$monedaCol}, 'USD') = COALESCE({$cabeceraAlias}.moneda_id, 'USD') THEN {$montoExpr}
                    WHEN COALESCE({$monedaCol}, 'USD') = 'USD' AND COALESCE({$cabeceraAlias}.moneda_id, 'USD') = 'PEN' THEN {$montoExpr} * {$tcCol}
                    WHEN COALESCE({$monedaCol}, 'USD') = 'PEN' AND COALESCE({$cabeceraAlias}.moneda_id, 'USD') = 'USD' THEN {$montoExpr} / NULLIF({$tcCol}, 0)
                    ELSE {$montoExpr}
                END,
                0
            )
            SQL;
    }

    /**
     * @param string[] $informacionIds UUIDs (string) de las cabeceras a recalcular.
     */
    public function recalcular(array $informacionIds, EntityManagerInterface $entityManager): void
    {
        $informacionIds = array_values(array_unique(array_filter(
            $informacionIds,
            static fn ($v) => is_string($v) && $v !== ''
        )));

        if ($informacionIds === []) {
            return;
        }

        $conn = $entityManager->getConnection();
        // Alias i2/i3 distintos del alias 'i' del UPDATE externo: son JOINs autocontenidos
        // dentro de cada subconsulta derivada, no una correlación hacia la tabla externa
        // (MySQL no permite reutilizar el mismo alias en el UPDATE y en una tabla derivada).
        $cargoConvertido = $this->expresionConvertida('COALESCE(c.total_linea, c.monto, 0)', 'c.moneda_id', 'c.tipo_cambio', 'i2');
        $pagoConvertido = $this->expresionConvertida('COALESCE(p.monto, 0)', 'p.moneda_id', 'p.tipo_cambio', 'i3');

        foreach (array_chunk($informacionIds, 400) as $chunk) {
            $binaryIds = array_map(static fn (string $id) => Uuid::fromString($id)->toBinary(), $chunk);
            $in = implode(',', array_fill(0, count($binaryIds), '?'));

            $sql = <<<SQL
                UPDATE pms_informacion_financiera i
                LEFT JOIN (
                    SELECT c.informacion_id, SUM($cargoConvertido) AS total
                    FROM pms_cargo_financiero c
                    INNER JOIN pms_informacion_financiera i2 ON i2.id = c.informacion_id
                    WHERE c.tipo = 'charge' AND c.informacion_id IN ($in)
                      -- Cabecera ANULADA: sólo cuenta la penalización (§12.7). Los cargos de
                      -- la estancia siguen en la tabla —no se pierde historia—, pero dejan de
                      -- sumar: tras una cancelación real lo que se debe es el "Cancel Fee".
                      AND (i2.activa = 1 OR c.tipo_cargo = 'penalizacion')
                    GROUP BY c.informacion_id
                ) cc ON cc.informacion_id = i.id
                LEFT JOIN (
                    SELECT p.informacion_id, SUM($pagoConvertido) AS total
                    FROM pms_pago_financiero p
                    INNER JOIN pms_informacion_financiera i3 ON i3.id = p.informacion_id
                    WHERE p.informacion_id IN ($in)
                    GROUP BY p.informacion_id
                ) pp ON pp.informacion_id = i.id
                SET
                    i.total_cargos = COALESCE(cc.total, 0),
                    i.total_pagos  = COALESCE(pp.total, 0)
                WHERE i.id IN ($in)
                SQL;

            $params = array_merge($binaryIds, $binaryIds, $binaryIds);
            $types = array_fill(0, count($params), ParameterType::BINARY);

            $conn->executeStatement($sql, $params, $types);
        }
    }
}
