<?php

declare(strict_types=1);

namespace App\Cotizacion\Enum;

enum FileEstadoEnum: string
{
    case ABIERTO = 'abierto';
    case CERRADO = 'cerrado';
    case ARCHIVADO = 'archivado';

    /**
     * ⚠️ `ARCHIVADO` es LO BUENO y `CERRADO` LO MALO. Estaba al revés hasta el 30/08/2026.
     *
     * El vocabulario se alinea con el del chat (`MessageConversation`), donde `archived` es el
     * ex-cliente cuya estancia terminó y `closed` lo pone `isCancelled()`. Antes cada módulo
     * usaba las mismas dos palabras para resultados **opuestos**: «cerrado» era la venta hecha
     * aquí y la reserva cancelada allí, así que la palabra no significaba nada sin saber en qué
     * pantalla estabas.
     *
     * Se invirtió el SIGNIFICADO, no los valores, y se pudo porque no había ni una fila usando
     * ninguno de los dos: los seis expedientes de producción estaban todos en `abierto`. Con
     * datos dentro habría hecho falta migrarlos, y el fallo habría sido mudo.
     *
     * El espejo en TypeScript es `ESTADO_FILE_LABELS` (`util/src/types/cotizacionEditorModel.ts`).
     * Si se toca uno, se toca el otro.
     */
    public function getLabel(): string
    {
        return match($this) {
            self::ABIERTO => 'Abierto',
            self::ARCHIVADO => 'Archivado (ganado)',
            self::CERRADO => 'Cerrado (no venta)',
        };
    }
}