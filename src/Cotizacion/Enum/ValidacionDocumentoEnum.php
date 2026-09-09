<?php

declare(strict_types=1);

namespace App\Cotizacion\Enum;

/**
 * En qué punto del control está un documento escaneado.
 *
 * 🔑 **Tres estados y no dos, porque «no validado» y «observado» piden cosas distintas.** Con un
 * booleano, un documento sin revisar y otro cuyo número no cuadra con el manifiesto caen en el
 * mismo cajón, y ese cajón se mira una vez, se ve enorme, y se deja de mirar. Separados, la cola
 * de trabajo es la de `OBSERVADO` —corta y accionable— y `NO_VALIDADO` es sólo «aún no le ha
 * tocado».
 *
 * ⚠️ **`VALIDADO` no significa «el documento es auténtico».** Nada de esto detecta una
 * falsificación. Significa exactamente esto y nada más: *lo que se lee en la imagen concuerda con
 * lo que hay guardado, y la lectura viene respaldada por algo más que la palabra de un modelo.*
 * Escribirlo aquí porque en pantalla se va a leer como «documento correcto», y quien lo interprete
 * así tomará decisiones que este estado no sostiene.
 */
enum ValidacionDocumentoEnum: string
{
    /** Todavía no se ha mirado, o no se pudo leer. Es el estado de partida de todo. */
    case NO_VALIDADO = 'no_validado';

    /**
     * Se leyó y **algo no encaja**: el número no coincide con el del manifiesto, la MRZ no cuadra,
     * está vencido, o no hay forma de comprobarlo. Va a una cola humana.
     */
    case OBSERVADO = 'observado';

    /** Se leyó, hay respaldo para creerlo, y concuerda con lo guardado. */
    case VALIDADO = 'validado';

    public function getLabel(): string
    {
        return match ($this) {
            self::NO_VALIDADO => 'Sin validar',
            self::OBSERVADO => 'Observado',
            self::VALIDADO => 'Validado',
        };
    }

    /** Para pintarlo: gris lo que no se ha mirado, ámbar lo que pide una persona, verde lo demás. */
    public function getColor(): string
    {
        return match ($this) {
            self::NO_VALIDADO => 'slate',
            self::OBSERVADO => 'amber',
            self::VALIDADO => 'emerald',
        };
    }

    /** ¿Pide que alguien haga algo? Es lo que alimenta el contador de la bóveda. */
    public function pideAtencion(): bool
    {
        return $this === self::OBSERVADO;
    }
}
