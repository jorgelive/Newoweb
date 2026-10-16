<?php

declare(strict_types=1);

namespace App\Cotizacion\Dto;

use Symfony\Component\Serializer\Annotation\Groups;

/**
 * Una propuesta del expediente vista desde la cuenta: cuánto vale, cuánto se le ha pagado y cuánto
 * falta, en USD. La arma {@see CuentaDelExpediente::propuestas()} con la MISMA regla de lo pagado
 * que el saldo y que pax, y la usa el panel de enlaces de pago de util para saber cuánto pedir.
 *
 * 🪞 Espejo: `util/src/types/fileDetalleModel.ts` (del esquema generado).
 */
final readonly class PropuestaDeLaCuenta
{
    public function __construct(
        #[Groups(['file:item:read'])]
        public int $propuesta,

        /** La fila que se cobra: la confirmada si la hay; si no, la publicada. Es el `origenId` de sus enlaces. */
        #[Groups(['file:item:read'])]
        public string $cotizacionId,

        #[Groups(['file:item:read'])]
        public string $estado,

        #[Groups(['file:item:read'])]
        public float $totalUsd,

        #[Groups(['file:item:read'])]
        public float $pagadoUsd,

        /** Lo que falta; nunca negativo (lo cobrado de más se ve en la cuenta del expediente). */
        #[Groups(['file:item:read'])]
        public float $saldoUsd,

        #[Groups(['file:item:read'])]
        public float $porcentajeAdelanto,
    ) {
    }
}
