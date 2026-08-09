<?php

declare(strict_types=1);

namespace App\Finanzas\Enum;

/**
 * Qué se está cobrando: el módulo dueño del documento al que se imputa el dinero.
 *
 * Este enum es la mitad de la relación SOFT de `FinEnlacePago` (la otra mitad es
 * `origenId`, un UUID sin foreign key). Se eligió así, y no una FK nullable por módulo,
 * porque Finanzas nace transversal: cada módulo nuevo que quiera cobrar añadiría una
 * columna, una migración y un cambio en la entidad compartida. Aquí sólo añade un case
 * y su resolver — Finanzas no se entera.
 *
 * A cambio se pierde la integridad referencial: puede quedar un enlace apuntando a una
 * reserva borrada. Es aceptable e incluso deseable, porque un enlace **pagado** es un
 * documento contable que debe sobrevivir al borrado de la reserva que lo originó.
 * `FinOrigenCobroRegistry::resolver()` devuelve null en ese caso y la UI lo pinta como
 * "origen no disponible" en vez de reventar.
 *
 * @see \App\Finanzas\Contract\FinOrigenCobroResolverInterface
 */
enum FinOrigenCobro: string
{
    /** Reserva de alojamiento del PMS (`App\Pms\Entity\PmsReserva`). */
    case PMS_RESERVA = 'pms_reserva';

    /**
     * Reserva de tour. El módulo todavía NO existe: el case está declarado para fijar
     * el vocabulario, pero sin resolver registrado el registry rechaza crear enlaces
     * con este origen (falla explícito, no un enlace huérfano).
     */
    case TOUR_RESERVA = 'tour_reserva';

    /**
     * Expediente de cotización. Sin resolver todavía.
     *
     * Se puede usar como **etiqueta de módulo** en un cobro manual aunque no exista el
     * resolver: en ese caso `origenId` va vacío y sólo sirve para saber a qué negocio
     * imputar el dinero al mirar el listado.
     */
    case COTIZACION = 'cotizacion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PMS_RESERVA  => 'Reserva de alojamiento',
            self::TOUR_RESERVA => 'Reserva de tour',
            self::COTIZACION   => 'Cotización',
        };
    }

    /** Nombre corto del módulo, para la columna del listado. */
    public function modulo(): string
    {
        return match ($this) {
            self::PMS_RESERVA, self::TOUR_RESERVA => 'PMS',
            self::COTIZACION                      => 'Cotizaciones',
        };
    }

    /** @return array<int, array{value: string, label: string}> Catálogo para los selects de la SPA. */
    public static function opciones(): array
    {
        return array_map(
            static fn (self $caso): array => ['value' => $caso->value, 'label' => $caso->etiqueta()],
            self::cases(),
        );
    }
}
