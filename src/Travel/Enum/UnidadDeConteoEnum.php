<?php

declare(strict_types=1);

namespace App\Travel\Enum;

/**
 * En qué se cuenta un componente que dura: **noches, días o unidades sueltas**.
 *
 * ── El caso que lo hizo falta ────────────────────────────────────────────────
 * Un hotel del 18 al 22 son **4 noches**. Un seguro de viaje del 18 al 22 son **5 días**. Las
 * mismas dos fechas, dos cantidades, y hasta el 07/09/2026 el editor sólo sabía contar una:
 * `calcularPernoctes()` hacía `fin − inicio` para todo, y el nombre lo confesaba.
 *
 * La consecuencia no fue un número mal pintado: fue que **el operador tuvo que torcer el dato**.
 * Para cobrar los 5 días de estancia en Punta Cana escribió el seguro como `18 → 23`, porque era
 * la única forma de que la resta diera 5. La fecha de fin quedó diciendo una cosa que no era, y
 * quien leyera el itinerario con atención vería la incoherencia — cobertura hasta el 23 en un
 * viaje cuyo hotel cierra el 22.
 *
 * ── Por qué es un eje propio y no un tipo de componente ──────────────────────
 * ⚠️ La tentación es resolverlo con el tipo: «alojamiento cuenta noches, extras cuenta días». No
 * vale, y la razón es `EXTRAS`: es un cajón de sastre donde caben el seguro (días), una propina
 * (una unidad) y un upgrade (una unidad). El tipo da el **valor por defecto**
 * ({@see ComponenteTipoEnum::unidadPorDefecto()}) y acierta en 31 de los 33 componentes multi-día
 * que hay en producción; el campo del componente maestro corrige los que no.
 *
 * ⚠️ **Y no lleva el sustantivo dentro.** «5 desayunos» es `DIAS` con el sustantivo «desayuno»,
 * no un caso nuevo. Si el enum cargara el nombre, crecería con el catálogo: desayuno, almuerzo,
 * cena, masaje… Cómo se CUENTA y cómo se LLAMA son dos preguntas, y sólo la primera es cerrada.
 */
enum UnidadDeConteoEnum: string
{
    /** Hoteles: del 18 al 22 se duerme 18, 19, 20 y 21. El día de salida no se cuenta. */
    case NOCHES = 'noches';

    /** Coberturas, desayunos, alquileres por jornada: del 18 al 22 son cinco. */
    case DIAS = 'dias';

    /** No se deriva de las fechas: lo pone el operador. Tickets, propinas, upgrades. */
    case UNIDADES = 'unidades';

    /**
     * ¿El último día cuenta? Es **toda** la diferencia entre noches y días, y por eso vive aquí y
     * no repartida por los sitios que cuentan.
     *
     * ```
     * noches:  fin − inicio
     * días:    fin − inicio + 1
     * ```
     */
    public function sumaElUltimoDia(): bool
    {
        return $this === self::DIAS;
    }

    /**
     * ¿La cantidad sale de las fechas?
     *
     * `UNIDADES` no: un ticket de entrada no dura, se compra. Ahí la cantidad la escribe quien
     * cotiza y las fechas no la tocan.
     */
    public function seDerivaDeLasFechas(): bool
    {
        return $this !== self::UNIDADES;
    }

    /**
     * ¿Cierra el día en el itinerario del huésped?
     *
     * Sólo la cama. Lo que se cuenta por días —un seguro, un desayuno— **cubre** la jornada, así
     * que se anuncia al empezarla: un cliente que lee el día entero y encuentra al pie «incluía el
     * almuerzo» se enteró tarde de algo que ya no puede usar.
     */
    public function cierraElDia(): bool
    {
        return $this === self::NOCHES;
    }

    /** El sustantivo por defecto, para cuando el componente no declara el suyo. */
    public function sustantivo(): string
    {
        return match ($this) {
            self::NOCHES => 'noche',
            self::DIAS => 'día',
            self::UNIDADES => '',
        };
    }
}
