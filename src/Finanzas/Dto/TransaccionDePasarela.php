<?php

declare(strict_types=1);

namespace App\Finanzas\Dto;

use App\Dto\Lee;

/**
 * La transacción de un cobro ya hecho, en la forma COMÚN a las dos pasarelas.
 *
 * Izipay la devuelve así (`transactions[0].transactionDetails.cardDetails…`) y Culqi se traduce a
 * ella en `CulqiClient::comoRespuestaNormalizada()`, así que `FinEnlacePagoService` lee UNA forma
 * venga el dinero de donde venga. Esta clase es esa forma, leída una vez.
 */
final readonly class TransaccionDePasarela
{
    public function __construct(
        public ?string $uuid,
        public ?string $autorizacion,
        public ?string $marca,
        public ?string $pan,
    ) {}

    /** La primera transacción de una respuesta normalizada; vacía si no trae ninguna. */
    public static function primeraDe(mixed $respuesta): self
    {
        $transaccion = Lee::mapa(Lee::en($respuesta, 'transactions', 0));
        $tarjeta = Lee::mapa(Lee::en($transaccion, 'transactionDetails', 'cardDetails'));

        return new self(
            uuid: Lee::texto($transaccion['uuid'] ?? null),
            autorizacion: Lee::texto(Lee::en($tarjeta, 'authorizationResponse', 'authorizationNumber')),
            marca: Lee::texto($tarjeta['effectiveBrand'] ?? null),
            pan: Lee::texto($tarjeta['pan'] ?? null),
        );
    }
}
