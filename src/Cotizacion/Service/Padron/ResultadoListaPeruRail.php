<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Padron;

/**
 * Lo que sale de {@see ListaPeruRail}: el archivo y quién quedó con huecos.
 */
final readonly class ResultadoListaPeruRail
{
    /**
     * @param list<string> $incompletos «Apellido Nombre: lo que falta», uno por persona
     */
    public function __construct(
        public string $contenido,
        public string $nombreArchivo,
        public bool $esZip,
        public int $personas,
        public array $incompletos,
    ) {
    }
}
