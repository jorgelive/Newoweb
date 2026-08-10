<?php

declare(strict_types=1);

namespace App\Api\Provider\Pms;

use ApiPlatform\Doctrine\Orm\State\ItemProvider;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Finance\PmsPrepagoCalculador;
use App\Pms\Service\Finance\TipoCambioDelDia;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Decora el Get público de la reserva (`/client/pax/pms/pms_reserva/{localizador}`)
 * para añadirle el resumen del estado de cuenta que ve el huésped.
 *
 * La relación va de PmsInformacionFinanciera hacia la reserva (1:1 lógico con
 * JoinColumn unique), así que la entidad no puede calcularlo sola: hay que
 * buscar la cabecera aquí. `findOneBy` con el objeto tipa bien el UUID binario
 * — un SearchFilter sobre la relación devolvería vacío en silencio (§12.6 del
 * doc de sync).
 *
 * Solo viaja el AGREGADO (total, pagado, saldo, moneda): el árbol de cargos y
 * pagos es del panel interno. El recargo por tarjeta NO se calcula aquí — es
 * presentación, y el porcentaje vive con su espejo en la vista
 * (PmsReservaView::RECARGO_TARJETA_PCT).
 */
final class PmsReservaPaxProvider implements ProviderInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.item_provider')]
        private readonly ItemProvider $itemProvider,
        private readonly EntityManagerInterface $em,
        private readonly TipoCambioDelDia $tipoCambioDelDia,
        private readonly PmsPrepagoCalculador $prepagoCalculador,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?PmsReserva
    {
        $reserva = $this->itemProvider->provide($operation, $uriVariables, $context);

        if (!$reserva instanceof PmsReserva) {
            return $reserva;
        }

        $finanzas = $this->em->getRepository(PmsInformacionFinanciera::class)
            ->findOneBy(['reserva' => $reserva]);

        // Sin cabecera o sin cargos no hay nada que contar: la tarjeta no se pinta.
        // Con `activa = false` los caches ya solo suman la penalización (§12.7),
        // así que el saldo que sale de aquí sigue siendo el correcto.
        if ($finanzas === null || (float) $finanzas->getTotalCargos() <= 0) {
            return $reserva;
        }

        $base = [
            'moneda'  => $finanzas->getMoneda()?->getId() ?? 'USD',
            'simbolo' => $finanzas->getMoneda()?->getSimbolo(),
        ] + $this->referenciaSoles($finanzas);

        $reserva->setResumenFinancieroCliente($base + $this->cifras($finanzas));

        return $reserva;
    }

    /**
     * Total, pagado y saldo tal como los ve el HUÉSPED.
     *
     * En los canales que cobran por nosotros (Airbnb, VRBO) no se pueden enseñar
     * los importes del canal: lo que guardamos es lo que la OTA nos remite, no lo
     * que el huésped pagó —que incluye la comisión de servicio de la OTA—, y la
     * diferencia genera una conversación que nadie quiere tener.
     *
     * La exclusión va por MARCA EXPLÍCITA, no por heurística: `esAutomatico` en el
     * cargo (lo pone Beds24InvoiceReceivePersister) y en el pago (lo pone
     * PmsPagoOtaAutomaticoService). Emparejar "el cargo de subtipo 8 con el pago
     * del mismo importe" se rompía de dos formas: el Cancel Fee de Beds24 llega
     * con el MISMO subtipo 8 que el alojamiento (ver PmsTipoCargo::desdeBeds24),
     * así que habría escondido las penalizaciones; y dos cargos del mismo importe
     * confunden el emparejamiento.
     *
     * Resultado: un huésped de Airbnb sin extras ve `soloProgreso` y la barra al
     * 100 %, sin una sola cifra. Con extras ve SOLO esos, que son los que nos debe
     * a nosotros y sí puede reconocer.
     *
     * @return array<string, string|bool>
     */
    private function cifras(PmsInformacionFinanciera $finanzas): array
    {
        $canal = $finanzas->getReserva()?->getChannel()?->getId();
        $espejo = $canal !== null && in_array($canal, PmsChannel::CANAL_PAGO_TOTAL, true);

        // El desglose por tipo lo calcula la cabecera: ahí viven las tres reglas
        // (anulación §12.7, `esCargo()`, `totalLinea ?? monto`) y duplicarlas aquí
        // era garantía de que se separasen con el tiempo.
        $cargos = $finanzas->getDesglosePorTipo(excluirEspejoCanal: $espejo);
        $pagos  = $this->pagosVisibles($finanzas, $espejo);

        $total  = array_sum(array_map('floatval', $cargos));
        $pagado = array_sum(array_map(static fn (array $p): float => (float) $p['monto'], $pagos));

        // Todo lo que había era el espejo del canal: no queda nada que enseñar.
        // La barra llena es puro acuse de recibo ("está cobrado"), sin importes.
        if ($espejo && $total <= 0.0) {
            return ['soloProgreso' => true];
        }

        // En un canal normal mandan los agregados cacheados de la cabecera. Las
        // líneas ya vienen en la misma moneda base que ellos, así que suman: no
        // hay que recalcular nada aquí ni comprobar que cuadren.
        return [
            'total'  => $espejo ? number_format($total, 2, '.', '')  : $finanzas->getTotalCargos(),
            'pagado' => $espejo ? number_format($pagado, 2, '.', '') : $finanzas->getTotalPagos(),
            'saldo'  => $espejo ? number_format($total - $pagado, 2, '.', '') : $finanzas->getSaldo(),
            'cargos' => $cargos,
            // Detalle línea a línea, con la descripción redactada para el huésped. Es lo que
            // pinta la tarjeta; `cargos` (agrupado por tipo) se mantiene porque sigue siendo
            // el resumen barato para cualquier otro consumidor.
            'lineas' => $finanzas->getLineasCliente(excluirEspejoCanal: $espejo),
            'pagos'  => $pagos,

            // Prepago pendiente. Solo se pide a quien NO ha pagado todavía nada: si hay
            // algún pago registrado, ese pago ES el prepago —es lo primero que se cobra— y
            // volver a pedírselo sería reclamarle algo que ya hizo.
            //
            // Va después de `$pagado` a propósito: necesita saber si hay cobros, y esa
            // cifra ya está calculada arriba con las exclusiones del canal aplicadas.
            'prepago' => $pagado > 0.0 ? null : $this->prepagoCalculador->calcular($finanzas),
        ];
    }

    /**
     * Equivalencia REFERENCIAL en soles, para el conmutador de la tarjeta.
     *
     * Se manda un único tipo de cambio —el del día— para toda la tarjeta, y no el que cada
     * cargo tiene congelado. Con los TC históricos las líneas no sumarían el total
     * convertido, y esa descuadre es justo la conversación que la tarjeta quiere evitar.
     *
     * Por eso es **referencial y hay que decirlo en pantalla**: no es lo que se cobró ni lo
     * que se va a cobrar, es «cuánto viene siendo hoy». El cobro real sigue siendo en la
     * moneda de la cabecera.
     *
     * Si no hay TC del día o la cabecera ya está en soles, no se manda nada y el front
     * sencillamente no pinta el conmutador.
     *
     * @return array{tipoCambioReferencial?: string, monedaReferencial?: string, simboloReferencial?: string}
     */
    private function referenciaSoles(PmsInformacionFinanciera $finanzas): array
    {
        if (($finanzas->getMoneda()?->getId() ?? 'USD') === 'PEN') {
            return [];
        }

        $tc = $this->tipoCambioDelDia->venta();

        if ($tc === null || (float) $tc <= 0) {
            return [];
        }

        return [
            'tipoCambioReferencial' => $tc,
            'monedaReferencial' => 'PEN',
            'simboloReferencial' => 'S/.',
        ];
    }

    /**
     * Pagos que el huésped puede ver, del más antiguo al más reciente.
     *
     * Viaja el MEDIO (valor del enum, traducible en el front) y la FECHA, no las
     * notas ni la referencia: son campos libres del operador, en español y con
     * datos internos. Tampoco el `montoTotalCobrado`, que incluye la comisión de
     * tarjeta y no es lo que el huésped entregó.
     *
     * @return list<array{fecha: string|null, medio: string, monto: string}>
     */
    private function pagosVisibles(PmsInformacionFinanciera $finanzas, bool $excluirEspejoCanal): array
    {
        $pagos = [];

        foreach ($finanzas->getPagos() as $pago) {
            if ($excluirEspejoCanal && $pago->isEsAutomatico()) {
                continue;
            }

            $pagos[] = [
                'fecha' => $pago->getFechaPago()?->format('Y-m-d'),
                'medio' => $pago->getMedioPago()->value,
                // Convertido a la moneda de la cabecera, como el `total_pagos`
                // que se muestra debajo. Ver PmsInformacionFinanciera::aMonedaBase().
                'monto' => $finanzas->convertirAMonedaBase(
                    (float) $pago->getMonto(),
                    $pago->getMoneda()?->getId(),
                    $pago->getTipoCambio(),
                ),
            ];
        }

        usort($pagos, static fn (array $a, array $b): int => ($a['fecha'] ?? '') <=> ($b['fecha'] ?? ''));

        return $pagos;
    }
}
