<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Padron;

use App\Cotizacion\Enum\GrupoTipoEnum;
use App\Enum\DocumentoTipoEnum;

/**
 * El formato del padrón: **una sola definición** para generar la plantilla y para leerla.
 *
 * ## Por qué vive aquí y no en cada lado
 *
 * Si el generador y el lector describieran las columnas por separado, el día que alguien añada una
 * la plantilla saldría con ella y el importador la ignoraría — sin error, sin aviso, con el dato
 * perdido. Aquí sólo hay un sitio donde equivocarse.
 *
 * ## Las tres familias de columna
 *
 * ```
 * FIJAS        Nombres · Apellidos · Nacionalidad · Sexo · F. Nacimiento · Observaciones
 * DOCUMENTOS   una por DocumentoTipoEnum, más su «Venc. »
 *                  DNI · Venc. DNI · Pasaporte · Venc. Pasaporte · …
 * EJES         cualquiera que empiece por «#» — llevan un VALOR
 *                  #Grupo · #Habitación · #Reserva aérea
 * SERVICIOS    cualquiera que empiece por «+» — llevan SÍ/NO
 *                  +Seguro · +Tour Saona · +Coco Bongo · +Hotel · +Comidas Lima
 * ```
 *
 * ⚠️ **Dos marcadores y no uno**, porque son dos cosas distintas: un eje tiene valor —«Habitación
 * HA13»— y un servicio sólo tiene pertenencia. Una columna `#Coco Bongo` con «SÍ» crearía un grupo
 * llamado «SI», que no significa nada.
 *
 * ⚠️ **El «salón» ya no está**: es control académico del colegio, no describe nada del viaje, y un
 * eje que no cambia ninguna decisión operativa sólo enseña a ignorar los demás.
 *
 * ## Qué la hace reutilizable
 *
 * **Todo salvo el nombre es opcional.** El mismo archivo sirve para un expediente de dos personas
 * con un pasaporte —se rellenan cuatro columnas y se borran las demás— y para un padrón de colegio
 * de 133 con dos documentos y cinco ejes.
 *
 * Las columnas se buscan **por cabecera, nunca por posición**: se pueden reordenar, y las que no
 * se usen se borran. Una columna que sobra no rompe nada; una que falta tampoco, salvo que sea el
 * nombre.
 *
 * ⚠️ **Los ejes se marcan con `#` a propósito.** Sin la marca no habría forma de distinguir un eje
 * de una columna de notas que el operador añadió por su cuenta, y el importador tendría que
 * adivinar. Con ella, el archivo se describe a sí mismo: borra `#Salón` y ese eje deja de existir;
 * añádelo y vuelve.
 *
 * ⚠️ Y hoy el eje tiene que corresponder a un `GrupoTipoEnum`. Un `#Bus` se rechaza con un mensaje
 * que lo dice, en vez de crear un eje fantasma: el enum es lo que garantiza que el código sepa
 * pintar y filtrar cada eje. Añadir uno de verdad es un `case`, no una columna.
 */
final class PadronFormato
{
    /**
     * La hoja que dice QUÉ es cada código.
     *
     * ⚠️ **Va en hoja aparte y no en una columna del pasajero, y el motivo no es estética.** El
     * nombre de un grupo —«Ida SKY H2-6478 · Retorno H2-3888»— pertenece al GRUPO, no a la
     * persona. Puesto en la hoja de pasajeros habría que repetirlo en las 133 filas, y el
     * importador lo escribiría 133 veces sobre el mismo grupo: **gana la última fila del bucle**,
     * que es la que nadie mira. Una errata en la fila 90 renombraría la reserva entera sin un solo
     * aviso.
     *
     * Aquí la identidad es el par `(eje, clave)` —el mismo `UNIQUE (file, tipo, clave)` que tiene
     * la tabla—, así que el mismo eje aparece tantas veces como códigos tenga:
     *
     * ```
     * #Reserva aérea nacional        Y9KZ7J   Ida SKY H2-6478 · Retorno H2-3888
     * #Reserva aérea nacional        XSRD4    Ida JetSmart JA-7854 · Retorno JA-3888
     * #Reserva aérea internacional   BONT3N   Ida ARAJET XS-7744 · Retorno XS-7884
     * #Habitación                    HA50     DOBLE
     * ```
     *
     * Se lee **antes** del bucle de pasajeros: cuando la fila de alguien crea el grupo, el nombre
     * ya está puesto.
     */
    public const HOJA_GRUPOS = 'Grupos';

    public const COL_GRUPO_EJE = 'Eje';
    public const COL_GRUPO_CLAVE = 'Clave';
    public const COL_GRUPO_NOMBRE = 'Nombre';

    /**
     * El itinerario largo, de varias líneas. Opcional.
     *
     * ⚠️ Columna propia y no pegada al nombre: el nombre se lee para ELEGIR entre veinte códigos y
     * tiene que caber en una píldora; esto se lee para COMPROBAR un horario. Juntos, el corto deja
     * de servir para lo primero.
     */
    public const COL_GRUPO_DETALLE = 'Detalle';

    public const MARCA_EJE = '#';
    public const MARCA_SERVICIO = '+';
    public const PREFIJO_VENCIMIENTO = 'Venc. ';

    /**
     * El código de ESA persona en ESE subgrupo: su localizador aéreo, su asiento.
     *
     * ```
     * #Reserva aérea internacional   Cód. #Reserva aérea internacional
     * BONT3N                         XKP4QT
     * ```
     *
     * ⚠️ **Columna hermana y no pegada a la clave.** Se probó la idea de partir `BONT3N XKP4QT`
     * por el espacio, que es lo que ya hace la exportación con «clave + rótulo», y aquí no vale: un
     * localizador y un código individual son los dos seis caracteres sin espacios, así que no hay
     * forma de saber cuál es cuál. Con la clave y el rótulo se puede porque el rótulo es una
     * palabra («Jetsmart»); con dos códigos, no.
     *
     * Es el mismo patrón que `Venc. `, que ya se entiende: una columna que cualifica a otra.
     *
     * ⚠️ Sólo tiene sentido en ejes **con valor**. El eje `servicio` es binario —se va a Coco Bongo
     * o no— y una columna de código ahí sería un campo que nadie sabría rellenar.
     */
    public const PREFIJO_CODIGO = 'Cód. ';

    /**
     * El identificador del pasajero. **Sólo lo pone la exportación.**
     *
     * ⚠️ Es lo que convierte la idempotencia en exacta. Sin él hay que casar por documento —y si
     * alguien corrige un pasaporte, esa persona se duplica— o por nombre, que es peor todavía. Con
     * él, una fila exportada vuelve a su sitio aunque le hayas cambiado el nombre, el documento y
     * la nacionalidad a la vez.
     *
     * La plantilla en blanco **no lo trae**: una columna vacía llamada «Id» sólo invita a
     * rellenarla con cualquier cosa.
     */
    public const COL_ID = 'Id';

    public const COL_NOMBRES = 'Nombres';
    public const COL_APELLIDOS = 'Apellidos';
    public const COL_NACIONALIDAD = 'Nacionalidad';
    public const COL_SEXO = 'Sexo';
    public const COL_NACIMIENTO = 'F. Nacimiento';
    public const COL_TIPO = 'Rol';
    public const COL_TELEFONO = 'Teléfono';
    public const COL_OBSERVACIONES = 'Observaciones';

    /**
     * Los servicios que trae la plantilla de fábrica.
     *
     * ⚠️ Son un EJEMPLO, no una lista cerrada: cada viaje tiene los suyos. Salen del padrón real
     * de Punta Cana porque una plantilla con columnas plausibles se entiende sin leer nada, y una
     * con «Servicio 1, Servicio 2» hay que explicarla.
     *
     * @var list<string>
     */
    public const SERVICIOS_DE_EJEMPLO = [
        'Vuelo Nacional', 'Vuelo Internacional', 'Seguro',
        'Traslado Ida', 'Traslado Retorno', 'Tour Saona', 'Coco Bongo',
        'Hotel', 'Traslado Lima', 'Comidas Lima',
    ];

    /**
     * Las columnas de persona, en el orden en que se escriben.
     *
     * @return list<array{columna: string, obligatoria: bool, soloGrupo: bool, soloExport: bool, ayuda: string}>
     */
    public static function columnasFijas(): array
    {
        return [
            ['columna' => self::COL_ID, 'obligatoria' => false, 'soloGrupo' => false, 'soloExport' => true,
                'ayuda' => 'Lo pone la descarga «con lo cargado». NO LO TOQUES: es lo que hace que al volver '
                    .'a subir el archivo cada fila vuelva a su persona, aunque le hayas cambiado el nombre.'],
            ['columna' => self::COL_NOMBRES, 'obligatoria' => true, 'soloGrupo' => false, 'soloExport' => false,
                'ayuda' => 'Nombres de pila. Es lo único imprescindible.'],
            ['columna' => self::COL_APELLIDOS, 'soloExport' => false, 'obligatoria' => false, 'soloGrupo' => false,
                'ayuda' => 'Apellidos, en su propia columna: partir «Nombres y Apellidos» a ojo se equivoca con dos nombres y dos apellidos.'],
            ['columna' => self::COL_NACIONALIDAD, 'soloExport' => false, 'obligatoria' => false, 'soloGrupo' => false,
                'ayuda' => 'Código ISO de dos letras. Los 198 están en la hoja «Tablas». Vacío se toma el del expediente.'],
            ['columna' => self::COL_SEXO, 'soloExport' => false, 'obligatoria' => false, 'soloGrupo' => false,
                'ayuda' => 'M o F.'],
            ['columna' => self::COL_NACIMIENTO, 'soloExport' => false, 'obligatoria' => false, 'soloGrupo' => false,
                'ayuda' => 'DD/MM/AAAA. Hace falta para saber quién viaja como menor.'],
            ['columna' => self::COL_TIPO, 'soloExport' => false, 'obligatoria' => false, 'soloGrupo' => true,
                'ayuda' => 'Uno EXACTO de la hoja «Tablas» (hay desplegable). De aquí cuelga qué ve cada '
                    .'uno al consultar su viaje y si aparece ante los demás, así que un valor a medias no vale.'],
            ['columna' => self::COL_TELEFONO, 'soloExport' => false, 'obligatoria' => false, 'soloGrupo' => false,
                'ayuda' => 'El suyo, no el del expediente: con 133 personas hay 133 familias a las que llamar.'],
            ['columna' => self::COL_OBSERVACIONES, 'soloExport' => false, 'obligatoria' => false, 'soloGrupo' => false,
                'ayuda' => 'Texto libre: «FALTA PASAPORTE», «reemplaza a…».'],
        ];
    }

    /**
     * Las columnas de documento: el número y su vencimiento, por cada tipo.
     *
     * Salen del enum, así que dar de alta un tipo nuevo lo añade a la plantilla **y** al lector a
     * la vez.
     *
     * @return list<array{columna: string, vencimiento: string, tipo: DocumentoTipoEnum}>
     */
    public static function columnasDeDocumento(): array
    {
        return array_map(
            static fn (DocumentoTipoEnum $t): array => [
                'columna' => self::etiquetaDeDocumento($t),
                'vencimiento' => self::PREFIJO_VENCIMIENTO.self::etiquetaDeDocumento($t),
                'tipo' => $t,
            ],
            DocumentoTipoEnum::cases(),
        );
    }

    /** «PASAPORTE» en el enum se escribe «Pasaporte» en la hoja: lo lee una persona. */
    public static function etiquetaDeDocumento(DocumentoTipoEnum $tipo): string
    {
        return match ($tipo) {
            DocumentoTipoEnum::DNI => 'DNI',
            DocumentoTipoEnum::CE => 'Carné de Extranjería',
            DocumentoTipoEnum::RUC => 'RUC',
            DocumentoTipoEnum::PASAPORTE => 'Pasaporte',
            DocumentoTipoEnum::CI => 'Cédula',
        };
    }

    /**
     * Los tramos que trae la plantilla de fábrica, como EJEMPLO.
     *
     * ⚠️ No es una lista cerrada ni se valida: `#Vuelo Cusco-Puno` funciona igual. Están éstos
     * porque son los dos de un viaje internacional corriente y se entienden sin leer nada.
     *
     * @var list<string>
     */
    public const TRAMOS_DE_EJEMPLO = ['Nacional', 'Internacional'];

    /**
     * Las columnas de eje que trae la plantilla, ya marcadas.
     *
     * @return list<array{columna: string, tipo: GrupoTipoEnum, subeje: ?string}>
     */
    public static function columnasDeEje(): array
    {
        $columnas = [];

        // Sin el SERVICIO: es binario y va con «+», no con «#».
        foreach (array_filter(GrupoTipoEnum::cases(), static fn (GrupoTipoEnum $t): bool => $t->esEjeConValor()) as $tipo) {
            if (!$tipo->admiteSubeje()) {
                $columnas[] = ['columna' => self::cabeceraDeEje($tipo, null), 'tipo' => $tipo, 'subeje' => null];
                continue;
            }

            foreach (self::TRAMOS_DE_EJEMPLO as $tramo) {
                $columnas[] = ['columna' => self::cabeceraDeEje($tipo, $tramo), 'tipo' => $tipo, 'subeje' => $tramo];
            }
        }

        return $columnas;
    }

    /**
     * Nombres alternativos que se aceptan para las columnas fijas.
     *
     * Un padrón real viene de una plantilla del colegio, no de la nuestra, y discutir por «Gén.»
     * frente a «Sexo» es fricción sin nada detrás: el dato es el mismo y no hay ambigüedad.
     *
     * ⚠️ **Sólo se aceptan alias donde no hay duda.** Los marcadores `#` y `+` NO tienen alias: sin
     * ellos no se puede distinguir un eje de una columna de notas, y adivinarlo es justo lo que
     * este formato existe para evitar.
     *
     * @var array<string, string>
     */
    public const ALIAS = [
        'id' => self::COL_ID,
        'id interno' => self::COL_ID,
        'nombre' => self::COL_NOMBRES,
        'nombres y apellidos' => self::COL_NOMBRES,
        'apellido' => self::COL_APELLIDOS,
        'apellidos y nombres' => self::COL_APELLIDOS,
        'gén.' => self::COL_SEXO,
        'gen.' => self::COL_SEXO,
        'genero' => self::COL_SEXO,
        'género' => self::COL_SEXO,
        'nacionalidad' => self::COL_NACIONALIDAD,
        'pais' => self::COL_NACIONALIDAD,
        'país' => self::COL_NACIONALIDAD,
        'f. nacimiento' => self::COL_NACIMIENTO,
        'fecha de nacimiento' => self::COL_NACIMIENTO,
        'observacion' => self::COL_OBSERVACIONES,
        'observaciones' => self::COL_OBSERVACIONES,
        'notas' => self::COL_OBSERVACIONES,
        'tipo' => self::COL_TIPO,
        'tipo de pasajero' => self::COL_TIPO,
        'rol en el grupo' => self::COL_TIPO,
        'rol' => self::COL_TIPO,
        'telefono' => self::COL_TELEFONO,
        'teléfono' => self::COL_TELEFONO,
        'celular' => self::COL_TELEFONO,
    ];

    /** La columna canónica para una cabecera, o la misma si no hay alias. */
    public static function canonica(string $cabecera): string
    {
        return self::ALIAS[mb_strtolower(trim($cabecera))] ?? trim($cabecera);
    }

    /**
     * ¿Esta cabecera trae el nombre y los apellidos juntos?
     *
     * ⚠️ Partirla es **una conjetura**, y quien la use tiene que enterarse: por convención peruana
     * las dos últimas palabras son los apellidos —«ALEJANDRA LUCILA VALDIVIA BERRIOS»— pero con un
     * extranjero falla —«Todd Joseph Rouse» daría apellido «Joseph Rouse»—. Se avisa siempre.
     */
    public static function esNombreCompleto(string $cabecera): bool
    {
        return in_array(mb_strtolower(trim($cabecera)), ['nombres y apellidos', 'apellidos y nombres', 'nombre completo'], true);
    }

    /**
     * Parte un nombre completo por la convención peruana: las dos últimas palabras son apellidos.
     *
     * @return array{0: string, 1: string} nombres, apellidos
     */
    public static function partirNombre(string $completo): array
    {
        $partes = preg_split('/\s+/', trim($completo)) ?: [];

        if (count($partes) <= 2) {
            return [implode(' ', array_slice($partes, 0, 1)), implode(' ', array_slice($partes, 1))];
        }

        return [
            implode(' ', array_slice($partes, 0, count($partes) - 2)),
            implode(' ', array_slice($partes, -2)),
        ];
    }

    /**
     * A qué banda pertenece una cabecera. Es lo que pinta el color en la plantilla.
     *
     * Tres y no dos: «lo imprescindible», «lo normal» y «sólo si es un grupo». Sin la tercera, quien
     * abre la plantilla para cargar dos pasajeros no sabe cuáles puede borrar.
     */
    public static function bandaDe(string $cabecera): string
    {
        if (self::esColumnaDeEje($cabecera) || self::esColumnaDeServicio($cabecera)) {
            return 'grupo';
        }

        foreach (self::columnasFijas() as $columna) {
            if ($columna['columna'] === $cabecera) {
                return $columna['soloGrupo'] ? 'grupo' : ($columna['obligatoria'] ? 'clave' : 'normal');
            }
        }

        return 'normal';
    }

    /** ¿Esta cabecera es un eje con valor? */
    public static function esColumnaDeEje(string $cabecera): bool
    {
        return str_starts_with(trim($cabecera), self::MARCA_EJE);
    }

    /** ¿Esta cabecera es un servicio (SÍ/NO)? */
    public static function esColumnaDeServicio(string $cabecera): bool
    {
        return str_starts_with(trim($cabecera), self::MARCA_SERVICIO);
    }

    /** El nombre del servicio que hay tras el «+». */
    public static function servicioDe(string $cabecera): string
    {
        return trim(ltrim(trim($cabecera), self::MARCA_SERVICIO));
    }

    /**
     * ¿Esta celda dice que sí participa?
     *
     * Se acepta lo que la gente escribe de verdad —SI, SÍ, X, 1— y **todo lo demás es no**,
     * incluido el vacío: en un padrón de 133 filas, una celda en blanco es «no me consta», y
     * apuntar a alguien a un servicio por descuido cuesta dinero.
     */
    public static function participa(?string $celda): bool
    {
        return in_array(mb_strtoupper(trim((string) $celda)), ['SI', 'SÍ', 'X', '1', 'SÍ.', 'YES'], true);
    }

    /**
     * A qué eje corresponde una cabecera marcada, o `null` si no es ninguno conocido.
     *
     * Devolver `null` en vez de inventar es lo que permite al lector avisar con nombre y apellido:
     * «la columna “#Bus” no corresponde a ningún eje», en vez de tragársela.
     */
    /**
     * Nombres alternativos del eje en la cabecera. `#Reserva aérea Ida` y `#Vuelo Ida` son lo mismo.
     *
     * @var array<string, string>
     */
    public const ALIAS_EJE = [
        'vuelo' => 'reserva_aerea',
        'reserva aerea' => 'reserva_aerea',
        'reserva aérea' => 'reserva_aerea',
        'grupo' => 'grupo',
        'habitacion' => 'habitacion',
        'habitación' => 'habitacion',
    ];

    /**
     * A qué eje —y a qué TRAMO— corresponde una cabecera marcada.
     *
     * ```
     * #Vuelo                 → reserva_aerea, sin tramo
     * #Vuelo Nacional        → reserva_aerea, tramo «Nacional»
     * #Vuelo Cusco-Puno      → reserva_aerea, tramo «Cusco-Puno»
     * #Reserva aérea Ida     → reserva_aerea, tramo «Ida»          (alias)
     * #Habitación            → habitacion,    sin tramo
     * #Bus                   → null                                 (se avisa y se ignora)
     * ```
     *
     * ⚠️ El tramo es **texto libre y no se valida contra nada**, y es deliberado: un multitramo
     * Lima→Cusco→Puno→Lima son cuatro columnas que nadie puede haber previsto. Lo que sí se
     * valida es el EJE, porque de él cuelga cómo se pinta y se filtra.
     *
     * ⚠️ Sólo se parte lo que admite tramo ({@see GrupoTipoEnum::admiteSubeje()}). Sin eso, una
     * cabecera mal escrita como `#Habitacion doble` entraría como eje habitación con tramo
     * «doble» en vez de denunciarse.
     *
     * @return array{tipo: GrupoTipoEnum, subeje: ?string}|null
     */
    public static function ejeDe(string $cabecera): ?array
    {
        $texto = trim(ltrim(trim($cabecera), self::MARCA_EJE));
        $buscada = mb_strtolower($texto);

        // 1. La cabecera entera es un eje: sin tramo.
        foreach (GrupoTipoEnum::cases() as $tipo) {
            if (mb_strtolower($tipo->label()) === $buscada || $tipo->value === $buscada) {
                return ['tipo' => $tipo, 'subeje' => null];
            }
        }
        if (isset(self::ALIAS_EJE[$buscada])) {
            return ['tipo' => GrupoTipoEnum::from(self::ALIAS_EJE[$buscada]), 'subeje' => null];
        }

        // 2. Empieza por un eje que admite tramo: lo que sigue ES el tramo.
        foreach (self::ALIAS_EJE as $prefijo => $valor) {
            $tipo = GrupoTipoEnum::from($valor);
            if (!$tipo->admiteSubeje()) {
                continue;
            }

            if (str_starts_with($buscada, $prefijo.' ')) {
                $subeje = trim(mb_substr($texto, mb_strlen($prefijo) + 1));

                return ['tipo' => $tipo, 'subeje' => $subeje !== '' ? $subeje : null];
            }
        }

        return null;
    }

    /** La cabecera que se escribe para un eje y su tramo: `#Vuelo Nacional`. */
    public static function cabeceraDeEje(GrupoTipoEnum $tipo, ?string $subeje): string
    {
        return self::MARCA_EJE.trim($tipo->label().' '.($subeje ?? ''));
    }

    /**
     * Todas las cabeceras de la plantilla, en orden.
     *
     * @return list<string>
     */
    public static function cabeceras(): array
    {
        return [...self::cabecerasBase(), ...self::cabecerasDeGrupo()];
    }

    /**
     * Sólo lo de cualquier expediente: persona y documentos. Sin `Id`, sin ejes, sin servicios.
     *
     * La exportación parte de aquí y añade **los ejes y servicios que ese expediente usa de
     * verdad**: sacar `+Coco Bongo` de ejemplo junto a `+COCO BONGO` real daba dos columnas para
     * lo mismo, y la segunda ganaba por casualidad de orden.
     *
     * @return list<string>
     */
    public static function cabecerasBase(): array
    {
        // ⚠️ Lo NORMAL primero, y lo de grupo al final en bloque.
        //
        // Un expediente corriente es dos personas con su documento: si el rol y las agrupaciones se
        // intercalan en medio, quien sólo necesita eso tiene que ir saltándoselas. Al final y con
        // otro color, se borran de una pasada.
        $cabeceras = array_column(
            array_filter(
                self::columnasFijas(),
                static fn (array $c): bool => !$c['soloGrupo'] && !$c['soloExport'],
            ),
            'columna',
        );

        foreach (self::columnasDeDocumento() as $doc) {
            $cabeceras[] = $doc['columna'];
            $cabeceras[] = $doc['vencimiento'];
        }

        return $cabeceras;
    }

    /**
     * Las columnas que sólo hacen falta en un padrón: rol, ejes y servicios de ejemplo.
     *
     * @return list<string>
     */
    public static function cabecerasDeGrupo(): array
    {
        $cabeceras = [];

        foreach (self::columnasFijas() as $columna) {
            if ($columna['soloGrupo']) {
                $cabeceras[] = $columna['columna'];
            }
        }

        foreach (self::columnasDeEje() as $eje) {
            $cabeceras[] = $eje['columna'];
        }

        // Los servicios NO salen de un enum: son los de este viaje. La plantilla trae los del
        // padrón real como ejemplo y el operador los cambia — es su lista, no la nuestra.
        foreach (self::SERVICIOS_DE_EJEMPLO as $servicio) {
            $cabeceras[] = self::MARCA_SERVICIO.$servicio;
        }

        return $cabeceras;
    }

    /**
     * Las cabeceras de la hoja «Grupos».
     *
     * @return list<string>
     */
    public static function cabecerasDeLaHojaDeGrupos(): array
    {
        return [self::COL_GRUPO_EJE, self::COL_GRUPO_CLAVE, self::COL_GRUPO_NOMBRE, self::COL_GRUPO_DETALLE];
    }

    /**
     * Parte «Y9KZ7J Jetsmart» en clave y rótulo.
     *
     * ⚠️ **Sólo se usa cuando la columna «Nombre» no dice nada.** La forma canónica —la que
     * escribe la exportación— es una columna para cada cosa, porque la clave es lo que CASA con la
     * columna del pasajero: si la identidad del grupo fuera «Y9KZ7J Jetsmart», habría que escribir
     * eso mismo en las 133 filas.
     *
     * Se admite la forma junta porque es como se teclea de un tirón, y separar por el primer
     * espacio acierta con lo que hay: un localizador aéreo son seis caracteres sin espacios, y un
     * número de habitación tampoco los lleva. Cuando falla —una clave con espacio— la salida es
     * poner el rótulo en su columna, que es la forma buena de todas formas.
     *
     * @return array{0: string, 1: string} clave, nombre
     */
    public static function partirClaveYNombre(string $celda): array
    {
        $partes = preg_split('/\s+/u', trim($celda), 2) ?: [];

        return [$partes[0] ?? '', $partes[1] ?? ''];
    }

    /**
     * La clave con la que se busca un nombre de grupo: `eje|CLAVE`.
     *
     * La clave se normaliza a mayúsculas igual que al crear el grupo, para que «ha50» de la hoja
     * de grupos encuentre al «HA50» de la de pasajeros.
     */
    public static function claveDeGrupo(string $eje, string $clave, ?string $subeje = null): string
    {
        return $eje.'/'.mb_strtolower(trim($subeje ?? '')).'|'.mb_strtoupper(trim($clave));
    }
}
