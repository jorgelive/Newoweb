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
     * @return list<array{columna: string, obligatoria: bool, ayuda: string}>
     */
    public static function columnasFijas(): array
    {
        return [
            ['columna' => self::COL_NOMBRES, 'obligatoria' => true,
                'ayuda' => 'Nombres de pila. Es lo único imprescindible.'],
            ['columna' => self::COL_APELLIDOS, 'obligatoria' => false,
                'ayuda' => 'Apellidos, en su propia columna: partir «Nombres y Apellidos» a ojo se equivoca con dos nombres y dos apellidos.'],
            ['columna' => self::COL_NACIONALIDAD, 'obligatoria' => false,
                'ayuda' => 'Código ISO de dos letras (PE, US, AR) o el nombre del país. Vacío se toma el del expediente.'],
            ['columna' => self::COL_SEXO, 'obligatoria' => false,
                'ayuda' => 'M o F.'],
            ['columna' => self::COL_NACIMIENTO, 'obligatoria' => false,
                'ayuda' => 'DD/MM/AAAA. Hace falta para saber quién viaja como menor.'],
            ['columna' => self::COL_OBSERVACIONES, 'obligatoria' => false,
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
        $cabeceras = array_column(self::columnasFijas(), 'columna');

        foreach (self::columnasDeDocumento() as $doc) {
            $cabeceras[] = $doc['columna'];
            $cabeceras[] = $doc['vencimiento'];
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
