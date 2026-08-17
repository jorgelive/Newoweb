<?php

declare(strict_types=1);

namespace App\Pms\Service\Finance;

use App\Exchange\Service\Context\SyncContext;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsEventoEstadoPago;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Propaga el estado de pago de la cabecera financiera a las estancias.
 *
 * El operador registra el dinero en UN sitio (la cabecera: cargos y pagos), pero
 * quien decide qué ve el huésped es el `estadoPago` de cada PmsEventoCalendario.
 * Hasta ahora había que mantenerlo a mano, y era el campo que más se olvidaba —con
 * la particularidad de que olvidarlo tiene consecuencias visibles: `pago-parcial`
 * y `pago-total` están en `ESTADOS_PAGO_CONFIABLES`, que es lo que abre los
 * códigos de acceso de la guía (docs/PmsGuiaHuesped.md §3).
 *
 * REGLAS (sólo hacia adelante):
 *
 *  · Hay pagos y queda saldo   → `pago-parcial`
 *  · Hay pagos y el saldo es 0 → `pago-total`
 *
 * **Nunca degrada.** `pago-parcial` sólo se escribe sobre `no-pagado`, así que un
 * `pago-alojamiento` puesto a mano por el operador sobrevive, y quitar un pago no
 * "desconfirma" una estancia — misma asimetría deliberada que ya tenía el sistema
 * (registrar un pago confirma la reserva, quitarlo no la des-confirma).
 *
 * Va en SQL y no por el ORM a propósito: se ejecuta desde el postFlush del
 * listener de coherencia, donde tocar entidades gestionadas obligaría a un flush
 * anidado. Mismo patrón que PmsInformacionFinancieraRecalculoService.
 */
final class PmsEstadoPagoEventosService
{
    public function __construct(
        private readonly SyncContext $syncContext,
    ) {}

    /*
     * QUÉ EVENTOS QUEDAN FUERA, y por qué los dos `UPDATE` lo repiten:
     *
     * · EXTENSIONES (`evento_origen_id IS NOT NULL`) — la noche que bloquea un
     *   horario extra no vende nada, así que no tiene estado de pago que seguir.
     *   Marcarlas tenía dos efectos feos: encolaba un push inútil a Beds24 por el
     *   cambio de fila, y sobre todo, con un estado de pago «confiable»,
     *   `requiereAutoConfirmacionPorPago()` las habría convertido en CONFIRMADA —
     *   una extensión dejaría de serlo sola y pasaría de `black` a `confirmed` en
     *   el canal.
     * · CANCELADAS — no participan del saldo de la reserva; dejarlas seguir el
     *   cobro de las estancias vivas sólo confunde al leer el histórico.
     *
     * Va en el WHERE y no en PHP porque estos UPDATE son masivos por cabecera: no
     * hay entidades cargadas donde preguntar.
     */
    /**
     * @param string[] $informacionIds UUIDs (string) de las cabeceras afectadas.
     *
     * @return bool true si se tocó alguna estancia.
     */
    public function sincronizar(array $informacionIds, EntityManagerInterface $entityManager): bool
    {
        $informacionIds = array_values(array_unique(array_filter(
            $informacionIds,
            static fn ($v) => is_string($v) && $v !== ''
        )));

        if ($informacionIds === []) {
            return false;
        }

        $conn = $entityManager->getConnection();
        $tocadas = 0;

        foreach (array_chunk($informacionIds, 400) as $chunk) {
            $binaryIds = array_map(static fn (string $id) => Uuid::fromString($id)->toBinary(), $chunk);
            $in = implode(',', array_fill(0, count($binaryIds), '?'));
            $types = array_fill(0, count($binaryIds), ParameterType::BINARY);

            // Saldo cero: todo cobrado. Pisa cualquier estado anterior, incluido
            // `pago-alojamiento`: si no queda saldo, la estancia está pagada entera.
            $tocadas += (int) $conn->executeStatement(
                sprintf(
                    <<<'SQL'
                        UPDATE pms_evento_calendario e
                        INNER JOIN pms_informacion_financiera i ON i.reserva_id = e.reserva_id
                        INNER JOIN (%s) t ON t.informacion_id = i.id
                        SET e.estado_pago_id = '%s'
                        WHERE i.id IN (%s)
                          AND t.hay_cargos > 0
                          AND t.hay_pagos  > 0
                          AND t.monedas_sin_convertir = 0
                          AND t.cuadre <= t.tolerancia
                          AND e.estado_pago_id <> '%s'
                          AND e.evento_origen_id IS NULL
                          AND e.estado_id <> 'cancelada'
                        SQL,
                    $this->subconsultaDeCuadre($in),
                    PmsEventoEstadoPago::ID_PAGO_TOTAL,
                    $in,
                    PmsEventoEstadoPago::ID_PAGO_TOTAL,
                ),
                array_merge($binaryIds, $binaryIds),
                array_merge($types, $types),
            );

            // Hay dinero pero queda saldo. Sólo asciende desde `no-pagado`: es la
            // condición que impide degradar un `pago-total` o pisar un
            // `pago-alojamiento` decidido a mano.
            $tocadas += (int) $conn->executeStatement(
                sprintf(
                    <<<'SQL'
                        UPDATE pms_evento_calendario e
                        INNER JOIN pms_informacion_financiera i ON i.reserva_id = e.reserva_id
                        INNER JOIN (%s) t ON t.informacion_id = i.id
                        SET e.estado_pago_id = '%s'
                        WHERE i.id IN (%s)
                          AND t.hay_pagos > 0
                          AND t.cuadre > t.tolerancia
                          AND e.estado_pago_id = '%s'
                          AND e.evento_origen_id IS NULL
                          AND e.estado_id <> 'cancelada'
                        SQL,
                    $this->subconsultaDeCuadre($in),
                    PmsEventoEstadoPago::ID_PAGO_PARCIAL,
                    $in,
                    PmsEventoEstadoPago::ID_SIN_PAGO,
                ),
                array_merge($binaryIds, $binaryIds),
                array_merge($types, $types),
            );
            $tocadas += $this->confirmarPorPago($conn, $in, $binaryIds, $types);
        }

        return $tocadas > 0;
    }

    /**
     * Por ficha: si hay cargos, si hay cobros, cuánto falta y con cuánta holgura.
     *
     * ── Por qué una subconsulta y no la resta de dos columnas ───────────────────
     * Los totales viven ahora en `pms_finanzas_total_moneda`, una fila **por moneda**. «Queda algo
     * por cobrar» dejó de ser `total_cargos - total_pagos` y pasó a ser una agregación.
     *
     * ── El cuadre, y por qué se convierte AQUÍ ──────────────────────────────────
     * La contabilidad no convierte (§12.2b), pero decidir «¿está pagada?» exige **un** número.
     * Se usa el mismo cuadre que ve el operador: los saldos de cada moneda llevados a la de la
     * ficha con SU tipo de cambio de cuadre. Así el panel y esta decisión no pueden discrepar.
     *
     * ── ⚠️ La tolerancia sólo existe si hubo cambio de moneda, y es PROPORCIONAL ─
     * Está para la **diferencia cambiaria**: el cambio del mostrador nunca es exactamente el de la
     * ficha. Ese error crece con lo que se convirtió —sobre 750 dólares, dos milésimos de tasa son
     * ya casi medio dólar—, así que una constante deja pasar las reservas pequeñas y cuelga las
     * grandes. De ahí `GREATEST(mínimo, convertido × proporción)`.
     *
     * `convertido` se suma en **valor absoluto**: importa cuánto dinero pasó por una tasa, no en
     * qué dirección. Dos saldos de signo opuesto no cancelan su incertidumbre, la suman.
     *
     * Con **una sola moneda no hay ningún cambio de por medio**: la tolerancia es 0 y se exige
     * exactitud — quien deba 0.50 debe 0.50. La holgura la concede el hecho de haber convertido.
     *
     * ⚠️ **ESPEJO de `PmsTotalesPorMoneda::tolerancia()`**, que es lo que ve el operador en el
     * panel. Si dejan de decir lo mismo, el panel dirá «cuadra» y esto dejará la estancia en
     * parcial. Al tocar uno, tocar el otro.
     *
     * ── `<= tolerancia`, no `ABS(...)` ──────────────────────────────────────────
     * Un SOBREPAGO deja el cuadre negativo y tiene que contar como pagada — es lo que ya hacía el
     * `<= 0` de la versión vieja. Con valor absoluto, un huésped que paga de más se quedaría en
     * «parcial» para siempre.
     *
     * ── Sin tipo de cambio no se inventa nada ───────────────────────────────────
     * `NULLIF(i.tipo_cambio, 0)` deja el término en `NULL` y el `COALESCE` exterior lo lleva a 0;
     * el resto de monedas sí suma. Es preferible a decidir con una cifra falsa, y con el sello
     * automático del TC (§12.4.1b) no debería pasar nunca.
     */
    private function subconsultaDeCuadre(string $in): string
    {
        return sprintf(
            <<<'SQL'
                SELECT t.informacion_id,
                       MAX(t.total_cargos > 0) AS hay_cargos,
                       MAX(t.total_pagos  > 0) AS hay_pagos,
                       SUM(COALESCE(
                           CASE
                               WHEN t.moneda_id = COALESCE(i4.moneda_id, 'USD')
                                   THEN t.total_cargos - t.total_pagos
                               WHEN t.moneda_id = 'USD' AND COALESCE(i4.moneda_id, 'USD') = 'PEN'
                                   THEN (t.total_cargos - t.total_pagos) * i4.tipo_cambio
                               WHEN t.moneda_id = 'PEN' AND COALESCE(i4.moneda_id, 'USD') = 'USD'
                                   THEN (t.total_cargos - t.total_pagos) / NULLIF(i4.tipo_cambio, 0)
                               ELSE t.total_cargos - t.total_pagos
                           END,
                       0)) AS cuadre,
                       CASE WHEN COUNT(DISTINCT t.moneda_id) > 1
                            THEN GREATEST(%s, SUM(COALESCE(
                                CASE
                                    WHEN t.moneda_id = COALESCE(i4.moneda_id, 'USD') THEN 0
                                    WHEN t.moneda_id = 'USD' AND COALESCE(i4.moneda_id, 'USD') = 'PEN'
                                        THEN ABS((t.total_cargos - t.total_pagos) * i4.tipo_cambio)
                                    WHEN t.moneda_id = 'PEN' AND COALESCE(i4.moneda_id, 'USD') = 'USD'
                                        THEN ABS((t.total_cargos - t.total_pagos) / NULLIF(i4.tipo_cambio, 0))
                                    ELSE 0
                                END,
                            0)) * %s)
                            ELSE 0
                       END AS tolerancia,
                       -- 🔴 Monedas que NO se pudieron llevar a la de la ficha por falta de tipo
                       -- de cambio. Su saldo cae al `COALESCE(..., 0)` de arriba, así que el
                       -- cuadre sale como si esa deuda no existiera.
                       --
                       -- Sin esta cuenta, una ficha en soles con cargos en dólares y sin TC daba
                       -- cuadre 0 al saldar los soles → `pago-total` → `confirmarPorPago()` →
                       -- `ESTADOS_PAGO_CONFIABLES` → **se le abren los códigos de acceso de la
                       -- casa a quien todavía debe dólares**. No es un descuadre contable: es
                       -- una regresión que ve el huésped.
                       --
                       -- Con el sello del TC en la cabecera (§12.4.1b) no debería llegar nunca a
                       -- ser > 0. Se cuenta igual: el coste es una columna y lo que hay al otro
                       -- lado es la puerta de la casa.
                       SUM(
                           t.moneda_id <> COALESCE(i4.moneda_id, 'USD')
                           AND COALESCE(i4.tipo_cambio, 0) <= 0
                       ) AS monedas_sin_convertir
                FROM pms_finanzas_total_moneda t
                INNER JOIN pms_informacion_financiera i4 ON i4.id = t.informacion_id
                WHERE t.informacion_id IN (%s)
                GROUP BY t.informacion_id
                SQL,
            PmsTotalesPorMoneda::UMBRAL_CUADRE_MINIMO,
            PmsTotalesPorMoneda::UMBRAL_CUADRE_PROPORCION,
            $in,
        );
    }

    /**
     * Confirma las estancias que acaban de quedar con un estado de pago confiable.
     *
     * ## Por qué está aquí y no en el listener de integridad
     *
     * La regla "registrar un pago confirma la reserva" vive en
     * `PmsEventoCalendarioIntegrityListener::asegurarEstadoConfirmadoPorPago()`, que
     * escucha el `preUpdate` de `PmsEventoCalendario`. **Y nunca se disparaba por esta
     * vía**: los `UPDATE` de arriba van en SQL crudo, así que el estado de pago cambia sin
     * pasar por el UnitOfWork y Doctrine no emite ningún evento.
     *
     * El síntoma era exactamente ese: la estancia pasaba a `pago-total` —correcto— pero
     * seguía en `pendiente`. Afectaba a **todo** registro de pago, no sólo a los cobros por
     * pasarela; con estos se nota siempre porque nadie toca la estancia después a mano.
     *
     * Se arregla aquí, en SQL, y no convirtiendo los UPDATE en ORM: cargar las entidades
     * obligaría a un flush anidado dentro del propio flush (ver la nota de la clase).
     *
     * La condición es el espejo literal de `PmsEventoCalendario::requiereAutoConfirmacionPorPago()`
     * — si cambia una, hay que cambiar la otra. Se construye desde las mismas constantes
     * para que al menos los valores no se desincronicen.
     *
     * @param string[] $binaryIds
     * @param int[]    $types
     */
    private function confirmarPorPago(Connection $conn, string $in, array $binaryIds, array $types): int
    {
        // Durante un pull de Beds24 manda el canal: auto-confirmar aquí sobrescribiría lo
        // que acaba de decir la OTA y devolvería el cambio como un push. Mismo guardarraíl
        // que ya tiene el listener de integridad.
        if ($this->syncContext->isPull()) {
            return 0;
        }

        $confiables = $this->comoLista(PmsEventoEstadoPago::ESTADOS_PAGO_CONFIABLES);
        $sinAutoConfirmacion = $this->comoLista(PmsEventoCalendario::ESTADOS_SIN_AUTO_CONFIRMACION);

        return (int) $conn->executeStatement(
            sprintf(
                <<<'SQL'
                    UPDATE pms_evento_calendario e
                    INNER JOIN pms_informacion_financiera i ON i.reserva_id = e.reserva_id
                    SET e.estado_id = '%s'
                    WHERE i.id IN (%s)
                      AND e.estado_pago_id IN (%s)
                      AND e.estado_id NOT IN (%s)
                      AND e.estado_id <> '%s'
                      AND e.evento_origen_id IS NULL
                    SQL,
                PmsEventoEstado::CODIGO_CONFIRMADA,
                $in,
                $confiables,
                $sinAutoConfirmacion,
                PmsEventoEstado::CODIGO_CONFIRMADA,
            ),
            $binaryIds,
            $types,
        );
    }

    /**
     * Constantes PHP → lista SQL entrecomillada.
     *
     * Los valores son códigos del maestro (`confirmada`, `bloqueo`…), no entrada de usuario,
     * pero se escapan igual: el día que alguien añada un código con apóstrofo, esto no debe
     * ser el sitio donde se rompa.
     *
     * @param string[] $valores
     */
    private function comoLista(array $valores): string
    {
        return implode(',', array_map(
            static fn (string $v): string => "'" . str_replace("'", "''", $v) . "'",
            $valores,
        ));
    }
}
