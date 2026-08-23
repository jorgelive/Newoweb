<?php

declare(strict_types=1);

namespace App\Operacion\Enum;

/**
 * Si un extremo —el recojo o la entrega— se le imprime al proveedor en esta línea.
 *
 * Es **presentación, no pacto**: ocultar un renglón no cambia lo que hay que hacer, dice menos.
 * Cambiar el TEXTO de un punto sí es pacto y eso obliga a reemitir. Ésa es la frontera, y es la
 * que permite que esto se pueda tocar con la orden ya emitida.
 *
 * ```
 * AUTO      manda la regla de cadenas: una cadena del mismo prestador enseña sólo su
 *           principio y su final, porque lo de en medio es logística suya
 * SIEMPRE   se imprime aunque esté en medio — un tramo subcontratado, o un punto que el
 *           proveedor sí necesita por escrito
 * OCULTO    NO se imprime aunque sea el extremo de la cadena
 * ```
 *
 * ⚠️ **`OCULTO` puede dejar una orden sin decir dónde se recoge, y eso se avisa pero NO se
 * bloquea.** La versión anterior era un booleano que sólo podía añadir líneas, con el argumento
 * de que quitar el principio de una cadena deja al proveedor sin saber dónde ir. El argumento
 * sigue siendo bueno; lo que cambió es el control: esto se decide **por lado**, sobre el listado
 * de la orden y con rastro, no con un interruptor de formato sin contexto. Y bloquearlo empujaba
 * al atajo destructivo —vaciar el texto del punto— que además pierde el dato.
 *
 * Quien lo deje sin recojo verá el aviso en ámbar. La decisión es de la persona; el sistema hace
 * visible la consecuencia.
 */
enum VisibilidadPuntoEnum: string
{
    case AUTO = 'auto';
    case SIEMPRE = 'siempre';
    case OCULTO = 'oculto';

    public function etiqueta(): string
    {
        return match ($this) {
            self::AUTO => 'Automático',
            self::SIEMPRE => 'Mostrar siempre',
            self::OCULTO => 'Ocultar al proveedor',
        };
    }

    /** ¿Deja que la regla de cadenas decida, o impone una respuesta? */
    public function esAutomatica(): bool
    {
        return $this === self::AUTO;
    }
}
