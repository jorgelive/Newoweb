<?php

declare(strict_types=1);

namespace App\Api\Provider\Pms;

use ApiPlatform\Doctrine\Orm\State\ItemProvider;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsReserva;
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
        ];

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

        // Canal que no cobra por nosotros: los agregados cacheados de la cabecera
        // ya son los correctos y no hay nada que recortar.
        if ($canal === null || !in_array($canal, PmsChannel::CANAL_PAGO_TOTAL, true)) {
            return [
                'total'  => $finanzas->getTotalCargos(),
                'pagado' => $finanzas->getTotalPagos(),
                'saldo'  => $finanzas->getSaldo(),
            ];
        }

        // Mismo criterio de importe que PmsInformacionFinanciera::totalPorTipo():
        // `esCargo()` porque la colección de cargos también trae filas `payment`
        // de Beds24, y `totalLinea ?? monto` porque el webhook no siempre manda
        // la primera. Sumar aquí de otra forma daría cifras que no cuadran con
        // las del panel interno.
        $total = 0.0;
        foreach ($finanzas->getCargos() as $cargo) {
            if ($cargo->isEsAutomatico() || !$cargo->esCargo()) {
                continue;
            }
            $total += (float) ($cargo->getTotalLinea() ?? $cargo->getMonto() ?? '0');
        }

        $pagado = 0.0;
        foreach ($finanzas->getPagos() as $pago) {
            if ($pago->isEsAutomatico()) {
                continue;
            }
            $pagado += (float) ($pago->getMonto() ?? '0');
        }

        // Todo lo que había era el espejo del canal: no queda nada que enseñar.
        // La barra llena es puro acuse de recibo ("está cobrado"), sin importes.
        if ($total <= 0.0) {
            return ['soloProgreso' => true];
        }

        return [
            'total'  => number_format($total, 2, '.', ''),
            'pagado' => number_format($pagado, 2, '.', ''),
            'saldo'  => number_format($total - $pagado, 2, '.', ''),
        ];
    }
}
