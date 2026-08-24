<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Padron;

/**
 * Qué pasó al leer un padrón.
 *
 * Se devuelve igual en el ensayo y en la carga real: la única diferencia entre los dos es si se
 * hace `flush()`. Así lo que se enseña antes de aceptar es **exactamente** lo que va a ocurrir, y
 * no una estimación parecida.
 */
final class ResultadoDelPadron
{
    /** @var list<string> */
    public array $avisos = [];

    /** @var list<string> */
    public array $errores = [];

    public int $filasLeidas = 0;
    public int $pasajerosCreados = 0;
    public int $pasajerosActualizados = 0;
    public int $identificacionesCreadas = 0;
    public int $gruposCreados = 0;
    public int $pertenenciasCreadas = 0;
    public int $pertenenciasQuitadas = 0;

    /**
     * Gente que está en el sistema y NO en el archivo.
     *
     * ⚠️ **No se borra: se avisa.** Una fila que falta puede ser una baja o puede ser que alguien
     * filtró el Excel antes de mandarlo, y borrar a 40 personas por un filtro mal puesto no se
     * deshace. Ver `CLAUDE.md`: «No se borra: se marca».
     *
     * @var list<string>
     */
    public array $noEstanEnElArchivo = [];

    public function aviso(string $texto): void
    {
        if (!in_array($texto, $this->avisos, true)) {
            $this->avisos[] = $texto;
        }
    }

    public function error(string $texto): void
    {
        $this->errores[] = $texto;
    }

    public function tieneErrores(): bool
    {
        return $this->errores !== [];
    }

    /** @return array<string, int|list<string>> */
    public function comoArray(): array
    {
        return [
            'filasLeidas' => $this->filasLeidas,
            'pasajerosCreados' => $this->pasajerosCreados,
            'pasajerosActualizados' => $this->pasajerosActualizados,
            'identificacionesCreadas' => $this->identificacionesCreadas,
            'gruposCreados' => $this->gruposCreados,
            'pertenenciasCreadas' => $this->pertenenciasCreadas,
            'pertenenciasQuitadas' => $this->pertenenciasQuitadas,
            'noEstanEnElArchivo' => $this->noEstanEnElArchivo,
            'avisos' => $this->avisos,
            'errores' => $this->errores,
        ];
    }
}
