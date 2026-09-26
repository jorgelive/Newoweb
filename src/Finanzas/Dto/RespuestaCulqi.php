<?php

declare(strict_types=1);

namespace App\Finanzas\Dto;

use App\Dto\Lee;

/**
 * Lo que devuelve Culqi: un cargo (`object: charge`) o un cuerpo de error. Un solo DTO para los
 * dos porque Culqi mezcla las formas — el reto 3DS llega como 200 con un cuerpo que no es cargo—
 * y quien lo lee (`CulqiClient`, `CulqiRechazoException`) necesita mirar los campos de los dos.
 *
 * ⚠️ **El veredicto está en `outcome.type`, no en `object`.** Un cargo denegado también es un
 * `charge`; ver `CulqiClient::cargoPagaElEnlace()`. Por eso los campos de `outcome` van aparte de
 * los de la raíz: un error trae `code` en la raíz y un cargo denegado lo trae en `outcome.code`.
 *
 * Se lee con {@see Lee::texto()} —tal cual, sin recortar— porque sustituye lecturas crudas sobre
 * el dinero y no debe cambiar ni un carácter de lo que se compara. Ver `docs/FinanzasEnlacesPago.md`.
 */
final readonly class RespuestaCulqi
{
    /**
     * @param array<mixed> $crudo El cuerpo entero, para auditoría y logs (sin datos del titular:
     *        eso lo quita `FinCobroAuditor::sinDatosDelTitular()` al escribirlo).
     */
    public function __construct(
        public ?string $objeto,
        public ?string $id,
        /** `REVIEW` cuando Culqi pide el reto 3DS en lugar de cobrar. */
        public ?string $actionCode,
        public ?string $resultadoTipo,
        public ?string $resultadoCodigo,
        public ?string $resultadoMotivoComercio,
        public ?string $resultadoCodigoRechazo,
        public ?string $codigo,
        public ?string $motivoComercio,
        public ?string $codigoRechazo,
        public ?string $mensajeUsuario,
        public ?int $importeCentimos,
        public ?string $moneda,
        public ?string $codigoReferencia,
        public ?string $marcaTarjeta,
        public ?string $ultimosCuatro,
        public array $crudo,
    ) {}

    /** @param array<mixed> $cuerpo */
    public static function fromArray(array $cuerpo): self
    {
        $resultado = Lee::mapa($cuerpo['outcome'] ?? null);
        $tarjeta = Lee::mapa($cuerpo['source'] ?? null);

        return new self(
            objeto: Lee::texto($cuerpo['object'] ?? null),
            id: Lee::texto($cuerpo['id'] ?? null),
            actionCode: Lee::texto($cuerpo['action_code'] ?? null),
            resultadoTipo: Lee::texto($resultado['type'] ?? null),
            resultadoCodigo: Lee::texto($resultado['code'] ?? null),
            resultadoMotivoComercio: Lee::texto($resultado['merchant_message'] ?? null),
            resultadoCodigoRechazo: Lee::texto($resultado['decline_code'] ?? null),
            codigo: Lee::texto($cuerpo['code'] ?? null),
            motivoComercio: Lee::texto($cuerpo['merchant_message'] ?? null),
            codigoRechazo: Lee::texto($cuerpo['decline_code'] ?? null),
            mensajeUsuario: Lee::texto($cuerpo['user_message'] ?? null),
            importeCentimos: self::centimos($cuerpo['amount'] ?? null),
            moneda: Lee::texto($cuerpo['currency_code'] ?? null),
            codigoReferencia: Lee::texto($cuerpo['reference_code'] ?? null),
            marcaTarjeta: Lee::texto(Lee::en($tarjeta, 'iin', 'card_brand')),
            ultimosCuatro: Lee::texto($tarjeta['last_four'] ?? null),
            crudo: $cuerpo,
        );
    }

    public function esCargo(): bool
    {
        return $this->objeto === 'charge';
    }

    /** El motivo que se enseña: el del usuario si lo hay, si no el del comercio. */
    public function detalle(): string
    {
        return $this->mensajeUsuario ?? $this->motivoComercio ?? 'sin cargo';
    }

    /**
     * El importe en céntimos, con la semántica del `(int)` al que sustituye: un `"10550.0"` son
     * 10 550 céntimos, no «no vino». Culqi manda un entero, pero si algún día llega con decimales
     * el fallo tiene que caer del lado de antes; con `Lee::entero()` era 0, y un cargo ya cobrado
     * se daba por «no corresponde al enlace» y el enlace se quedaba sin saldar.
     */
    private static function centimos(mixed $valor): ?int
    {
        $decimal = Lee::decimal($valor);

        return $decimal === null ? null : (int) $decimal;
    }
}
