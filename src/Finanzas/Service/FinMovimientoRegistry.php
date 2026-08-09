<?php

declare(strict_types=1);

namespace App\Finanzas\Service;

use App\Finanzas\Contract\FinMovimientoProviderInterface;
use App\Finanzas\Dto\FinMovimientoDto;
use App\Finanzas\Dto\FinMovimientoFiltro;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Reúne los cobros de todos los módulos en una sola lista de caja.
 *
 * Gemelo de `FinOrigenCobroRegistry`, con la misma mecánica de tags: implementar
 * `FinMovimientoProviderInterface` en un módulo basta para que sus pagos aparezcan aquí.
 */
final class FinMovimientoRegistry
{
    /**
     * @param iterable<FinMovimientoProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('finanzas.movimiento_provider')]
        private readonly iterable $providers,
    ) {}

    /**
     * Movimientos de todos los módulos, ordenados por fecha descendente.
     *
     * ⚠️ **El límite es POR MÓDULO, no global.** Cada provider devuelve hasta `limite`
     * filas y aquí se fusionan y se recortan. Con un solo módulo (hoy) el resultado es
     * exacto; con varios, un módulo muy activo podría desplazar filas antiguas de otro.
     *
     * Se acepta porque la alternativa —paginar de verdad— exige una tabla común de
     * movimientos, y eso es rehacer la contabilidad, no listar pagos. El día que estorbe,
     * la salida es materializar los movimientos en Finanzas, no complicar este método.
     * Mientras tanto, la vista filtra por fechas y con un rango normal no se roza el tope.
     *
     * @return FinMovimientoDto[]
     */
    public function buscar(FinMovimientoFiltro $filtro): array
    {
        $movimientos = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->buscar($filtro) as $movimiento) {
                $movimientos[] = $movimiento;
            }
        }

        // Desempate por id para que dos pagos del mismo día salgan siempre en el mismo
        // orden: sin él, la lista bailaba entre recargas y parecía que cambiaban los datos.
        usort($movimientos, static function (FinMovimientoDto $a, FinMovimientoDto $b): int {
            return [$b->fecha, $b->id] <=> [$a->fecha, $a->id];
        });

        return array_slice($movimientos, 0, $filtro->limite);
    }

    /**
     * Totales agrupados por moneda.
     *
     * Por moneda y NO convertido a una sola: el tipo de cambio bueno es el del día de cada
     * pago, y sumarlo todo con una cotización de hoy daría una cifra que no cuadra con
     * ningún extracto. Ver la nota de `FinMovimientoDto`.
     *
     * @param FinMovimientoDto[] $movimientos
     * @return array<int, array{moneda: string, total: string, registros: int}>
     */
    public function totalesPorMoneda(array $movimientos): array
    {
        $acumulado = [];

        foreach ($movimientos as $movimiento) {
            $acumulado[$movimiento->moneda] ??= ['total' => 0.0, 'registros' => 0];
            $acumulado[$movimiento->moneda]['total'] += (float) $movimiento->monto;
            $acumulado[$movimiento->moneda]['registros']++;
        }

        ksort($acumulado);

        $totales = [];
        foreach ($acumulado as $moneda => $datos) {
            $totales[] = [
                'moneda' => $moneda,
                'total' => number_format($datos['total'], 2, '.', ''),
                'registros' => $datos['registros'],
            ];
        }

        return $totales;
    }

    /**
     * Catálogo de medios de todos los módulos, deduplicado por código.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function mediosDisponibles(): array
    {
        $medios = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->mediosDisponibles() as $medio) {
                $medios[$medio['value']] = $medio;
            }
        }

        return array_values($medios);
    }
}
