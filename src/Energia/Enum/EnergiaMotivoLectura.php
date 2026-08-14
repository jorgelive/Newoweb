<?php

declare(strict_types=1);

namespace App\Energia\Enum;

/**
 * Por qué existe una fila en la bitácora de consumo.
 *
 * La bitácora no guarda sólo muestreos: guarda también los momentos en que el contador del
 * huésped se puso a cero. Y eso NO se hace borrando ni editando la fila anterior —se hace
 * escribiendo una fila nueva que dice «aquí volvimos a empezar, y por esto».
 *
 * Es la diferencia entre poder enseñarle al huésped su historial y tener que pedirle que se
 * fíe: si el reinicio se hiciera con un UPDATE, el salto en la cuenta no tendría explicación
 * dentro del propio historial, que es justo lo que hay que evitar cuando se cobra por consumo.
 */
enum EnergiaMotivoLectura: string
{
    /** Muestreo horario del cron. Es el caso normal y el 99 % de las filas. */
    case Poll = 'poll';

    /** Primera lectura de la estancia: fija la referencia y deja el consumo del cliente en 0. */
    case CheckIn = 'check_in';

    /** Alguien pidió empezar de cero: el aparato quedó encendido de una limpieza, por ejemplo. */
    case Reinicio = 'reinicio';

    /** Última lectura de la estancia. Cierra la cuenta y es la que se factura. */
    case CheckOut = 'check_out';

    /** Corrección manual de un operador, con su nota. Deja rastro en vez de reescribir. */
    case Ajuste = 'ajuste';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Poll => 'Lectura automática',
            self::CheckIn => 'Inicio de estancia',
            self::Reinicio => 'Reinicio a solicitud',
            self::CheckOut => 'Cierre de estancia',
            self::Ajuste => 'Ajuste manual',
        };
    }

    /**
     * ¿Esta lectura pone a cero el consumo del cliente?
     *
     * El contador total NUNCA se toca: es la lectura física del aparato y tiene que seguir
     * subiendo pase lo que pase. Lo que se reinicia es sólo la parte que se le imputa a quien
     * está alojado.
     */
    public function reiniciaConsumoCliente(): bool
    {
        return $this === self::CheckIn || $this === self::Reinicio;
    }

    /** Las que decide una persona y por tanto piden explicación escrita. */
    public function exigeNota(): bool
    {
        return $this === self::Reinicio || $this === self::Ajuste;
    }
}
