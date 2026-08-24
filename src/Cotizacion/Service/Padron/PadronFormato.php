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
    public const MARCA_EJE = '#';
    public const MARCA_SERVICIO = '+';
    public const PREFIJO_VENCIMIENTO = 'Venc. ';

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
     * @return list<array{columna: string, obligatoria: bool, soloGrupo: bool, ayuda: string}>
     */
    public static function columnasFijas(): array
    {
        return [
            ['columna' => self::COL_NOMBRES, 'obligatoria' => true, 'soloGrupo' => false,
                'ayuda' => 'Nombres de pila. Es lo único imprescindible.'],
            ['columna' => self::COL_APELLIDOS, 'obligatoria' => false, 'soloGrupo' => false,
                'ayuda' => 'Apellidos, en su propia columna: partir «Nombres y Apellidos» a ojo se equivoca con dos nombres y dos apellidos.'],
            ['columna' => self::COL_NACIONALIDAD, 'obligatoria' => false, 'soloGrupo' => false,
                'ayuda' => 'Código ISO de dos letras. Los 198 están en la hoja «Tablas». Vacío se toma el del expediente.'],
            ['columna' => self::COL_SEXO, 'obligatoria' => false, 'soloGrupo' => false,
                'ayuda' => 'M o F.'],
            ['columna' => self::COL_NACIMIENTO, 'obligatoria' => false, 'soloGrupo' => false,
                'ayuda' => 'DD/MM/AAAA. Hace falta para saber quién viaja como menor.'],
            ['columna' => self::COL_TIPO, 'obligatoria' => false, 'soloGrupo' => true,
                'ayuda' => 'Uno EXACTO de la hoja «Tablas» (hay desplegable). De aquí cuelga qué ve cada '
                    .'uno al consultar su viaje y si aparece ante los demás, así que un valor a medias no vale.'],
            ['columna' => self::COL_TELEFONO, 'obligatoria' => false, 'soloGrupo' => false,
                'ayuda' => 'El suyo, no el del expediente: con 133 personas hay 133 familias a las que llamar.'],
            ['columna' => self::COL_OBSERVACIONES, 'obligatoria' => false, 'soloGrupo' => false,
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
     * Las columnas de eje que trae la plantilla, ya marcadas.
     *
     * @return list<array{columna: string, tipo: GrupoTipoEnum}>
     */
    public static function columnasDeEje(): array
    {
        // Sin el SERVICIO: es binario y va con «+», no con «#».
        return array_values(array_map(
            static fn (GrupoTipoEnum $t): array => [
                'columna' => self::MARCA_EJE.$t->label(),
                'tipo' => $t,
            ],
            array_filter(GrupoTipoEnum::cases(), static fn (GrupoTipoEnum $t): bool => $t->esEjeConValor()),
        ));
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
    public static function ejeDe(string $cabecera): ?GrupoTipoEnum
    {
        $buscada = mb_strtolower(trim(ltrim(trim($cabecera), self::MARCA_EJE)));

        foreach (GrupoTipoEnum::cases() as $tipo) {
            if (mb_strtolower($tipo->label()) === $buscada || $tipo->value === $buscada) {
                return $tipo;
            }
        }

        return null;
    }

    /**
     * Todas las cabeceras de la plantilla, en orden.
     *
     * @return list<string>
     */
    public static function cabeceras(): array
    {
        // ⚠️ Lo NORMAL primero, y lo de grupo al final en bloque.
        //
        // Un expediente corriente es dos personas con su documento: si el rol y las agrupaciones se
        // intercalan en medio, quien sólo necesita eso tiene que ir saltándoselas. Al final y con
        // otro color, se borran de una pasada.
        $cabeceras = array_column(
            array_filter(self::columnasFijas(), static fn (array $c): bool => !$c['soloGrupo']),
            'columna',
        );

        foreach (self::columnasDeDocumento() as $doc) {
            $cabeceras[] = $doc['columna'];
            $cabeceras[] = $doc['vencimiento'];
        }

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
}
