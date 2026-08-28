<?php

declare(strict_types=1);

namespace App\Pms\Finanzas;

/** Lo que se debe en UNA moneda. Nunca se convierte a otra: ver §12.2b. */
final readonly class PmsImporteMoneda
{
    public function __construct(
        public string $moneda,
        public ?string $simbolo,
        /** El neto: lo que abona la reserva. */
        public string $importe,
        /**
         * Equivalencia en soles, **sólo presentación**.
         *
         * `null` cuando no procede: o la moneda ya es PEN, o no sabemos que el huésped pague
         * desde Perú. Ojo — `PmsProcedenciaHuesped::pagaDesdePeru()` es TERNARIA y su `null`
         * significa «no se sabe»: ahí no se pone equivalencia, porque una conversión a quien
         * no paga en soles confunde más de lo que ayuda.
         *
         * No toca el saldo ni la contabilidad. Es el mismo criterio que el «son unos S/ 340»
         * del formulario de cargo.
         */
        public ?string $enSoles,
    ) {
    }
}
