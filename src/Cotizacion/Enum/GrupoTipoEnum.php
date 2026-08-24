<?php

declare(strict_types=1);

namespace App\Cotizacion\Enum;

/**
 * En qué EJE agrupa un subgrupo del expediente.
 *
 * ## No es un árbol, son ejes cruzados
 *
 * ⚠️ Una persona pertenece a la vez a su grupo, su habitación, sus dos reservas aéreas y los
 * servicios que lleva. En el padrón real de Punta Cana hay **13 combinaciones distintas de
 * servicios entre 133 personas**. Modelarlo anidado se rompe en cuanto quieres filtrar por otro eje.
 *
 * ⚠️ **El «salón» se quitó a propósito**: es control académico interno del colegio —qué aula le
 * toca a cada alumno— y no describe nada del viaje. Un eje que no cambia ninguna decisión
 * operativa sólo enseña a ignorar los demás.
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
    case GRUPO = 'grupo';
    case HABITACION = 'habitacion';
    case RESERVA_AEREA = 'reserva_aerea';

    /**
     * Los dos tramos, cuando el viaje los tiene separados y **en billetes distintos**.
     *
     * ⚠️ Son ejes propios y no un atributo de {@see self::RESERVA_AEREA} porque la plantilla del
     * padrón identifica el eje **por la cabecera de la columna**. Con las dos llamándose «#Reserva
     * aérea», cuál es cuál dependía de la POSICIÓN: al reimportar caían las dos en el mismo eje y
     * **el tramo se perdía sin un solo error**. Nada en el archivo lo decía.
     *
     * Y los tres conviven a propósito: una pareja que vuela a Cusco tiene UNA reserva y no hay
     * tramo del que hablar. Obligarla a elegir «nacional» sería inventarle una distinción.
     */
    case RESERVA_AEREA_NACIONAL = 'reserva_aerea_nacional';
    case RESERVA_AEREA_INTERNACIONAL = 'reserva_aerea_internacional';

    /**
     * Quién participa en un servicio concreto: los que van a Coco Bongo, los que llevan seguro.
     *
     * ⚠️ Es el único eje **binario**: los demás tienen un valor —salón B, habitación HA13— y éste
     * sólo tiene pertenencia. Por eso en la plantilla del padrón entra con el marcador `+` y no
     * con `#`: una columna `#Coco Bongo` con «SÍ» crearía un grupo llamado «SI», que no significa
     * nada. Ver {@see \App\Cotizacion\Service\Padron\PadronFormato}.
     *
     * Sirve para dos cosas a la vez sin duplicar nada: el panel de inclusiones específicas de cada
     * participante, y la lista de quién va que necesita la orden de servicio de ese proveedor.
     */
    case SERVICIO = 'servicio';

    public function label(): string
    {
        return match ($this) {
            self::GRUPO => 'Grupo',
            self::HABITACION => 'Habitación',
            self::RESERVA_AEREA => 'Reserva aérea',
            self::RESERVA_AEREA_NACIONAL => 'Reserva aérea nacional',
            self::RESERVA_AEREA_INTERNACIONAL => 'Reserva aérea internacional',
            self::SERVICIO => 'Servicio',
        };
    }

    /**
     * ¿Lleva un VALOR, o sólo pertenencia?
     *
     * El servicio es el único binario: se va a Coco Bongo o no se va. Los demás tienen un valor
     * —«Habitación HA13»— y por eso viajan con marcador distinto en la plantilla del padrón.
     */
    public function esEjeConValor(): bool
    {
        return $this !== self::SERVICIO;
    }

    /** Qué se escribe en su columna. Va aquí y no en el generador: al añadir un eje, el `match` obliga. */
    public function ejemplos(): string
    {
        return match ($this) {
            self::GRUPO => '1, 2, 3…',
            self::HABITACION => 'HA13, HA44 — el número que da el hotel',
            self::RESERVA_AEREA => 'JA2CWN, YMFLHB — el localizador de la aerolínea',
            self::RESERVA_AEREA_NACIONAL => 'Y9KZ7J — el localizador del tramo nacional',
            self::RESERVA_AEREA_INTERNACIONAL => 'BONT3N — el localizador del tramo internacional',
            self::SERVICIO => 'SÍ o NO (esta columna va con «+», no con «#»)',
        };
    }

    /**
     * ¿Este eje recibe documentos propios?
     *
     * Hoy sólo la reserva aérea: su namelist llega de la aerolínea y lo mira todo el grupo. Una
     * habitación o un servicio no tienen papeles propios.
     */
    public function admiteArchivos(): bool
    {
        return $this->esReservaAerea();
    }

    /** Cualquiera de los tres ejes de vuelo. Se pregunta por esto, no por el caso concreto. */
    public function esReservaAerea(): bool
    {
        return in_array($this, [self::RESERVA_AEREA, self::RESERVA_AEREA_NACIONAL, self::RESERVA_AEREA_INTERNACIONAL], true);
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
