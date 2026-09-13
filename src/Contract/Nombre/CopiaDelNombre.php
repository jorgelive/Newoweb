<?php

declare(strict_types=1);

namespace App\Contract\Nombre;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Un sitio que guarda **su propia copia** del nombre de alguien y sabe ponerla al día.
 *
 * Implementarla es todo lo que hay que hacer para que una copia deje de envejecer: el emisor no
 * se toca, no hay registro que actualizar, no hay `match` en ninguna parte.
 * {@see PropagadorDeNombre} las encuentra solas.
 *
 * ### Qué NO debe implementar esto
 *
 * **Lo que se congela a propósito.** Un enlace de pago ya emitido guarda el nombre que se mandó a
 * la pasarela; un aviso ya enviado, el que leyó quien lo recibió; una auditoría de webhook, el
 * payload crudo. Eso no son copias que envejecen: son constancias de lo que pasó, y reescribirlas
 * sería falsificar el pasado. Si tu copia es de ésas, **no implementes esta interfaz**.
 *
 * **Lo que ya se recalcula solo.** `msg_conversation.guest_name` se reescribe en cada guardado de
 * la reserva, así que la corrección le llega sin ayuda. Añadirlo aquí sería un segundo camino
 * hacia el mismo dato, que es como acaban contradiciéndose.
 *
 * ### La regla que toda implementación tiene que respetar
 *
 * 🔑 **Sólo se pisa lo que era nuestro.** Si la copia coincide con el nombre ANTERIOR, la
 * escribimos nosotros y se actualiza; si no coincide, la puso una persona y se respeta. Sin esa
 * comprobación habría que elegir entre copias podridas o borrar el trabajo de alguien.
 */
#[AutoconfigureTag('app.copia_del_nombre')]
interface CopiaDelNombre
{
    /**
     * Pone al día lo que este sitio tenga copiado.
     *
     * @return int Cuántas filas se tocaron. Cero es una respuesta normal —lo habitual es que no
     *             haya nada que corregir—, no un fallo.
     */
    public function corregir(CorreccionDeNombre $correccion): int;

    /**
     * Cuántas copias están hoy desincronizadas de su origen, sin arreglar ninguna.
     *
     * 🔥 **Es la mitad que convierte esto en algo comprobable.** Propagar bien no sirve de nada si
     * nadie puede preguntar «¿y cuántas hay mal ahora mismo?» — que es exactamente la pregunta que
     * no se podía contestar y la que hizo falta responder a mano, a base de SQL, para descubrir
     * que había 23 títulos de calendario podridos.
     */
    public function desincronizadas(): int;

    /** Cómo se llama esto en un informe. Cuatro palabras: «título del calendario». */
    public function queCopia(): string;
}
