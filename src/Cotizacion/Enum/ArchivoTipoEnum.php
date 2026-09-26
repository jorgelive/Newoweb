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
    /**
     * Los tres tickets, que **fueron un solo `boleto`** hasta el 18/09/2026.
     *
     * 🔥 **El tipo genérico impedía justo lo que se le pedía al sistema**: decidir qué ve el
     * pasajero. En un expediente real había 123 tarjetas de embarque y, con el MISMO tipo, la
     * entrada a Huayna Picchu, el tren de retorno y el bus — así que exponer la tarjeta de embarque
     * era exponer también las entradas del grupo, y esconder una escondía la otra. No es un
     * refinamiento de nomenclatura: era la unidad con la que se configura la exposición
     * ({@see \App\Cotizacion\Entity\CotizacionFile::exponeAlPasajero()}).
     *
     * El reparto sale de los datos, no de la teoría: `Version20260918...` mandó al aéreo todo lo
     * que tenía vuelo y clasificó a mano los tres que no.
     *
     * ⚠️ El tren y el bus comparten tipo con criterio: los dos los lleva el guía, ninguno se
     * valida y los dos se exponen igual. Un tipo por medio de transporte no daría ni una decisión
     * distinta, y sí tres `match` más que mantener.
     */
    case TICKET_AEREO = 'ticket_aereo';
    case TICKET_INGRESO = 'ticket_ingreso';
    case TICKET_TRANSPORTE = 'ticket_transporte';

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

    /**
     * El **E-Ticket de República Dominicana**: el formulario electrónico de entrada y salida que
     * exige su Dirección General de Migración, con su código QR.
     *
     * ⚠️ **No es un billete de avión**, aunque el nombre lo sugiera —así lo llama el gobierno
     * dominicano, y así lo va a buscar el pasajero—. Es un trámite migratorio que rellena **él** en
     * la web oficial y que descarga en PDF; nosotros no lo podemos emitir en su nombre. Por eso
     * vive junto a {@see self::AUTORIZACION} y no junto a {@see self::TICKET_AEREO}: los dos son permisos
     * de frontera que hay que reunir antes de viajar, no documentos de vuelo que damos nosotros.
     *
     * Es el único documento de esta lista **atado a un destino concreto**. Ver el aviso de alcance
     * en `docs/Cotizaciones.md`.
     */
    case ETICKET = 'eticket';

    case OTROS = 'otros';

    public function getLabel(): string
    {
        return match ($this) {
            self::TICKET_AEREO => 'Tarjeta de embarque / boleto aéreo',
            self::TICKET_INGRESO => 'Entrada (ingreso a atracción)',
            self::TICKET_TRANSPORTE => 'Tren o bus',
            self::FACTURA => 'Factura / Recibo',
            self::RESERVA => 'Confirmación de Reserva',
            self::PASAPORTE => 'Pasaporte (escaneo)',
            self::DNI_ANVERSO => 'DNI — anverso',
            self::DNI_REVERSO => 'DNI — reverso',
            self::AUTORIZACION => 'Autorización notarial',
            self::ETICKET => 'E-Ticket migratorio (Rep. Dominicana)',
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
            self::TICKET_AEREO, self::TICKET_INGRESO, self::TICKET_TRANSPORTE, self::RESERVA => true,
            // El e-ticket SIEMPRE tiene dueño, así que quien manda es `esDevolvibleAlPasajero()`:
            // esta rama sólo decide sobre los adjuntos sin dueño de la portada.
            self::ETICKET, self::FACTURA, self::OTROS, self::PASAPORTE, self::DNI_ANVERSO, self::DNI_REVERSO, self::AUTORIZACION => false,
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
            // 🔥 **El E-Ticket se devolvió a la lista el 17/09/2026.** Estaba fuera «por lo mismo
            // que el pasaporte»: lo descargó él de la web de Migración, así que ya lo tiene. Con el
            // viaje encima resultó falso en la práctica — lo rellenó semanas antes en un móvil, el
            // PDF se perdió entre descargas y en el mostrador dominicano hay que ENSEÑARLO. A
            // diferencia de un pasaporte, el original no se lleva en el bolsillo.
            //
            // ⚠️ Y no se cuela en «Tus tarjetas de embarque», que era la otra objeción y sigue en
            // pie: el front lo separa por `tipo` en su propio panel. Ver `PaxCotizacionGuiaView`.
            self::TICKET_AEREO, self::TICKET_INGRESO, self::TICKET_TRANSPORTE, self::RESERVA, self::ETICKET => true,
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
            // Es un permiso, no una identidad: no hay número que cotejar contra el manifiesto.
            // Y llega como PDF nativo, así que el filtro de alta fidelidad sería gasto por nada.
            self::ETICKET, self::TICKET_AEREO, self::TICKET_INGRESO, self::TICKET_TRANSPORTE, self::RESERVA, self::FACTURA, self::OTROS => false,
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
     * ⚠️ **«Respaldar» aquí es llevar el número IMPRESO en claro**, que es lo que se coteja contra
     * el manifiesto. La autorización no lleva ninguno. El reverso del DNI tampoco lo lleva impreso
     * — pero sí lo lleva en la MRZ, y eso es otra cosa: {@see verificaA()}.
     */
    public function respaldaA(): ?DocumentoTipoEnum
    {
        return match ($this) {
            self::DNI_ANVERSO => DocumentoTipoEnum::DNI,
            self::PASAPORTE => DocumentoTipoEnum::PASAPORTE,
            self::DNI_REVERSO, self::AUTORIZACION, self::TICKET_AEREO, self::TICKET_INGRESO, self::TICKET_TRANSPORTE, self::ETICKET, self::RESERVA, self::FACTURA, self::OTROS => null,
        };
    }

    /**
     * Qué número puede **verificar por MRZ** este escaneo: la banda con dígitos de control.
     *
     * 🔥 **Aquí decía que el reverso del DNI no lleva número, y era FALSO.** El DNIe peruano lleva
     * su MRZ TD1 —tres líneas de 30— en el **reverso**, y es la única cara que la lleva: el
     * anverso no tiene banda ninguna. Como el reverso estaba marcado como que no respalda nada, no
     * se leía nunca, así que **ningún DNI podía llegar a «validado por MRZ»**: todos se quedaban
     * en el cotejo con el manifiesto, y los que no tenían con qué cotejar, en observado.
     *
     * Y encima el aviso decía «sin banda MRZ legible (revisa la calidad del escaneo)», mandando a
     * arreglar una foto impecable por una banda que estábamos buscando en la cara equivocada.
     *
     * Se separa de `respaldaA()` a propósito: son dos preguntas distintas y el DNI las contesta
     * con caras distintas. Juntarlas obligaría a que la cara del número impreso y la de la banda
     * fueran la misma, que es justo lo que no pasa.
     */
    public function verificaA(): ?DocumentoTipoEnum
    {
        return match ($this) {
            // El pasaporte se verifica solo: número impreso y banda van en la misma página.
            self::PASAPORTE => DocumentoTipoEnum::PASAPORTE,
            self::DNI_REVERSO => DocumentoTipoEnum::DNI,
            self::DNI_ANVERSO, self::AUTORIZACION, self::TICKET_AEREO, self::TICKET_INGRESO, self::TICKET_TRANSPORTE, self::ETICKET, self::RESERVA, self::FACTURA, self::OTROS => null,
        };
    }

    /** El camino inverso: qué escaneo lleva la banda que verifica este número. */
    public static function paraVerificar(DocumentoTipoEnum $tipo): ?self
    {
        foreach (self::cases() as $caso) {
            if ($caso->verificaA() === $tipo) {
                return $caso;
            }
        }

        return null;
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
            self::DNI_REVERSO, self::AUTORIZACION, self::TICKET_AEREO, self::TICKET_INGRESO, self::TICKET_TRANSPORTE, self::ETICKET, self::RESERVA, self::FACTURA, self::OTROS => false,
        };
    }

    /**
     * Cuántos meses se guarda **después del retorno del grupo**. `null` = no caduca.
     *
     * ⚠️ **Seis meses desde el 26/09/2026; antes era uno.** Lo decidió el operador al cerrar el
     * primer grupo grande: un mes se quedaba corto para lo que llega tarde —una reclamación a la
     * aerolínea, un seguro, una consulta de Migración— y el escaneo es lo único que lo respalda.
     * El precio es guardar cinco meses más documentos de identidad de terceros, muchos de menores:
     * no bajar de aquí sin una razón igual de concreta.
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
            // El E-Ticket migratorio caduca con los demás: lleva nombre, número de pasaporte y
            // el itinerario. Pasado el viaje ya cumplió su función y no compensa guardarlo.
            self::PASAPORTE, self::DNI_ANVERSO, self::DNI_REVERSO, self::AUTORIZACION, self::TICKET_AEREO, self::TICKET_INGRESO, self::TICKET_TRANSPORTE, self::ETICKET => 6,
            self::FACTURA, self::RESERVA, self::OTROS => null,
        };
    }

    /**
     * Los tipos que un expediente puede EXIGIR a sus pasajeros.
     *
     * ⚠️ Es exactamente el conjunto de {@see self::loSubeElPasajero()}, derivado y no escrito a
     * mano: pedir algo que el pasajero no puede subir sería pedirle lo imposible, y una segunda
     * lista acabaría discrepando con la primera el día que se añada un tipo.
     *
     * Lo consume la validación de `CotizacionFile::$documentosPedidos` y el selector de `util`.
     *
     * @return list<string>
     */
    public static function pedibles(): array
    {
        return array_values(array_map(
            static fn (self $c): string => $c->value,
            array_filter(self::cases(), static fn (self $c): bool => $c->loSubeElPasajero()),
        ));
    }

    /**
     * Los tipos que un expediente PUEDE decidir exponerle al pasajero.
     *
     * ⚠️ **No es «todos».** La factura queda fuera: es el documento fiscal del titular, y en un
     * grupo el titular no es quien se identifica. Lo demás se puede marcar, **incluidos los
     * escaneos de identidad** — hay operaciones que quieren devolverle su propio pasaporte y no
     * es asunto del código decidirlo por ellas—, pero esos van aparte en la pantalla y avisados:
     * ver {@see self::exponerEsSensible()}.
     *
     * @return list<string>
     */
    public static function exponibles(): array
    {
        return array_values(array_map(
            static fn (self $c): string => $c->value,
            array_filter(self::cases(), static fn (self $c): bool => $c !== self::FACTURA),
        ));
    }

    /**
     * ¿Exponer este tipo merece un aviso antes de marcarlo?
     *
     * 🔥 **Sí para los escaneos de identidad**, y no es paternalismo: en un expediente hay 100
     * menores y sus pasaportes. Marcar «Pasaporte» no puede costar el mismo clic que marcar
     * «Tarjeta de embarque», porque el error se paga en datos de terceros que no se pueden
     * recuperar una vez vistos. La UI los pinta en su propio bloque; el enum se limita a decir
     * cuáles son, que es lo que sabe.
     */
    public function exponerEsSensible(): bool
    {
        return $this->esEscaneoDeIdentidad();
    }

    /** ¿Lo sube el propio pasajero desde su app, o sólo el operador? */
    public function loSubeElPasajero(): bool
    {
        return match ($this) {
            self::PASAPORTE, self::DNI_ANVERSO, self::DNI_REVERSO, self::AUTORIZACION, self::ETICKET => true,
            self::TICKET_AEREO, self::TICKET_INGRESO, self::TICKET_TRANSPORTE, self::RESERVA, self::FACTURA, self::OTROS => false,
        };
    }
}
