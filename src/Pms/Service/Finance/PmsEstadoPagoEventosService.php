<?php

declare(strict_types=1);

namespace App\Pms\Service\Finance;

use App\Pms\Entity\PmsEventoEstadoPago;
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
                        SET e.estado_pago_id = '%s'
                        WHERE i.id IN (%s)
                          AND i.total_pagos > 0
                          AND i.total_cargos > 0
                          AND (i.total_cargos - i.total_pagos) <= 0
                          AND e.estado_pago_id <> '%s'
                        SQL,
                    PmsEventoEstadoPago::ID_PAGO_TOTAL,
                    $in,
                    PmsEventoEstadoPago::ID_PAGO_TOTAL,
                ),
                $binaryIds,
                $types,
            );

            // Hay dinero pero queda saldo. Sólo asciende desde `no-pagado`: es la
            // condición que impide degradar un `pago-total` o pisar un
            // `pago-alojamiento` decidido a mano.
            $tocadas += (int) $conn->executeStatement(
                sprintf(
                    <<<'SQL'
                        UPDATE pms_evento_calendario e
                        INNER JOIN pms_informacion_financiera i ON i.reserva_id = e.reserva_id
                        SET e.estado_pago_id = '%s'
                        WHERE i.id IN (%s)
                          AND i.total_pagos > 0
                          AND (i.total_cargos - i.total_pagos) > 0
                          AND e.estado_pago_id = '%s'
                        SQL,
                    PmsEventoEstadoPago::ID_PAGO_PARCIAL,
                    $in,
                    PmsEventoEstadoPago::ID_SIN_PAGO,
                ),
                $binaryIds,
                $types,
            );
        }

        return $tocadas > 0;
    }
}
