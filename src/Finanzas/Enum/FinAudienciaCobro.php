<?php

declare(strict_types=1);

namespace App\Finanzas\Enum;

/**
 * A quién se le puede ofrecer un medio de cobro.
 *
 * No es un permiso: es que **ofrecer el medio equivocado le cuesta dinero al huésped**. Una
 * transferencia a una cuenta peruana desde el extranjero se come en comisiones buena parte de
 * un adelanto de 30 USD, y el huésped lo descubre cuando ya la ha hecho. Al revés pasa lo
 * mismo: mandar a un peruano a Western Union es cobrarle un giro que no necesitaba.
 *
 * Por eso el medio dice a quién sirve y no se deja al criterio del modelo, que sólo ve un
 * número de cuenta y no sabe cuánto cuesta llegar hasta él.
 *
 * Quién es «de Perú» lo decide {@see \App\Agent\Skill\Pms\ConsultarMediosPagoSkill::esDePeru()}
 * con el país de la reserva y, si falta, el prefijo del teléfono.
 */
enum FinAudienciaCobro: string
{
    /** Sirve para cualquiera: efectivo al llegar, tarjeta. */
    case TODOS = 'todos';

    /** Sólo para quien paga desde Perú: Yape, Plin, cuentas nacionales en soles o dólares. */
    case PERU = 'peru';

    /** Sólo para quien paga desde fuera: Western Union, PayPal. */
    case INTERNACIONAL = 'internacional';

    public function label(): string
    {
        return match ($this) {
            self::TODOS         => 'Todos',
            self::PERU          => 'Sólo desde Perú',
            self::INTERNACIONAL => 'Sólo desde el extranjero',
        };
    }

    /**
     * ¿Se le puede ofrecer a este huésped?
     *
     * `$desdePeru` a `null` significa «no lo sabemos». En ese caso pasa TODO: es preferible
     * enumerar los medios y dejar que elija a ocultarle el único que le servía porque su
     * reserva vino sin país y con un teléfono raro.
     */
    public function aplicaA(?bool $desdePeru): bool
    {
        if ($this === self::TODOS || $desdePeru === null) {
            return true;
        }

        return $desdePeru
            ? $this === self::PERU
            : $this === self::INTERNACIONAL;
    }

    /**
     * Para los `choices` de EasyAdmin.
     *
     * ⚠️ El **caso del enum, no su `->value`**: ver el aviso de
     * {@see FinMedioCobroTipo::opciones()}. Con strings, el formulario de edición muere con
     * «could not be converted to string» y el de alta no, porque ahí no hay valor que casar.
     *
     * @return array<string, self>
     */
    public static function opciones(): array
    {
        $opciones = [];

        foreach (self::cases() as $caso) {
            $opciones[$caso->label()] = $caso;
        }

        return $opciones;
    }
}
