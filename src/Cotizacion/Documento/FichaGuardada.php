<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

use DateTimeImmutable;

/**
 * Lo que el manifiesto ya dice de una persona, en primitivas.
 *
 * ⚠️ **En primitivas y no la entidad, a propósito.** El cotejo es la única parte del proceso que
 * decide algo, y aquí no hay base de datos en los tests: pasarle entidades de Doctrine lo dejaría
 * fuera de la suite, que es justo donde tiene que estar. Quien traduzca entidad → esto es el
 * servicio, en un solo sitio.
 */
final readonly class FichaGuardada
{
    public function __construct(
        public ?string $numero = null,
        public ?string $tipo = null,
        public ?DateTimeImmutable $vencimiento = null,
        public ?string $nombreCompleto = null,
    ) {}

    /** Sin número guardado no hay nada contra lo que cotejar: la ficha está a medias. */
    public function tieneNumero(): bool
    {
        return $this->numero !== null && trim($this->numero) !== '';
    }
}
