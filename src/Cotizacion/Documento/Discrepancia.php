<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

/**
 * Un campo en el que **el manifiesto no dice lo mismo que el documento**.
 *
 * 🔑 **Estructurada y no una frase, porque la pantalla la pinta AL LADO del campo.** Una cadena
 * como «el número leído no es el guardado» obliga al front a adivinar de qué campo habla para
 * saber dónde ponerla; con `campo` separado, cada aviso va donde se corrige, y el texto se compone
 * al pintar.
 *
 * ⚠️ Guarda **los dos valores**, no sólo el aviso. Sin ellos, quien mira la cola tiene que abrir
 * el escaneo para saber qué decía — y en el caso más frecuente de este expediente, un dedazo en el
 * año (`2026` por `2036`), verlos uno al lado del otro **es** la resolución.
 */
final readonly class Discrepancia
{
    public function __construct(
        /** `numero`, `nombre`, `vencimiento`, `nacimiento`, `nacionalidad`, `tipo`. */
        public string $campo,
        /** Lo que dice el documento escaneado. */
        public string $documento,
        /** Lo que hay guardado en el manifiesto. */
        public string $manifiesto,
    ) {}

    /** «número no coincide». El detalle son los dos valores, que van aparte. */
    public function titulo(): string
    {
        return sprintf('%s no coincide', $this->campo);
    }

    /** @return array{campo: string, documento: string, manifiesto: string} */
    public function aJson(): array
    {
        return ['campo' => $this->campo, 'documento' => $this->documento, 'manifiesto' => $this->manifiesto];
    }

    /** @param array<string, mixed> $fila */
    public static function deJson(array $fila): self
    {
        return new self(
            is_string($fila['campo'] ?? null) ? $fila['campo'] : '',
            is_string($fila['documento'] ?? null) ? $fila['documento'] : '',
            is_string($fila['manifiesto'] ?? null) ? $fila['manifiesto'] : '',
        );
    }
}
