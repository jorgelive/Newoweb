<?php

declare(strict_types=1);

namespace App\Service\Front;

use App\Dto\Lee;

/**
 * El `manifest.json` que escribe `vite build`, leído una vez para `PaxAppController` y
 * `UtilAppController`.
 *
 * Cada clave es un fichero de entrada (`src/main.ts`) y su valor dice qué JS y qué CSS le tocaron
 * tras el hash. Los dos controladores lo recorrían a mano sobre un `json_decode()` sin forma; una
 * entrada sin `file` era un `null` en la plantilla y una página en blanco sin ningún error. Aquí esa
 * entrada, sencillamente, no existe.
 */
final readonly class ManifiestoVite
{
    /** @param array<string, array{file: string, css: list<string>}> $entradas */
    private function __construct(private array $entradas) {}

    public static function desdeJson(string $json): self
    {
        $crudo = json_decode($json, true);
        $entradas = [];

        foreach (is_array($crudo) ? $crudo : [] as $clave => $entrada) {
            $fichero = Lee::texto(Lee::en($entrada, 'file'));

            if (!is_string($clave) || $fichero === null) {
                continue;
            }

            $entradas[$clave] = ['file' => $fichero, 'css' => Lee::listaDeTextos(Lee::en($entrada, 'css'))];
        }

        return new self($entradas);
    }

    /** @return array{file: string, css: list<string>}|null */
    public function entrada(string $clave): ?array
    {
        return $this->entradas[$clave] ?? null;
    }

    /** @return list<string> */
    public function claves(): array
    {
        return array_keys($this->entradas);
    }
}
