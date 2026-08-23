<?php

declare(strict_types=1);

namespace App\Cotizacion\Enum;

/**
 * En qué EJE agrupa un subgrupo del expediente.
 *
 * ## No es un árbol, son ejes cruzados
 *
 * ⚠️ En el padrón real de Punta Cana 2026 —133 personas— hay **5 salones y 10 grupos, con 43
 * combinaciones distintas**, y **9 de los 10 grupos aparecen en más de un salón**; el grupo 1 está
 * en los cinco. Una persona pertenece a la vez a su salón, su grupo, su habitación y sus dos
 * reservas aéreas. Modelarlo anidado se rompe en cuanto quieres filtrar por el otro eje.
 *
 * ## Por qué el EJE es enum y el VALOR nunca
 *
 * Los ejes son pocos y el código sí distingue alguno —una reserva aérea lleva PNR y documentos, un
 * salón no—. El enum obliga a decidir cómo se pinta y se filtra cada eje nuevo, que es justo lo que
 * no conviene que se olvide.
 *
 * El **valor** es otra cosa: de estos cuatro ejes, dos lo reciben de fuera —66 habitaciones las
 * numera el hotel, 20 códigos de reserva los dan las aerolíneas—. Ahí un enum es imposible, y lo
 * que evita los duplicados por tecleo no es congelar una lista sino la unicidad
 * `(file, tipo, clave)`.
 */
enum GrupoTipoEnum: string
{
    case SALON = 'salon';
    case GRUPO = 'grupo';
    case HABITACION = 'habitacion';
    case RESERVA_AEREA = 'reserva_aerea';

    public function label(): string
    {
        return match ($this) {
            self::SALON => 'Salón',
            self::GRUPO => 'Grupo',
            self::HABITACION => 'Habitación',
            self::RESERVA_AEREA => 'Reserva aérea',
        };
    }

    /**
     * ¿Este eje recibe documentos propios?
     *
     * Hoy sólo la reserva aérea: su namelist llega de la aerolínea y lo mira todo el grupo. Un
     * salón o una habitación no tienen papeles.
     */
    public function admiteArchivos(): bool
    {
        return $this === self::RESERVA_AEREA;
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
