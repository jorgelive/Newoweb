<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

/**
 * Qué habría que hacer con el archivo, además de dejar constancia de su estado.
 *
 * ⚠️ **Ninguna se ejecuta sola.** Es una propuesta que alguien aplica, igual que el plan de la
 * carga por ZIP: con cientos de documentos, escribir a ciegas es pedir un desastre callado — y
 * aquí lo que se escribiría son personas del manifiesto.
 */
enum Accion: string
{
    /** Ya está donde tiene que estar. */
    case NINGUNA = 'ninguna';

    /**
     * Hay una persona en el expediente a la que pertenece. La confianza la dice el motivo: casar
     * **por número de documento** es seguro; **por nombre** es una sugerencia y nada más.
     */
    case ASOCIAR = 'asociar';

    /**
     * No hay nadie a quien pueda pertenecer: habría que crear la ficha del manifiesto con lo
     * leído.
     *
     * ⚠️ Es la única que **añade una persona**, así que es la que más caro sale equivocada: dos
     * fichas de la misma persona rompen los conteos del manifiesto y nadie las echa de menos.
     */
    case CREAR = 'crear';
}
