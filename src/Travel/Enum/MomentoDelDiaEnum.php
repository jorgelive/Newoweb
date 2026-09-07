<?php

declare(strict_types=1);

namespace App\Travel\Enum;

/**
 * Dónde se lee un componente dentro de la jornada **cuando no tiene reloj**.
 *
 * ── Por qué hace falta si ya existe `ordenNarrativo()` ───────────────────────
 * `ComponenteTipoEnum::ordenNarrativo()` contesta esta misma pregunta con un número por TIPO, y
 * se queda corto en el sitio donde más se nota: **desayuno, almuerzo y cena son el mismo tipo**
 * (`ALIMENTACION_HORARIO_VAR`) y ocurren en tres momentos distintos. Con un solo número por tipo,
 * los tres van al mismo sitio.
 *
 * Este enum vive en la misma escala —10, 20, 30…— para que los dos se comparen sin traducir nada:
 * el tipo pone el defecto, el componente maestro lo afina.
 *
 * ── El caso que lo hizo falta ────────────────────────────────────────────────
 * Un cliente que lee su día de arriba abajo y encuentra **al final** «incluía el almuerzo» se
 * enteró tarde de algo que ya no puede usar. Pasa porque hoy lo que no tiene hora se ordena
 * después de todo lo que sí la tiene, así que un almuerzo variable aterriza tras la excursión de
 * la tarde aunque ocurra a mediodía.
 *
 * ⚠️ **La alternativa era inventarle una hora**, y es peor: mete en la base un minuto que nadie
 * fijó, que luego se pinta, se manda al proveedor y se convierte en un compromiso. El comando del
 * seguro de viaje ya se negó a hacerlo, con razón.
 *
 * ⚠️ **Es sólo para LEER.** No cambia horas ni fechas y no toca la operación: la orden del
 * proveedor sigue siendo cronológica, que allí es un horario de trabajo y no un relato.
 */
enum MomentoDelDiaEnum: string
{
    /** Abre la jornada. Lo que cubre el día entero —un seguro— se anuncia aquí. */
    case ABRE = 'abre';

    /** Desayunos y lo de primera hora. */
    case MANANA = 'manana';

    /** El cuerpo del día: excursiones, visitas. */
    case MEDIA_MANANA = 'media_manana';

    /** Almuerzos. */
    case MEDIODIA = 'mediodia';

    /** Lo de después de comer. */
    case TARDE = 'tarde';

    /** Cenas y espectáculos. */
    case NOCHE = 'noche';

    /** Dormir cierra el día. Es la razón de existir de `ordenNarrativo()`. */
    case CIERRA = 'cierra';

    /**
     * La posición en la misma escala que {@see ComponenteTipoEnum::ordenNarrativo()}.
     *
     * Números con hueco para poder intercalar sin renumerar lo que ya existe.
     */
    public function orden(): int
    {
        return match ($this) {
            self::ABRE => 5,
            self::MANANA => 15,
            self::MEDIA_MANANA => 30,
            self::MEDIODIA => 50,
            self::TARDE => 55,
            self::NOCHE => 70,
            self::CIERRA => 90,
        };
    }

    /** Lo que se enseña en el desplegable del catálogo. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::ABRE => 'Abre el día (cubre la jornada)',
            self::MANANA => 'Mañana',
            self::MEDIA_MANANA => 'Media mañana',
            self::MEDIODIA => 'Mediodía',
            self::TARDE => 'Tarde',
            self::NOCHE => 'Noche',
            self::CIERRA => 'Cierra el día',
        };
    }
}
