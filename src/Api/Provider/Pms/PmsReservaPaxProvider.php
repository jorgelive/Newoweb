<?php

declare(strict_types=1);

namespace App\Api\Provider\Pms;

use ApiPlatform\Doctrine\Orm\State\ItemProvider;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
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

        $reserva->setResumenFinancieroCliente([
            'moneda'  => $finanzas->getMoneda()?->getId() ?? 'USD',
            'simbolo' => $finanzas->getMoneda()?->getSimbolo(),
            'total'   => $finanzas->getTotalCargos(),
            'pagado'  => $finanzas->getTotalPagos(),
            'saldo'   => $finanzas->getSaldo(),
        ]);

        return $reserva;
    }
}
