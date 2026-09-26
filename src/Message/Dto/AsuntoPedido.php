<?php

declare(strict_types=1);

namespace App\Message\Dto;

use App\Dto\Lee;

/**
 * El asunto que manda el chat en el cuerpo de una petición: `{"contextType": "pms_reserva",
 * "contextId": "…"}`.
 *
 * Lo leen dos endpoints con los mismos nombres —abrir un hilo sobre un asunto
 * (`AbrirConversacionController`) y cambiarle el titular (`AsuntosDeConversacionController::
 * cambiarTitular()`)—, y los dos lo leían con su copia de `trim((string) ($cuerpo['…'] ?? ''))`.
 *
 * Los dos identificadores son **opacos** aquí: qué es un `pms_reserva` lo sabe el dominio que lo
 * resuelve, no este objeto. Ver `CLAUDE.md` → «Dominios y contratos».
 */
final readonly class AsuntoPedido
{
    public function __construct(
        /** `pms_reserva`, `cotizacion_file`…; recortado, y `''` si no llegó. */
        public string $tipo,
        /** El id del asunto en su dominio; recortado, y `''` si no llegó. */
        public string $id,
    ) {}

    /** @param array<mixed> $cuerpo */
    public static function fromArray(array $cuerpo): self
    {
        // `texto()` + `trim()` y `''` por defecto: la misma lectura que el `trim((string) …)` al
        // que sustituye, salvo que un array ya no se convierte en la palabra «Array».
        return new self(
            tipo: trim(Lee::texto($cuerpo['contextType'] ?? null) ?? ''),
            id: trim(Lee::texto($cuerpo['contextId'] ?? null) ?? ''),
        );
    }

    /** Si faltan cualquiera de los dos, no hay asunto: los endpoints contestan 400. */
    public function estaIncompleto(): bool
    {
        return $this->tipo === '' || $this->id === '';
    }
}
