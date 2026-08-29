<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Vuelos;

/**
 * Qué pasó (o pasaría) al importar vuelos. Lo lee igual la consola que el formulario.
 *
 * Separa **cambios** de **problemas** a propósito: un cambio se enseña para que quien lo lanza
 * decida, y un problema es algo que no se hizo. Mezclarlos deja al que mira sin saber si el
 * archivo se aplicó entero.
 */
final class ResultadoVuelos
{
    /** @var list<string> */
    public array $cambios = [];

    /** @var list<string> */
    public array $problemas = [];

    /** @var list<string> */
    public array $avisos = [];

    public int $sinCambios = 0;

    public function cambio(string $linea): void
    {
        $this->cambios[] = $linea;
    }

    public function problema(string $linea): void
    {
        $this->problemas[] = $linea;
    }

    public function aviso(string $linea): void
    {
        $this->avisos[] = $linea;
    }

    public function hayAlgoQueHacer(): bool
    {
        return $this->cambios !== [];
    }
}
