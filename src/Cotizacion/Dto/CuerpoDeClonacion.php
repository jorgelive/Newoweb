<?php

declare(strict_types=1);

namespace App\Cotizacion\Dto;

use App\Dto\Lee;
use DateTimeImmutable;

/**
 * El cuerpo de `POST /client/cotizacion/{id}/clonar`, leído una vez.
 *
 * ⚠️ **Los dos campos son opcionales y un cuerpo vacío significa «clona como siempre»**, que es
 * lo que la UI manda hoy (`apiClient.post(..., {})` en `fileStore.ts`). Sin esa garantía, añadir
 * destino y fecha habría roto el botón de clonar que ya existe.
 *
 * - `file`: a qué expediente va la copia. Acepta el UUID o el IRI (`/platform/.../files/{id}`),
 *   porque el front maneja IRIs y un comando de consola maneja ids. Vacío = el mismo padre.
 * - `fechaInicio`: a qué día se ancla el PRIMER servicio. Vacío = no se mueve nada.
 *
 * Una fecha ilegible es `false`, no `null`: «no la entiendo» (400) y «no la mandaste» (no tocar)
 * no son lo mismo, y confundirlas clonaría un viaje entero en las fechas del original sin avisar.
 */
final readonly class CuerpoDeClonacion
{
    private function __construct(
        /** UUID del expediente destino, ya sin el IRI. Null = el mismo padre que el original. */
        public ?string $fileId,
        /** `false` = no se entiende; `null` = no desplazar. */
        public DateTimeImmutable|false|null $fechaInicio,
    ) {}

    /** @param array<mixed> $datos */
    public static function fromArray(array $datos): self
    {
        $file = Lee::textoLimpio($datos['file'] ?? null);

        // El IRI llega como `/platform/sales/client/files/{uuid}`: nos quedamos con el último
        // tramo. Un UUID pelado pasa igual, que es lo que manda la consola.
        if ($file !== null && str_contains($file, '/')) {
            $file = substr($file, strrpos($file, '/') + 1) ?: null;
        }

        $crudaFecha = $datos['fechaInicio'] ?? null;
        $fecha = null;

        if ($crudaFecha !== null && $crudaFecha !== '') {
            $texto = Lee::textoLimpio($crudaFecha);
            $fecha = false;

            if ($texto !== null) {
                // `Y-m-d` estricto: aceptar formatos sueltos invita a que «03/04» entre como
                // marzo en un sitio y abril en otro, y aquí mueve un viaje entero.
                $intento = DateTimeImmutable::createFromFormat('!Y-m-d', $texto);
                $fecha = ($intento !== false && $intento->format('Y-m-d') === $texto) ? $intento : false;
            }
        }

        return new self($file, $fecha);
    }
}
