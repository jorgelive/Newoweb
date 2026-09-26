<?php

declare(strict_types=1);

namespace App\Finanzas\Dto;

use App\Dto\Lee;

/**
 * Lo que interesa de un aviso de pasarela (webhook de Culqi, IPN de Izipay): a qué enlace nuestro
 * se refiere y, en Culqi, qué cargo hay que ir a verificar.
 *
 * ⚠️ **Del aviso sólo se toman identificadores, nunca el veredicto.** El estado del cobro se
 * pregunta a la pasarela por su API (Culqi) o se valida por firma (Izipay); un cuerpo de webhook
 * que dijera «pagado» no salda nada. Ver `docs/FinanzasEnlacesPago.md`.
 *
 * Cada pasarela envuelve distinto, y las dos formas se leen aquí y en ningún otro sitio:
 *
 * | | enlace | orden | cargo |
 * |---|---|---|---|
 * | Culqi | `data.metadata.enlaceId` ?? `metadata.enlaceId` | `data.metadata.ordenId` | el primer `chr_…` de `data.id`, `object.id`, `id` |
 * | Izipay | `transactions[0].metadata.enlaceId` ?? `metadata.enlaceId` | `orderDetails.orderId` ?? `orderId` | — |
 */
final readonly class AvisoDePasarela
{
    public function __construct(
        public ?string $enlaceId,
        public ?string $ordenId,
        public ?string $idCargo,
    ) {}

    /** @param array<mixed> $payload */
    public static function deCulqi(array $payload): self
    {
        $idCargo = null;
        foreach ([Lee::en($payload, 'data', 'id'), Lee::en($payload, 'object', 'id'), $payload['id'] ?? null] as $candidato) {
            // Los cargos de Culqi son `chr_...`; descartar el resto evita ir a preguntar por el id
            // del propio evento y llevarse un 404.
            if (is_string($candidato) && str_starts_with($candidato, 'chr_')) {
                $idCargo = $candidato;
                break;
            }
        }

        return new self(
            enlaceId: Lee::texto(Lee::en($payload, 'data', 'metadata', 'enlaceId') ?? Lee::en($payload, 'metadata', 'enlaceId')),
            ordenId: Lee::texto(Lee::en($payload, 'data', 'metadata', 'ordenId')),
            idCargo: $idCargo,
        );
    }

    /** @param array<mixed> $respuesta El `kr-answer` ya decodificado. */
    public static function deIzipay(array $respuesta): self
    {
        return new self(
            enlaceId: Lee::texto(Lee::en($respuesta, 'transactions', 0, 'metadata', 'enlaceId') ?? Lee::en($respuesta, 'metadata', 'enlaceId')),
            ordenId: Lee::texto(Lee::en($respuesta, 'orderDetails', 'orderId') ?? ($respuesta['orderId'] ?? null)),
            idCargo: null,
        );
    }
}
