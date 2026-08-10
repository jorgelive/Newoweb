<?php

declare(strict_types=1);

namespace App\Pms\Enum;

/**
 * Cuánto se le pide por adelantado al huésped al reservar.
 *
 * Es política COMERCIAL, no una constante: cambia por temporada —en baja se afloja para no
 * perder reservas— y puede diferir entre establecimientos virtuales. Por eso vive como dato
 * configurable en {@see \App\Pms\Entity\PmsEstablecimientoVirtual} y no incrustada en el
 * código, que es donde estaba antes de existir este enum.
 *
 * ### La distinción «del total» vs «de noches»
 *
 * No son lo mismo y la diferencia es dinero real. Una reserva de 3 noches a 40 con 15 de
 * limpieza suma 135:
 *
 * | Política | Base | Importe |
 * |---|---|---|
 * | `PRIMERA_NOCHE_TOTAL` | 135 / 3 noches | 45.00 |
 * | `PRIMERA_NOCHE_ALOJAMIENTO` | 120 / 3 noches | 40.00 |
 * | `MITAD_TOTAL` | 135 | 67.50 |
 * | `MITAD_ALOJAMIENTO` | 120 | 60.00 |
 *
 * Las variantes «de noches» miran solo los cargos de tipo {@see PmsTipoCargo::ALOJAMIENTO};
 * las «del total», todo lo facturado. Cobrar limpieza por adelantado y luego no prestarla
 * —cancelación— es justo la discusión que las segundas evitan.
 *
 * ### Para qué sirve de verdad el prepago
 *
 * **No es flujo de caja: es un compromiso.** El importe es deliberadamente pequeño, y su
 * función es que al huésped le cueste algo cancelar. Las devoluciones son excepcionales —del
 * orden de una al año—, así que optimizar la política pensando en el reembolso es optimizar
 * para el caso que casi nunca ocurre.
 *
 * De ahí se siguen dos cosas al tocar esto:
 *
 * 1. **La diferencia entre «del total» y «solo alojamiento» importa poco en la práctica.**
 *    Son unos pocos dólares. Existe porque a veces conviene explicarla al huésped, no porque
 *    mueva la caja. No merece maquinaria complicada.
 * 2. **Lo que sí importa es que el importe se VEA y se entienda.** Un prepago que el huésped
 *    no sabe explicar no le disuade de cancelar; uno que reconoce —«la primera noche»—, sí.
 *    Por eso el estado de cuenta lo enseña con su frase, y no solo como una cifra suelta.
 *
 * ⚠️ El importe NO se calcula aquí: depende de los cargos y de las noches de la reserva, que
 * este enum no conoce. Aquí solo se declara QUÉ política es; el cálculo va en el servicio que
 * lea la información financiera.
 */
enum PmsPoliticaPrepago: string
{
    /** Ninguno: se cobra todo a la llegada, o lo cobró el canal. */
    case SIN_PREPAGO = 'sin_prepago';

    case PRIMERA_NOCHE_TOTAL       = 'primera_noche_total';
    case PRIMERA_NOCHE_ALOJAMIENTO = 'primera_noche_alojamiento';
    case MITAD_TOTAL               = 'mitad_total';
    case MITAD_ALOJAMIENTO         = 'mitad_alojamiento';

    /** Etiqueta para el panel. La que ve el huésped se traduce aparte. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::SIN_PREPAGO               => 'Sin prepago',
            self::PRIMERA_NOCHE_TOTAL       => 'Primera noche (sobre el total)',
            self::PRIMERA_NOCHE_ALOJAMIENTO => 'Primera noche (solo alojamiento)',
            self::MITAD_TOTAL               => '50 % del total',
            self::MITAD_ALOJAMIENTO         => '50 % del alojamiento',
        };
    }

    /**
     * Clave del diccionario del huésped con la frase que explica la política.
     *
     * Se devuelve la CLAVE y no el texto porque el huésped puede leer en siete idiomas; el
     * texto vive en `pax_ui_i18n`, como el resto de lo que se le enseña.
     */
    public function claveI18n(): ?string
    {
        return match ($this) {
            self::SIN_PREPAGO => null,
            default           => 'res_prepago_' . $this->value,
        };
    }

    /** ¿Se calcula sobre una noche, o sobre una fracción del importe? */
    public function esPorNoche(): bool
    {
        return $this === self::PRIMERA_NOCHE_TOTAL || $this === self::PRIMERA_NOCHE_ALOJAMIENTO;
    }

    /**
     * ¿La base son SOLO los cargos de alojamiento, o todo lo facturado?
     *
     * Ver la tabla del docblock de la clase: es lo que separa 45.00 de 40.00.
     */
    public function soloAlojamiento(): bool
    {
        return $this === self::PRIMERA_NOCHE_ALOJAMIENTO || $this === self::MITAD_ALOJAMIENTO;
    }

    /** Fracción del importe base, para las políticas que no van por noche. */
    public function fraccion(): float
    {
        return match ($this) {
            self::MITAD_TOTAL, self::MITAD_ALOJAMIENTO => 0.5,
            default                                    => 1.0,
        };
    }

    /**
     * @return array<string, self> etiqueta => CASO, para los desplegables del panel.
     *
     * ⚠️ Devuelve las instancias del enum, no sus `value`. La propiedad de la entidad está
     * tipada como este enum, así que con cadenas Symfony intenta convertir el dato (un
     * objeto) a string para compararlo con las opciones y revienta con
     * «Object of class PmsPoliticaPrepago could not be converted to string». Es el mismo
     * patrón que usa PmsCargoFinancieroCrudController con PmsTipoCargo.
     */
    public static function opciones(): array
    {
        $opciones = [];

        foreach (self::cases() as $caso) {
            $opciones[$caso->etiqueta()] = $caso;
        }

        return $opciones;
    }
}
