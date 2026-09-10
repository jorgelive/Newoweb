<?php

declare(strict_types=1);

namespace App\Cotizacion\Enum;

use App\Enum\DocumentoTipoEnum;

/**
 * Qué clase de archivo es un adjunto del expediente.
 *
 * ⚠️ **Esto NO dice de quién es**: el alcance —del expediente, de un subgrupo, de una persona— lo
 * dicen las relaciones de {@see \App\Cotizacion\Entity\CotizacionFilearchivo}. Un mismo tipo vale
 * para los tres: un boleto puede ser del grupo (el namelist con el PNR) o de una persona (su
 * boarding pass).
 */
enum ArchivoTipoEnum: string
{
    case BOLETO = 'boleto';
    case FACTURA = 'factura';
    case RESERVA = 'reserva';

    /**
     * El escaneo de un pasaporte, y el del DNI **por cada cara**.
     *
     * ⚠️ Son dos casos y no uno con un campo «cara» porque un DNI necesita **las dos**: en un
     * control migratorio no vale sólo el anverso. Con un caso por cara, «¿le falta algo a esta
     * persona?» se contesta mirando qué tipos tiene, sin recorrer un segundo campo.
     *
     * ⚠️ Y **no se devuelven al pasajero** aunque sean suyos ({@see self::esDevolvibleAlPasajero()}).
     */
    case PASAPORTE = 'pasaporte';
    case DNI_ANVERSO = 'dni_anverso';
    case DNI_REVERSO = 'dni_reverso';

    /** Lo que un menor necesita para salir del país. Con 100 menores en un grupo, no es un extra. */
    case AUTORIZACION = 'autorizacion';

    case OTROS = 'otros';

    public function getLabel(): string
    {
        return match ($this) {
            self::BOLETO => 'Boleto / Ticket',
            self::FACTURA => 'Factura / Recibo',
            self::RESERVA => 'Confirmación de Reserva',
            self::PASAPORTE => 'Pasaporte (escaneo)',
            self::DNI_ANVERSO => 'DNI — anverso',
            self::DNI_REVERSO => 'DNI — reverso',
            self::AUTORIZACION => 'Autorización notarial',
            self::OTROS => 'Otros Documentos',
        };
    }

    /**
     * ¿Se le enseña al cliente en el visor público?
     *
     * Los administrativos —factura— y los de clasificación ambigua quedan fuera. Los escaneos de
     * identidad **también**, y por otro motivo: {@see self::esDevolvibleAlPasajero()}.
     */
    public function esPublico(): bool
    {
        return match ($this) {
            self::BOLETO, self::RESERVA => true,
            self::FACTURA, self::OTROS, self::PASAPORTE, self::DNI_ANVERSO, self::DNI_REVERSO, self::AUTORIZACION => false,
        };
    }

    /**
     * ¿Se le puede DEVOLVER al pasajero que se identificó?
     *
     * 🔥 **El boarding pass sí, y es indispensable**: lo enseña él en el gate. Por eso baja hasta
     * su móvil, donde funciona sin cobertura y sin depender de nosotros.
     *
     * ⚠️ **El escaneo de su propio documento, no.** Lo sube él y ahí se acaba. Poder volver a
     * descargarlo no le da nada que no tenga ya —el pasaporte lo lleva en el bolsillo— y añade una
     * vía más por la que esa imagen puede salir: un móvil prestado, una sesión abierta, un
     * reenvío. Subir es de quien es el documento; mirar es de quien lo tramita.
     */
    public function esDevolvibleAlPasajero(): bool
    {
        return match ($this) {
            self::BOLETO, self::RESERVA => true,
            self::PASAPORTE, self::DNI_ANVERSO, self::DNI_REVERSO, self::AUTORIZACION, self::FACTURA, self::OTROS => false,
        };
    }

    /**
     * ¿Es un documento que hay que poder **leer**, y no sólo mirar?
     *
     * Un boleto se abre en el gate y basta con que se vea; de un pasaporte se copian un número y
     * una fecha de caducidad. Por eso estos se comprimen con otro filtro
     * ({@see \App\Panel\Contract\RequiereAltaFidelidadInterface}).
     */
    public function esEscaneoDeIdentidad(): bool
    {
        return match ($this) {
            self::PASAPORTE, self::DNI_ANVERSO, self::DNI_REVERSO, self::AUTORIZACION => true,
            self::BOLETO, self::RESERVA, self::FACTURA, self::OTROS => false,
        };
    }

    /**
     * ¿Se puede someter al control de validación?
     *
     * ⚠️ **Es MÁS ESTRECHO que {@see self::esEscaneoDeIdentidad()}, y la diferencia importa.** Ahí
     * caben los cuatro documentos que llevan datos personales —y por eso se comprimen con el
     * filtro bueno y caducan pronto—; aquí sólo los dos que traen **un número y un nombre que
     * cotejar**.
     *
     * 🔥 **El reverso del DNI no es un documento mal leído: es un documento que no se puede
     * validar.** Metido en la misma cola salía como «no se pudo leer el número», que suena a fallo
     * de calidad y manda a alguien a pedir otra vez un escaneo mejor de algo que nunca tuvo el
     * dato. En una tanda real fueron 2 de 12 filas de ruido; con 86 reversos en el expediente,
     * serían 86 — y una cola con más ruido que trabajo se deja de mirar entera.
     *
     * La autorización notarial, igual: es un permiso, no una identidad.
     */
    /**
     * Qué NÚMERO del manifiesto respalda este escaneo, si respalda alguno.
     *
     * 🔥 **La única definición de la pareja escaneo↔número.** Llegó a estar en tres sitios —el
     * validador, el listener que invalida veredictos y, en el front, deducida a ojo— y son mapeos
     * que tienen que decir lo mismo o el sistema se contradice: uno valida el DNI contra el
     * anverso y otro cree que lo respalda otra cosa.
     *
     * ⚠️ El reverso del DNI y la autorización **no respaldan ningún número**: no lo llevan. Eso no
     * es una carencia del escaneo, es lo que son.
     */
    public function respaldaA(): ?DocumentoTipoEnum
    {
        return match ($this) {
            self::DNI_ANVERSO => DocumentoTipoEnum::DNI,
            self::PASAPORTE => DocumentoTipoEnum::PASAPORTE,
            self::DNI_REVERSO, self::AUTORIZACION, self::BOLETO, self::RESERVA, self::FACTURA, self::OTROS => null,
        };
    }

    /** El camino inverso: qué escaneo hace falta para poder validar este número. */
    public static function paraValidar(DocumentoTipoEnum $tipo): ?self
    {
        foreach (self::cases() as $caso) {
            if ($caso->respaldaA() === $tipo) {
                return $caso;
            }
        }

        return null;
    }

    public function esValidable(): bool
    {
        return match ($this) {
            self::PASAPORTE, self::DNI_ANVERSO => true,
            self::DNI_REVERSO, self::AUTORIZACION, self::BOLETO, self::RESERVA, self::FACTURA, self::OTROS => false,
        };
    }

    /**
     * Cuántos meses se guarda **después del retorno del grupo**. `null` = no caduca.
     *
     * 🔥 **Es la única fuente de la caducidad.** No hay columna `caduca_el`: la fecha se calcula
     * cada vez a partir del retorno del expediente, así que si el viaje se mueve, la caducidad se
     * mueve con él. Una fecha guardada se habría quedado apuntando al viaje que se planeó.
     *
     * ⚠️ **El boarding pass caduca igual que el pasaporte, y no es exceso de celo.** Lleva nombre
     * completo, vuelo, asiento y el LOCALIZADOR: con apellido y localizador se entra a la reserva
     * en la web de la aerolínea. Pasado el viaje no aporta nada que compense tenerlo — y son ~542
     * ficheros por grupo grande, sin techo.
     *
     * ⚠️ **`OTROS` no caduca a propósito.** Es el cajón donde acaba lo que no encajó en ningún
     * tipo, así que no sabemos qué hay dentro; borrar por defecto lo que no se ha clasificado es
     * cómo se pierde el único ejemplar de algo. Se queda hasta que alguien le ponga su tipo.
     *
     * ⚠️ Y esto **no toca {@see \App\Cotizacion\Entity\CotizacionPasajeroIdentificacion}**, que
     * es el DATO —tipo, número, vencimiento, país— y se queda para siempre. Esa separación es lo
     * que permite borrar la foto sin romper el expediente.
     */
    public function mesesDeRetencion(): ?int
    {
        return match ($this) {
            self::PASAPORTE, self::DNI_ANVERSO, self::DNI_REVERSO, self::AUTORIZACION, self::BOLETO => 1,
            self::FACTURA, self::RESERVA, self::OTROS => null,
        };
    }

    /** ¿Lo sube el propio pasajero desde su app, o sólo el operador? */
    public function loSubeElPasajero(): bool
    {
        return match ($this) {
            self::PASAPORTE, self::DNI_ANVERSO, self::DNI_REVERSO, self::AUTORIZACION => true,
            self::BOLETO, self::RESERVA, self::FACTURA, self::OTROS => false,
        };
    }
}
