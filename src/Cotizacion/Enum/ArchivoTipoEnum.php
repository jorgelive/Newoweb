<?php

declare(strict_types=1);

namespace App\Cotizacion\Enum;

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

    /** ¿Lo sube el propio pasajero desde su app, o sólo el operador? */
    public function loSubeElPasajero(): bool
    {
        return match ($this) {
            self::PASAPORTE, self::DNI_ANVERSO, self::DNI_REVERSO, self::AUTORIZACION => true,
            self::BOLETO, self::RESERVA, self::FACTURA, self::OTROS => false,
        };
    }
}
