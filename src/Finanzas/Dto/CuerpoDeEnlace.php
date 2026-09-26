<?php

declare(strict_types=1);

namespace App\Finanzas\Dto;

use App\Dto\Lee;
use App\Finanzas\Enum\FinOrigenCobro;
use App\Finanzas\Enum\FinPasarela;
use Symfony\Component\Uid\Uuid;
use ValueError;

/**
 * Lo que manda el panel al emitir un enlace de pago —con documento o manual—, leído una vez.
 *
 * Los dos endpoints de `FinEnlacePagoApiController` comparten nombres de campo, así que comparten
 * lectura; cada uno toma los que le tocan. La obligatoriedad NO se decide aquí sino en
 * `FinEnlacePagoService`, que es quien sabe qué exige cada tipo de cobro.
 *
 * ⚠️ **`conRecargo` se lee como booleano de verdad.** Antes era `(bool) ($datos['conRecargo'] ??
 * true)`, y `(bool) "false"` es `true`: un `"false"` en texto emitía el cobro CON recargo. El panel
 * manda un booleano JSON y no pasaba, pero es dinero y la lectura tiene que decir lo que dice.
 */
final readonly class CuerpoDeEnlace
{
    public function __construct(
        public ?FinOrigenCobro $origenTipo,
        public ?Uuid $origenId,
        /** El NETO, como texto decimal: el servicio lo trata con bcmath y no se pasa por float. */
        public ?string $monto,
        public ?string $moneda,
        public ?string $concepto,
        public bool $conRecargo,
        public ?int $vigenciaDias,
        public ?FinPasarela $pasarela,
        public ?FinOrigenCobro $modulo,
        public ?string $clienteNombre,
        public ?string $clienteApellido,
        public ?string $clienteEmail,
        public ?string $clienteTelefono,
        public ?string $referencia,
    ) {}

    /** @param array<mixed> $datos */
    public static function fromArray(array $datos): self
    {
        $origenId = Lee::texto($datos['origenId'] ?? null);

        return new self(
            origenTipo: self::origen($datos['origenTipo'] ?? null),
            origenId: $origenId !== null && Uuid::isValid($origenId) ? Uuid::fromString($origenId) : null,
            monto: Lee::texto($datos['monto'] ?? null),
            moneda: Lee::textoLimpio($datos['moneda'] ?? null),
            concepto: Lee::texto($datos['concepto'] ?? null),
            conRecargo: Lee::booleano($datos['conRecargo'] ?? null) ?? true,
            vigenciaDias: Lee::entero($datos['vigenciaDias'] ?? null),
            pasarela: FinPasarela::tryFrom(Lee::texto($datos['pasarela'] ?? null) ?? ''),
            modulo: self::origen($datos['modulo'] ?? null),
            clienteNombre: Lee::textoLimpio($datos['clienteNombre'] ?? null),
            clienteApellido: Lee::textoLimpio($datos['clienteApellido'] ?? null),
            clienteEmail: Lee::textoLimpio($datos['clienteEmail'] ?? null),
            clienteTelefono: Lee::textoLimpio($datos['clienteTelefono'] ?? null),
            referencia: Lee::textoLimpio($datos['referencia'] ?? null),
        );
    }

    private static function origen(mixed $valor): ?FinOrigenCobro
    {
        $texto = Lee::texto($valor);
        if ($texto === null || $texto === '') {
            return null;
        }

        try {
            return FinOrigenCobro::from($texto);
        } catch (ValueError) {
            return null;
        }
    }
}
