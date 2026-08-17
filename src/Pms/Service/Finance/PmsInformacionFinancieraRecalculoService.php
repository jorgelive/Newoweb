<?php

declare(strict_types=1);

namespace App\Pms\Service\Finance;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Recalcula los totales de una cabecera financiera a partir de sus cargos y cobros, por SQL crudo.
 *
 * ── ⚠️ Escribe DOS modelos a la vez, y es temporal ──────────────────────────
 * 1. **`pms_finanzas_total_moneda`** (el modelo nuevo): una fila por moneda con movimiento,
 *    **sin convertir nada**. Soles con soles, dólares con dólares.
 * 2. **`total_cargos` / `total_pagos`** de la cabecera (el modelo viejo): todo convertido a la
 *    moneda de la cabecera con el tipo de cambio de cada fila.
 *
 * El viejo se sigue escribiendo mientras dure la transición para que **revertir el código no
 * obligue a revertir la base**. Los dos se retiran en el mismo PR que el `DROP COLUMN`, junto con
 * {@see self::expresionConvertida()}, que es la pieza que aquí sobra.
 *
 * ── Por qué el modelo viejo se cambia ───────────────────────────────────────
 * Convertir tiene un fallo que no avisa: una fila en otra moneda **sin tipo de cambio** aporta 0
 * y desaparece del total sin dar ningún error. Es lo que destapó HMN4BP8J25 —«en dólares cuadra y
 * en soles no»— y lo que obligó al panel a aprender a pintar el saldo como `—`. El modelo nuevo
 * no puede tener ese fallo porque no convierte.
 *
 * ── Por qué SQL crudo y no el ORM ───────────────────────────────────────────
 * Son sumas sobre miles de filas hijas y esto corre en cada `postFlush`. Hidratar entidades para
 * sumarlas sería pagar el precio de un ORM sin usar nada de lo que da. La contrapartida: los
 * totales cambian **sin pasar por el `UnitOfWork`**, así que la entidad en memoria se queda con
 * los valores viejos — de ahí el `$em->refresh()` del listener antes de sincronizar el depósito.
 */
final class PmsInformacionFinancieraRecalculoService
{
    /**
     * Expresión SQL de conversión hacia la moneda de la cabecera. Sólo el par USD/PEN.
     *
     * ⚠️ **En retirada.** Es la pieza del modelo viejo, y es la que hace desaparecer las filas sin
     * tipo de cambio: `monto * NULL` es `NULL`, y el `COALESCE` exterior lo convierte en 0. Tiene
     * un espejo en PHP —`PmsInformacionFinanciera::aMonedaBase()`— que hay que tocar a la vez, y
     * ése es justamente el acoplamiento que el modelo nuevo elimina: sin conversión, el rollup es
     * un `GROUP BY` y no hay dos fórmulas que mantener sincronizadas.
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
     * ¿Este cobro se imputa a una moneda distinta de la suya?
     *
     * Sólo si lo declara **y se puede hacer con honestidad**: hace falta un tipo de cambio y que
     * el par esté soportado. Cuando no, el cobro **no se imputa y no se pierde** — cae en la rama
     * de «su propia moneda». Es la diferencia con el rollup viejo, donde una fila sin TC aportaba
     * 0 y se evaporaba en silencio, y es la razón de que esto sea una condición y no un `CASE`
     * con un `NULL` al final.
     *
     * Con `PmsTipoCambioSnapshotListener` sellando el TC en cada registro, esta red de seguridad
     * no debería dispararse nunca. Se escribe igual: cuesta cuatro líneas, y no tenerla es
     * exactamente lo que costó tres cancelaciones perdidas.
     */
    private function expresionImputa(): string
    {
        return <<<'SQL'
            p.moneda_saldada_id IS NOT NULL
            AND p.moneda_saldada_id <> COALESCE(p.moneda_id, 'USD')
            AND p.tipo_cambio IS NOT NULL AND p.tipo_cambio <> 0
            AND (
                (COALESCE(p.moneda_id, 'USD') = 'PEN' AND p.moneda_saldada_id = 'USD')
             OR (COALESCE(p.moneda_id, 'USD') = 'USD' AND p.moneda_saldada_id = 'PEN')
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

        foreach (array_chunk($informacionIds, 400) as $chunk) {
            $binaryIds = array_map(static fn (string $id) => Uuid::fromString($id)->toBinary(), $chunk);

            $this->recalcularPorMoneda($conn, $binaryIds);
            $this->recalcularConvertido($conn, $binaryIds);
        }
    }

    /**
     * El modelo NUEVO: una fila por (cabecera, moneda), sin convertir.
     *
     * ── DELETE + INSERT, y no un UPSERT ─────────────────────────────────────────
     * Es lo único que garantiza **por construcción** que no quede una fila fantasma de una moneda
     * que ya no tiene ningún registro. Con `INSERT … ON DUPLICATE KEY UPDATE`, borrar el último
     * cargo en soles dejaría viva una fila `PEN 50.00 / 0.00` diciendo que se debe algo que ya no
     * existe — y eso encadena hasta `PmsEstadoPagoEventosService`, que decidiría que la reserva
     * sigue debiendo. La tabla es minúscula (una o dos filas por ficha), así que rehacerla es
     * gratis.
     *
     * ── En transacción, y no por costumbre ──────────────────────────────────────
     * `postFlush` corre **fuera** de la transacción del flush: Doctrine hace commit y luego
     * dispara el evento. Sin envolver esto, hay una ventana de milisegundos en la que otra
     * petición ve la ficha **sin ninguna fila** y el panel pinta ceros.
     *
     * ── Las filas en CERO se conservan ──────────────────────────────────────────
     * Nada de `HAVING`: una estancia directa nace con una línea a `0.00` y es donde el operador
     * teclea el precio acordado. Si la fila desapareciera, el panel no tendría dónde enseñarla.
     *
     * @param list<string> $binaryIds
     */
    private function recalcularPorMoneda(Connection $conn, array $binaryIds): void
    {
        $in = implode(',', array_fill(0, count($binaryIds), '?'));
        $tipos = array_fill(0, count($binaryIds), ParameterType::BINARY);
        $imputa = $this->expresionImputa();

        $conn->transactional(static function (Connection $c) use ($in, $binaryIds, $tipos, $imputa): void {
            $c->executeStatement(
                "DELETE FROM pms_finanzas_total_moneda WHERE informacion_id IN ({$in})",
                $binaryIds,
                $tipos,
            );

            $c->executeStatement(
                <<<SQL
                INSERT INTO pms_finanzas_total_moneda (informacion_id, moneda_id, total_cargos, total_pagos)
                SELECT m.informacion_id, m.moneda_id, SUM(m.cargo), SUM(m.pago)
                FROM (
                    /* 1. CARGOS, cada uno en SU moneda. Aquí ya no se convierte nada. */
                    SELECT c.informacion_id                    AS informacion_id,
                           COALESCE(c.moneda_id, 'USD')        AS moneda_id,
                           COALESCE(c.total_linea, c.monto, 0) AS cargo,
                           0                                   AS pago
                    FROM pms_cargo_financiero c
                    INNER JOIN pms_informacion_financiera i2 ON i2.id = c.informacion_id
                    -- COALESCE y no `= 'charge'`: la columna admite nulo y `prePersist` es quien
                    -- pone el defecto, así que el espejo en PHP (`PmsTotalesPorMoneda`) tiene que
                    -- contar igual una fila sin tipo. Hoy no hay ninguna, pero dos criterios
                    -- distintos sobre la misma fila es exactamente lo que no puede pasar aquí.
                    WHERE COALESCE(c.tipo, 'charge') = 'charge'
                      AND c.informacion_id IN ({$in})
                      -- Cabecera ANULADA: sólo cuenta la penalización (§12.7). Los cargos de la
                      -- estancia siguen en la tabla —no se pierde historia—, pero dejan de sumar.
                      --
                      -- ⚠️ ASIMÉTRICO A PROPÓSITO: los cobros NO llevan este filtro, porque el
                      -- dinero entró igual y ocultarlo sería mentir. Antes el neteo lo escondía;
                      -- ahora se ve como saldo negativo, y está bien que se vea. Lo que NO puede
                      -- pasar es que «ninguna moneda debe» se lea como «pagada»: de eso se ocupa
                      -- PmsEstadoPagoEventosService exigiendo además que HAYA cargos.
                      AND (i2.activa = 1 OR c.tipo_cargo = 'penalizacion')

                    UNION ALL

                    /* 2a. COBROS que saldan SU PROPIA moneda —el 97 %—, MÁS los que declaran otra
                          pero no se puede honrar. Nunca se pierde un cobro. */
                    SELECT p.informacion_id, COALESCE(p.moneda_id, 'USD'), 0, COALESCE(p.monto, 0)
                    FROM pms_pago_financiero p
                    WHERE p.informacion_id IN ({$in})
                      AND NOT ({$imputa})

                    UNION ALL

                    /* 2b. COBROS IMPUTADOS a otra moneda: el ÚNICO sitio del sistema donde
                          sobrevive una conversión, y sólo porque ahí el dinero sí cruzó.

                          ROUND por fila y no al final: 223.70 / 3.391 = 65.9687…, y sin redondear
                          el saldo en dólares se queda en 0.0013 y no llega nunca a cero exacto. */
                    SELECT p.informacion_id, p.moneda_saldada_id, 0,
                           CASE
                               WHEN COALESCE(p.moneda_id, 'USD') = 'PEN'
                                   THEN ROUND(COALESCE(p.monto, 0) / p.tipo_cambio, 2)
                               ELSE ROUND(COALESCE(p.monto, 0) * p.tipo_cambio, 2)
                           END
                    FROM pms_pago_financiero p
                    WHERE p.informacion_id IN ({$in})
                      AND ({$imputa})
                ) m
                GROUP BY m.informacion_id, m.moneda_id
                SQL,
                array_merge($binaryIds, $binaryIds, $binaryIds),
                array_merge($tipos, $tipos, $tipos),
            );
        });
    }

    /**
     * El modelo VIEJO: los dos escalares de la cabecera, con todo convertido.
     *
     * ⚠️ **En retirada.** Se sigue escribiendo sólo para que revertir el código no obligue a
     * revertir la base durante la transición. Nada nuevo debe leer de aquí.
     *
     * @param list<string> $binaryIds
     */
    private function recalcularConvertido(Connection $conn, array $binaryIds): void
    {
        // Alias i2/i3 distintos del alias 'i' del UPDATE externo: son JOINs autocontenidos dentro
        // de cada subconsulta derivada, no una correlación hacia la tabla externa (MySQL no
        // permite reutilizar el mismo alias en el UPDATE y en una tabla derivada).
        $cargoConvertido = $this->expresionConvertida('COALESCE(c.total_linea, c.monto, 0)', 'c.moneda_id', 'c.tipo_cambio', 'i2');
        $pagoConvertido = $this->expresionConvertida('COALESCE(p.monto, 0)', 'p.moneda_id', 'p.tipo_cambio', 'i3');

        $in = implode(',', array_fill(0, count($binaryIds), '?'));

        $sql = <<<SQL
            UPDATE pms_informacion_financiera i
            LEFT JOIN (
                SELECT c.informacion_id, SUM($cargoConvertido) AS total
                FROM pms_cargo_financiero c
                INNER JOIN pms_informacion_financiera i2 ON i2.id = c.informacion_id
                WHERE c.tipo = 'charge' AND c.informacion_id IN ($in)
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

        $conn->executeStatement($sql, $params, array_fill(0, count($params), ParameterType::BINARY));
    }
}
