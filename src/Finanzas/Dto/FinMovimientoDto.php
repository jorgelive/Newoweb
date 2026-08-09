<?php

declare(strict_types=1);

namespace App\Finanzas\Dto;

/**
 * Un cobro recibido, en el vocabulario de Finanzas y sin entidades de nadie.
 *
 * Es el equivalente de `FinOrigenCobroDto` para la vista de caja: los módulos traducen
 * aquí sus propios registros de pago (el PMS, su `PmsPagoFinanciero`) para que Finanzas
 * pueda listarlos juntos sin conocer ninguno.
 *
 * **No lleva importe convertido a una moneda común.** Convertir aquí obligaría a Finanzas
 * a elegir un tipo de cambio, y el que vale es el del día de cada pago —que el módulo ya
 * guardó—. Se expone `tipoCambio` tal cual y los totales se agrupan POR MONEDA: es la
 * misma decisión que ya toma el panel financiero de la reserva, y evita que dos pantallas
 * den cifras distintas por redondear en sitios distintos.
 */
final readonly class FinMovimientoDto
{
    /**
     * @param string      $id                Identificador en el módulo de origen (para navegar).
     * @param string      $fecha             `Y-m-d`. Fecha del cobro, no la de registro.
     * @param string      $medioCodigo       Valor crudo del medio en su módulo ('efectivo', 'tarjeta_credito'…).
     * @param string      $medioEtiqueta     Etiqueta ya traducida. La pone el módulo: es su vocabulario.
     * @param string      $monto             Importe NETO imputado, 2 decimales, en `moneda`.
     * @param string      $moneda            ISO 4217 alfa-3.
     * @param string|null $tipoCambio        TC del día del pago si el módulo lo guardó; null si no aplica.
     * @param string|null $referencia        Nº de operación, voucher, uuid de transacción…
     * @param string      $origenTipo        Valor de `FinOrigenCobro`.
     * @param string      $origenId          UUID del documento al que se imputó.
     * @param string|null $origenReferencia  Localizador legible del documento.
     * @param string      $origenDescripcion Texto corto para la fila ("Casita 7, 12-15 ago").
     * @param string|null $cobradorNombre    Quién recibió el dinero en mano, si aplica.
     * @param bool        $esAutomatico      Lo generó el sistema (depósito de OTA, cobro por pasarela).
     */
    public function __construct(
        public string $id,
        public string $fecha,
        public string $medioCodigo,
        public string $medioEtiqueta,
        public string $monto,
        public string $moneda,
        public ?string $tipoCambio,
        public ?string $referencia,
        public string $origenTipo,
        public string $origenId,
        public ?string $origenReferencia,
        public string $origenDescripcion,
        public ?string $cobradorNombre,
        public bool $esAutomatico,
    ) {}

    /** @return array<string, mixed> Espejo en TS: `util/src/types/finMovimientoModel.ts`. */
    public function aArray(): array
    {
        return [
            'id' => $this->id,
            'fecha' => $this->fecha,
            'medioCodigo' => $this->medioCodigo,
            'medioEtiqueta' => $this->medioEtiqueta,
            'monto' => $this->monto,
            'moneda' => $this->moneda,
            'tipoCambio' => $this->tipoCambio,
            'referencia' => $this->referencia,
            'origenTipo' => $this->origenTipo,
            'origenId' => $this->origenId,
            'origenReferencia' => $this->origenReferencia,
            'origenDescripcion' => $this->origenDescripcion,
            'cobradorNombre' => $this->cobradorNombre,
            'esAutomatico' => $this->esAutomatico,
        ];
    }
}
