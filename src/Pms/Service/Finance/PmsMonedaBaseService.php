<?php

declare(strict_types=1);

namespace App\Pms\Service\Finance;

use App\Pms\Entity\PmsInformacionFinanciera;
use DomainException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cambia la MONEDA BASE (contable) de una reserva.
 *
 * El caso de uso es el cliente directo peruano que cierra el trato en soles: la reserva nace en
 * dólares (base por defecto) con sus cargos automáticos calculados del tarifario, y al fijar la
 * base en soles todo el expediente pasa a estar denominado en soles de forma determinista.
 *
 * REGLAS (ver §12.4.4 del doc):
 *
 *  · Sólo en reservas DIRECTAS PURAS. Si algún cargo vino de Beds24, la moneda base es la del
 *    canal y reescribirla falsearía la verdad histórica del canal.
 *  · Los CARGOS se reescriben en la moneda nueva y la descripción conserva el importe original
 *    ("(orig. US$ 140.00 · TC 3.411)"), que es la traza mínima para poder auditar.
 *  · Los PAGOS **no se tocan nunca**: son un hecho, el dinero entró en una moneda concreta.
 *    Sólo se les rellena el tipo de cambio si les faltaba, para que sumen en la base nueva.
 */
final class PmsMonedaBaseService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MonedaResolver $monedaResolver,
        private readonly TipoCambioDelDia $tipoCambioDelDia,
        private readonly MonedaBaseRebaseContext $rebaseContext,
    ) {}

    /**
     * @param string      $monedaId    Moneda base nueva (ej. 'PEN').
     * @param string|null $tipoCambio  Venta USD→PEN a aplicar; si no llega, la del día.
     */
    public function cambiar(PmsInformacionFinanciera $info, string $monedaId, ?string $tipoCambio = null): void
    {
        if (!$info->isMonedaBaseEditable()) {
            throw new DomainException(
                'La moneda base sólo se puede cambiar en reservas directas puras. '
                . 'Esta reserva tiene cargos sincronizados desde el canal, que mandan su propia moneda.'
            );
        }

        $destino = $this->monedaResolver->resolve($monedaId);
        $origenId = $info->getMoneda()?->getId();

        if ($origenId === $destino->getId()) {
            return; // no-op: ya está en esa moneda
        }

        $tc = $tipoCambio ?: $this->tipoCambioDelDia->venta();
        if (!$tc || (float) $tc <= 0) {
            throw new DomainException(
                'No se pudo obtener el tipo de cambio del día. Introdúcelo a mano para cambiar la moneda base.'
            );
        }

        $this->rebaseContext->durante(function () use ($info, $destino, $tc): void {
            foreach ($info->getCargos() as $cargo) {
                $monedaCargo = $cargo->getMoneda()?->getId();
                if ($monedaCargo === $destino->getId()) {
                    continue;
                }

                $importe = (float) ($cargo->getTotalLinea() ?? $cargo->getMonto() ?? '0');
                $convertido = $this->convertir($importe, $monedaCargo, $destino->getId(), (float) $tc);
                if ($convertido === null) {
                    continue; // par no soportado: se deja como estaba antes de inventar nada
                }

                $cargo->setDescripcion($this->anotarOriginal(
                    $cargo->getDescripcion(),
                    $importe,
                    $monedaCargo,
                    $tc
                ));

                $nuevo = number_format($convertido, 2, '.', '');
                $cargo->setMonto($nuevo);
                $cargo->setTotalLinea($nuevo);
                $cargo->setMoneda($destino);
                // Ya está en la moneda base: sin conversión pendiente, el TC deja de aplicar.
                $cargo->setTipoCambio(null);
            }

            // Los pagos NO se convierten. Sólo se les da el TC que les falte, para que el rollup
            // pueda expresarlos en la base nueva en lugar de contarlos como 0 (§12.2).
            foreach ($info->getPagos() as $pago) {
                if ($pago->getMoneda()?->getId() !== $destino->getId() && !$pago->getTipoCambio()) {
                    $pago->setTipoCambio($tc);
                }
            }

            $info->setMoneda($destino);

            // El listener de coherencia recalcula los totales en el postFlush de este mismo flush.
            $this->em->flush();
        });
    }

    /** Conversión puntual USD↔PEN. Devuelve null en pares no soportados (no se inventa cruzada). */
    private function convertir(float $importe, ?string $origen, ?string $destino, float $tc): ?float
    {
        if ($origen === null || $destino === null || $tc <= 0) {
            return null;
        }
        if ($origen === 'USD' && $destino === 'PEN') {
            return $importe * $tc;
        }
        if ($origen === 'PEN' && $destino === 'USD') {
            return $importe / $tc;
        }

        return null;
    }

    /**
     * Deja constancia del importe original en la descripción.
     *
     * Es la traza que permite auditar un cargo reescrito sin montar una tabla de histórico: el
     * importe en soles es el que manda, pero se puede ver de dónde salió. Se evita duplicar la
     * anotación si el cargo ya la tiene (rebase de ida y vuelta).
     */
    private function anotarOriginal(?string $descripcion, float $importe, ?string $moneda, string $tc): string
    {
        $base = trim((string) $descripcion);
        $nota = sprintf('(orig. %s %s · TC %s)', $moneda ?? '?', number_format($importe, 2, '.', ''), $tc);

        if (str_contains($base, '(orig. ')) {
            $base = trim((string) preg_replace('/\s*\(orig\. [^)]*\)/', '', $base));
        }

        return $base === '' ? $nota : $base . ' ' . $nota;
    }
}
