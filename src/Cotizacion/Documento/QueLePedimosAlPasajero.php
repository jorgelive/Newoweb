<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

use App\Cotizacion\Enum\ArchivoTipoEnum;
use App\Enum\DocumentoTipoEnum;
use DateTimeImmutable;

/**
 * Lo que se le dice al PASAJERO en el momento de subir un documento, cuando hay que pedirle otro.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * Hasta el 17/09/2026 el control corría **después**, desde `util`: el pasajero subía una foto
 * cortada, la app le decía «recibido», y días más tarde alguien del equipo tenía que escribirle
 * para pedírsela otra vez — si llegaba a tiempo. Con el móvil todavía en la mano, repetir la foto
 * cuesta diez segundos; por WhatsApp dos días antes de volar, cuesta una persecución.
 *
 * ── 🔑 LA REGLA: sólo lo que ÉL puede arreglar ──────────────────────────────
 * Se le pide otro documento **sólo por problemas del documento o de la foto**, que se arreglan
 * con otra foto o rellenando bien el trámite. **Nunca porque no coincida con el manifiesto.**
 *
 * | Se le pide otro | NO se le pide otro (lo mira el equipo en `util`) |
 * |---|---|
 * | subió un DNI donde va el pasaporte, o al revés | su número no coincide con el manifiesto |
 * | no se lee | |
 * | la banda de abajo sale cortada o no sale | su nombre no coincide con el manifiesto |
 * | el documento está vencido | el vuelo del E-Ticket no es el de su subgrupo |
 * | subió un billete en vez del E-Ticket | la fecha del E-Ticket no es la del vuelo |
 * | el E-Ticket sólo trae la entrada o sólo la salida | |
 *
 * ⚠️ **La columna de la derecha es la que más tienta y la que más daño haría.** El manifiesto lo
 * tecleamos nosotros y es la fuente de los errores (ver {@see ReferenciaDeIdentidad}); los
 * subgrupos aéreos también son nuestros, y **once de los primeros treinta y dos E-Tickets
 * observados eran fallo nuestro de asignación**, no suyo. Pedirle al pasajero que «corrija» ahí
 * sería mandarle a estropear un trámite que tenía bien.
 *
 * ⚠️ **No se rechaza nada: se pide.** El documento se guarda igual —el equipo puede darlo por
 * bueno viendo la foto, como con una MRZ cortada de un pasaporte vigente— y la siguiente subida lo
 * reemplaza. Esto sólo decide **qué decirle mientras todavía tiene el móvil en la mano**.
 *
 * ⚠️ **Los textos no enseñan ningún dato del manifiesto ni del expediente.** Esta respuesta la ve
 * quien tenga el enlace; un «tu número debería ser 123…» sería filtrar el dato a quien no es.
 *
 * Pura y sin dependencias, para que la regla se pueda probar y leer entera en un sitio.
 */
final readonly class QueLePedimosAlPasajero
{
    /**
     * Para un DNI o un pasaporte.
     *
     * @param DatosDeDocumento|null $leido `null` = no se pudo leer
     *
     * @return list<string> vacío = nada que pedirle
     */
    public static function delDocumento(ArchivoTipoEnum $tipo, ?DatosDeDocumento $leido, ?DateTimeImmutable $hoy = null): array
    {
        $nombre = $tipo === ArchivoTipoEnum::PASAPORTE ? 'tu pasaporte' : 'tu DNI';

        // ⚠️ **El reverso del DNI no lleva el número impreso: sólo sale de la banda.** Si la banda se
        // VIO pero se transcribió mal, el número queda vacío y esto decía «no conseguimos leer tu
        // DNI» — culparle de nuestra lectura, que es justo lo que esta clase existe para no hacer.
        // Otra foto no lo arregla; lo mira el equipo.
        if ($leido !== null && !$leido->esUtilizable() && $tipo === ArchivoTipoEnum::DNI_REVERSO && !$leido->bandaVacia) {
            return [];
        }

        if ($leido === null || !$leido->esUtilizable()) {
            return [sprintf(
                'No conseguimos leer %s. Sácale otra foto con buena luz, sin reflejos, apoyado en una '
                .'mesa y que se vea entero.',
                $nombre,
            )];
        }

        // 🔥 **Primero: ¿es el documento que toca?** Esta pregunta no existía, y un DNI subido por el
        // hueco del pasaporte pasaba. Reproducido con las lecturas reales del 17/09/2026: de 69
        // reversos de DNI, **65 se habrían aceptado como pasaporte sin pedir nada** —su banda TD1
        // cuadra perfectamente, sólo que es la de un DNI—; y a 87 anversos se les habría dicho «no se
        // ve la banda de abajo del pasaporte», que manda a repetir la foto del documento equivocado.
        //
        // 🔑 **Se pregunta al tipo LEÍDO, y se midió antes de fiarse de él**: 323 de 323 escaneos bien
        // etiquetados traían el tipo correcto. Cero falsos positivos, así que no molesta a nadie que
        // lo haya hecho bien.
        //
        // ⚠️ Y **sólo en el eje pasaporte ↔ carné**. En el hueco del DNI no se exige `DNI` a secas: un
        // carné de extranjería o una cédula de otro país leídos como `CE`/`CI` no son un error de
        // este pasajero, y pedirle «tu DNI» a quien no tiene DNI no tiene salida. Lo que sí es un error
        // seguro es un pasaporte donde va un carné, o al revés.
        if ($tipo === ArchivoTipoEnum::PASAPORTE && $leido->tipo !== null && $leido->tipo !== DocumentoTipoEnum::PASAPORTE) {
            return [sprintf(
                'Esto parece %s, no un pasaporte. Sube la página de tu pasaporte que tiene la foto y la '
                .'banda de letras abajo.',
                $leido->tipo === DocumentoTipoEnum::DNI ? 'un DNI' : 'un carné de identidad',
            )];
        }

        if ($tipo !== ArchivoTipoEnum::PASAPORTE && $leido->tipo === DocumentoTipoEnum::PASAPORTE) {
            return [$tipo === ArchivoTipoEnum::DNI_REVERSO
                ? 'Esto parece un pasaporte, no tu DNI. Sube la parte de atrás de tu DNI.'
                : 'Esto parece un pasaporte, no tu DNI. Sube la cara de delante de tu DNI, la de la foto.'];
        }

        $pedir = [];

        // La banda de letras de abajo (MRZ). La lleva la página del pasaporte y el REVERSO del DNI;
        // el anverso del DNI no, y pedírsela sería pedir algo que no existe.
        //
        // 🔑 Es el caso que motivó todo esto: quien opera lo dijo con sus palabras —«normalmente no
        // cuadra porque está incompleto, tomaron la foto de muy cerca»—. Con el móvil en la mano es
        // separarlo un palmo y repetir.
        $llevaBanda = $tipo === ArchivoTipoEnum::PASAPORTE || $tipo === ArchivoTipoEnum::DNI_REVERSO;

        // ⚠️ **`bandaVacia`, no `mrz === null`.** Una banda que se ve pero se transcribió mal también
        // deja la MRZ en `null`, y ahí otra foto no arregla nada: sería culparle de nuestra lectura.
        // Ver {@see DatosDeDocumento::$bandaVacia}. Los avisos de lectura sí: son dígitos de control
        // que no cuadran sobre una banda que SÍ se leyó, y quien opera confirmó la causa — la foto
        // tomada demasiado cerca, con un borde fuera.
        if ($llevaBanda && ($leido->bandaVacia || $leido->avisosDeLectura !== [])) {
            $pedir[] = $tipo === ArchivoTipoEnum::PASAPORTE
                ? 'No se ve entera la banda de letras de abajo del pasaporte. Aleja un poco el móvil y '
                    .'saca la página de la foto completa, de borde a borde.'
                : 'No se ve entera la banda de letras de abajo del DNI. Aleja un poco el móvil y saca '
                    .'la parte de atrás completa, de borde a borde.';
        }

        // ⚠️ **Se pide, no se sentencia.** Si la fecha la leyó un modelo y no la MRZ, puede estar mal
        // leída, y el texto deja las dos salidas: renovarlo o repetir la foto. Un «tu DNI está
        // vencido» seco a quien lo tiene vigente es peor que no decir nada.
        $hoy ??= new DateTimeImmutable('today');
        if ($leido->vencimiento !== null && $leido->vencimiento < $hoy) {
            $pedir[] = sprintf(
                'Parece que %s venció el %s. Para viajar necesitas uno vigente. Si no está vencido, sácale '
                .'otra foto donde se lea bien la fecha.',
                $nombre,
                $leido->vencimiento->format('d/m/Y'),
            );
        }

        return $pedir;
    }

    /**
     * Para el E-Ticket migratorio.
     *
     * @param DatosDeEticket|null $leido `null` = no se pudo leer
     *
     * @return list<string>
     */
    public static function delEticket(?DatosDeEticket $leido): array
    {
        if ($leido !== null && $leido->esOtroDocumento()) {
            // El error más común de todos: «e-ticket» significa «billete» para cualquiera.
            return ['Esto parece un billete de avión o una tarjeta de embarque, no el E-Ticket de '
                .'Migración. El E-Ticket se saca en eticket.migracion.gob.do y lleva un código QR.'];
        }

        if ($leido === null || !$leido->esUtilizable()) {
            return ['No conseguimos leer tu E-Ticket. Sube el PDF que te dio la web de Migración, o una '
                .'captura donde se vea entero.'];
        }

        // ⚠️ Dos hechos que se le PREGUNTARON al documento, no deducciones: ver
        // {@see DatosDeEticket::$traeEntrada}. Una foto cortada por abajo no puede hacer creer que
        // falta la salida.
        if ($leido->traeEntrada && !$leido->traeSalida) {
            return ['Tu E-Ticket sólo tiene la ENTRADA al país. Entra en la web de Migración, rellena '
                .'también la SALIDA y sube el documento nuevo.'];
        }

        if ($leido->traeSalida && !$leido->traeEntrada) {
            return ['Tu E-Ticket sólo tiene la SALIDA del país. Entra en la web de Migración, rellena '
                .'también la ENTRADA y sube el documento nuevo.'];
        }

        return [];
    }
}
