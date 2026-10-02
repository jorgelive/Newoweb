<?php

declare(strict_types=1);

namespace App\Message\Dto;

use App\Dto\Lee;

/**
 * «Este hilo y aquel parecen la misma persona»: lo que se apunta cuando un teléfono o un correo
 * llega por el dominio y ya es de otro hilo.
 *
 * Vive en la columna JSON `msg_conversation.fusion_sugerida`, que hidrata Doctrine sin pasar por
 * ningún setter: se lee SIEMPRE por {@see self::desde()}, y lo que no tenga la forma es «no hay».
 * Ver `docs/Mensajeria.md`, «El choque de identificadores que nadie veía».
 */
final readonly class FusionSugerida
{
    public function __construct(
        /** El otro hilo, el que ya tiene el identificador. */
        public string $con,
        /** Cómo se llamaba ese hilo al apuntarlo, para no tener que ir a buscarlo al pintar. */
        public ?string $nombre,
        /** `telefono` o `email` ({@see \App\Message\Enum\IdentidadTipo}). */
        public string $tipo,
        /** El identificador que chocó, ya normalizado. */
        public string $valor,
        /** Cuándo se vio el choque, ISO 8601. */
        public string $desde,
    ) {}

    public static function desde(mixed $datos): ?self
    {
        $con = Lee::texto(Lee::en($datos, 'con'));
        $tipo = Lee::texto(Lee::en($datos, 'tipo'));
        $valor = Lee::texto(Lee::en($datos, 'valor'));

        if ($con === null || $con === '' || $tipo === null || $valor === null) {
            return null;
        }

        return new self($con, Lee::texto(Lee::en($datos, 'nombre')), $tipo, $valor, Lee::texto(Lee::en($datos, 'desde')) ?? '');
    }

    /** @return array{con: string, nombre: string|null, tipo: string, valor: string, desde: string} */
    public function aArray(): array
    {
        return ['con' => $this->con, 'nombre' => $this->nombre, 'tipo' => $this->tipo, 'valor' => $this->valor, 'desde' => $this->desde];
    }
}
