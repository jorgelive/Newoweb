<?php

declare(strict_types=1);

namespace App\Travel\Dto;

use App\Dto\Lee;
use InvalidArgumentException;

/**
 * La ficha de un hotel tal como la recibe `app:travel:cargar-hotel` desde un JSON.
 *
 * ```json
 * {
 *   "buscar": ["Qelqatani Hotel - Puno"],      // nombres con los que ya podría existir
 *   "nombre": "Qelqatani Hotel - Puno",         // nombre comercial (interno)
 *   "titulo": "Qelqatani Hotel",                // lo que ve el cliente
 *   "descripcion": "…", "url": "…", "direccion": "…",
 *   "telefono": "+51 …", "email": "…",          // opcionales: de aquí salen las órdenes
 *   "fotos": ["fachada.jpg", …],                // la primera es la portada
 *   "habitaciones": [{"nombre": "…", "descripcion": "…", "fotos": ["…"]}]
 * }
 * ```
 *
 * Todo lo que entra del archivo se tipa aquí y en ningún otro sitio (`docs/TiposDeFrontera.md`).
 * Un campo obligatorio vacío es un error con nombre, no una ficha a medias.
 */
final readonly class HotelACargar
{
    /**
     * @param list<string>                                                              $buscar
     * @param list<string>                                                              $fotos
     * @param list<array{nombre: string, descripcion: string, fotos: list<string>}> $habitaciones
     */
    public function __construct(
        public array $buscar,
        public string $nombre,
        public string $titulo,
        public string $descripcion,
        public ?string $url,
        public ?string $direccion,
        public ?string $telefono,
        public ?string $email,
        public array $fotos,
        public array $habitaciones,
    ) {}

    /** @param array<mixed> $datos */
    public static function fromArray(array $datos): self
    {
        $obligatorio = static fn (string $campo, mixed $valor): string => Lee::textoLimpio($valor)
            ?? throw new InvalidArgumentException(sprintf('Falta «%s» en la ficha del hotel.', $campo));

        $nombre = $obligatorio('nombre', $datos['nombre'] ?? null);

        $habitaciones = [];
        foreach (Lee::listaDeMapas($datos['habitaciones'] ?? null) as $i => $h) {
            $habitaciones[] = [
                'nombre' => $obligatorio(sprintf('habitaciones[%d].nombre', $i), $h['nombre'] ?? null),
                'descripcion' => $obligatorio(sprintf('habitaciones[%d].descripcion', $i), $h['descripcion'] ?? null),
                'fotos' => Lee::listaDeTextos($h['fotos'] ?? null),
            ];
        }

        return new self(
            buscar: array_values(array_unique([...Lee::listaDeTextos($datos['buscar'] ?? null), $nombre])),
            nombre: $nombre,
            titulo: $obligatorio('titulo', $datos['titulo'] ?? null),
            descripcion: $obligatorio('descripcion', $datos['descripcion'] ?? null),
            url: Lee::textoLimpio($datos['url'] ?? null),
            direccion: Lee::textoLimpio($datos['direccion'] ?? null),
            telefono: Lee::textoLimpio($datos['telefono'] ?? null),
            email: Lee::textoLimpio($datos['email'] ?? null),
            fotos: Lee::listaDeTextos($datos['fotos'] ?? null),
            habitaciones: $habitaciones,
        );
    }
}
