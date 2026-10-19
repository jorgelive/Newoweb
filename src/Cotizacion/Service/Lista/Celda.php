<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Lista;

/**
 * Lo que va en una celda de una plantilla ajena, dicho como lo guardaría Excel al teclearlo.
 *
 * - `texto`: va a la tabla de textos compartidos, como cualquier texto tecleado.
 * - `numero`: un número en una celda de formato general.
 * - `calculada`: la celda TIENE una fórmula de la plantilla; se le deja y se le pone el resultado
 *   que Excel guardaría. Ver `RellenadorDePlantillaXlsx`.
 */
final readonly class Celda
{
    private function __construct(
        public TipoDeCelda $tipo,
        public string|int|float $valor,
    ) {
    }

    public static function texto(string $valor): self
    {
        return new self(TipoDeCelda::Texto, $valor);
    }

    public static function numero(int|float $valor): self
    {
        return new self(TipoDeCelda::Numero, $valor);
    }

    public static function calculada(string|int|float $resultado): self
    {
        return new self(TipoDeCelda::Calculada, $resultado);
    }
}
