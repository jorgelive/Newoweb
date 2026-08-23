<?php

declare(strict_types=1);

namespace App\Cotizacion\Enum;

/**
 * A QUIÉN se le enseña un detalle del componente.
 *
 * ## Por qué banderas y no un tipo
 *
 * El campo nació con un `tipo` —`cliente` u `operativa`— y en producción los **15 componentes
 * que tenían los dos bloques los tenían IDÉNTICOS palabra por palabra**. La distinción no
 * separaba nada: obligaba a escribir dos veces lo mismo y a mantener las dos copias en siete
 * idiomas. Con banderas se escribe una vez y se marca quién lo ve.
 *
 * ## ⚠️ Una audiencia = un documento
 *
 * La lista NO crece con roles: crece con **superficies que reciben papel**. Una bandera que no
 * cambie a dónde llega el texto no se añade, porque enseña a ignorar las demás.
 *
 * | Audiencia   | Dónde aterriza                    |
 * |-------------|-----------------------------------|
 * | `cliente`   | cotización y app del pasajero     |
 * | `interno`   | La Biblia                         |
 * | `prestador` | Orden de Servicio                 |
 *
 * `personal` (guía, conductor) queda fuera a propósito: hoy leen la orden del prestador, así que
 * sería una marca que no cambia nada.
 *
 * ⚠️ La audiencia de casa se llama `interno` y **no `operador`**: en turismo «operador» es muchas
 * veces una agencia de fuera, así que ese nombre habría dicho justo lo contrario de lo que marca.
 *
 * ## Y esto NO es un many-to-many
 *
 * Son tres casos que define el código y no cambian nunca. Una tabla intermedia para eso añade
 * dos joins y una pantalla de mantenimiento a cambio de nada. El día que una audiencia sea un
 * dato que el operador crea —«sólo para ESTE proveedor»— será otra conversación, y otra forma.
 */
enum AudienciaDetalleEnum: string
{
    case CLIENTE = 'cliente';
    case INTERNO = 'interno';
    case PRESTADOR = 'prestador';

    public function label(): string
    {
        return match ($this) {
            self::CLIENTE => 'Cliente',
            self::INTERNO => 'Interno',
            self::PRESTADOR => 'Prestador',
        };
    }

    /** Qué documento lo recibe. Es la frase que justifica que la bandera exista. */
    public function documento(): string
    {
        return match ($this) {
            self::CLIENTE => 'Cotización y app del pasajero',
            self::INTERNO => 'La Biblia',
            self::PRESTADOR => 'Orden de Servicio',
        };
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_map(static fn (self $a): string => $a->value, self::cases());
    }
}
