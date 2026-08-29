<?php

declare(strict_types=1);

namespace App\Travel\Enum;

/**
 * Clasifica la naturaleza operativa del servicio.
 * Determina reglas de negocio críticas en la UI, como la exigencia de horarios exactos o dependencias de duración.
 */
enum ComponenteTipoEnum: string
{
    case TICKET_HORARIO_FIJO = 'ticket_fijo';
    case TICKET_HORARIO_VAR = 'ticket_variable';
    case GUIADO = 'guiado';
    case TRANSPORTE = 'transporte';
    case ALOJAMIENTO = 'alojamiento';

    case ALIMENTACION_HORARIO_FIJO = 'alimentacion_fijo';
    case ALIMENTACION_HORARIO_VAR = 'alimentacion_variable';
    case EXCURSION_POOL = 'pool';
    case EXCURSION_PRIVADA = 'privada';
    case PERSONAL_EXTRA = 'personal_extra';
    case EXTRAS = 'extras';
    case VUELO = 'vuelo';
    case TREN = 'tren';

    /**
     * El encuentro con el pasajero: alguien lo espera, a una hora, en un sitio.
     *
     * ⚠️ **No es el guiado, y confundirlos cuesta caro.** El guiado de Machu Picchu ocurre ARRIBA,
     * en el santuario; el contacto ocurre en la estación o en el hotel, horas antes y a cuatro
     * horas de distancia. Metidos en el mismo componente, el «dónde recojo» de la orden diría uno
     * de los dos y el proveedor iría al otro — con una orden que se lee perfectamente bien.
     *
     * Existe como componente y no como campo del guiado porque **es un servicio que se pide**:
     * alguien tiene que estar ahí con el cartel. Y por eso va con tarifa, aunque sea de 0: un
     * componente sin tarifa es «sólo referencia» y NO entra en una Orden de Servicio, así que se
     * quedaría visible para operaciones e invisible para quien tiene que ir.
     *
     * Que se quite del itinerario significa algo concreto y querido: el pasajero sube por su
     * cuenta y el guía lo espera arriba.
     */
    case CONTACTO = 'contacto';

    /**
     * ¿Dónde empieza y dónde termina este servicio? — {@see PuntosDeServicio}
     *
     * Es lo primero que pregunta el proveedor al recibir la orden: **dónde recojo y dónde
     * dejo**. La respuesta no depende del servicio concreto sino de su NATURALEZA, y por eso
     * vive aquí y no en cada componente: un ticket nunca recogerá a nadie, por mucho que se le
     * rellene el campo.
     *
     * ```
     * INICIO_Y_FIN   transporte · tren · vuelo · pool · privada
     * SOLO_INICIO    guiado · contacto — se presentan en un punto y ahí acaba su parte
     * NINGUNO        tickets · comidas · extras · personal · alojamiento
     * ```
     *
     * ⚠️ **Las excursiones llevan punto de recojo, y no es un detalle.** Un `pool` recoge al
     * pasajero en su hotel y lo devuelve: es de lo primero que se coordina, y meterlas en el
     * saco de «no aplica» dejaría sin dato justo a las que más lo necesitan.
     *
     * ⚠️ **`ALOJAMIENTO` devuelve `NINGUNO` y aun así es la pieza clave.** No recoge a nadie
     * —es un sitio, no un traslado— pero es lo que dice DÓNDE ESTÁ el pasajero cada noche, y de
     * ahí se deducen los puntos de todo lo demás. Para eso está {@see self::esAnclaDeUbicacion()},
     * que es una pregunta distinta y merecía su propio método en vez de un `=== 'alojamiento'`
     * repartido por ahí.
     *
     * ⚠️ **`VUELO` y `TREN` los tienen, y además parten el día** — {@see self::esSalto()}.
     *
     * 🚧 **Eso último todavía NO lo implementa ningún resolvedor**, y conviene saberlo antes de
     * confiar en ello: un abarcador en un día con tren toma el primer y el último segmento del
     * día tal cual, sin partirlo por el vuelo. Hoy no muerde porque los abarcadores son full-days
     * dentro de una misma ciudad. El día que se monte uno multi-ciudad, hará falta escribirlo.
     * Anotado en `docs/Pendientes.md`.
     */
    public function puntosDeServicio(): PuntosDeServicio
    {
        return match ($this) {
            self::TRANSPORTE, self::TREN, self::VUELO,
            self::EXCURSION_POOL, self::EXCURSION_PRIVADA => PuntosDeServicio::INICIO_Y_FIN,

            self::GUIADO, self::CONTACTO => PuntosDeServicio::SOLO_INICIO,

            default => PuntosDeServicio::NINGUNO,
        };
    }

    /**
     * ¿Este servicio dice dónde ESTÁ el pasajero, en vez de moverlo?
     *
     * Sólo el alojamiento. Encadenando sus noches se sabe dónde duerme cada día, y de ahí sale
     * el punto de recojo de todo lo demás: se recoge donde durmió y se deja donde dormirá.
     */
    public function esAnclaDeUbicacion(): bool
    {
        return $this === self::ALOJAMIENTO;
    }

    /**
     * ¿Cambia de ciudad, partiendo el día en dos?
     *
     * Un vuelo o un tren dividen la jornada: lo que ocurre antes termina en su punto de salida
     * —el aeropuerto, la estación— y lo posterior empieza en el de llegada. Sin esto, un
     * traslado de la mañana se leería como si terminara en el hotel de destino, que está en otra
     * ciudad.
     */
    /**
     * ¿El servicio se comparte con otros pasajeros, o va sólo para este grupo?
     *
     * Decide **dónde se les deja al terminar**, y la regla es del negocio, no del código: lo
     * privado devuelve al hotel de cada uno; lo compartido deja a todos en el centro de la
     * ciudad, porque un bus con doce pasajeros de nueve hoteles no puede hacer nueve paradas.
     *
     * ⚠️ **`TRANSPORTE` cuenta como privado y no es un descuido.** En este catálogo las
     * versiones privadas de una excursión se montan con un componente de transporte propio
     * —«Transporte Vinicunca», «Transporte Combinada»— y no con `EXCURSION_PRIVADA`, del que
     * sólo hay dos en todo el maestro. Clasificar por el nombre del caso en vez de por cómo se
     * usa habría dejado a los privados devolviendo al centro.
     */
    public function esCompartido(): bool
    {
        return $this === self::EXCURSION_POOL;
    }

    public function esSalto(): bool
    {
        return $this === self::VUELO || $this === self::TREN;
    }

    /**
     * Define si la UI (Vue) debe exigir y mostrar un selector de hora específica (H:i).
     * Si retorna false, el backend debe forzar la hora a '00:00:00' al persistir.
     *
     * @return bool
     */
    public function sinHorario(): bool
    {
        return match($this) {
            self::TREN,
            self::VUELO,
            self::TRANSPORTE,
            self::TICKET_HORARIO_FIJO,
            self::ALIMENTACION_HORARIO_FIJO,
            self::EXCURSION_POOL,
            self::EXCURSION_PRIVADA,
            self::GUIADO,
            self::CONTACTO => false,

            self::ALOJAMIENTO,
            self::TICKET_HORARIO_VAR,
            self::ALIMENTACION_HORARIO_VAR,
            self::EXTRAS,
            self::PERSONAL_EXTRA => true,
        };
    }

    /**
     * Establece la prioridad visual para el prestador en los manifiestos y reportes operativos.
     * Menor número indica mayor prioridad (aparece antes).
     *
     * @return int
     */
    public function prioridad(): int
    {
        return match($this) {
            // El contacto va PRIMERO: es lo que ocurre antes que nada ese día, y en el manifiesto
            // del proveedor tiene que leerse antes que el servicio al que da paso.
            self::CONTACTO => 0,
            self::GUIADO, self::TRANSPORTE, self::EXCURSION_POOL, self::EXCURSION_PRIVADA, self::TREN => 1,
            self::ALOJAMIENTO, self::VUELO => 2,
            self::ALIMENTACION_HORARIO_FIJO,  self::ALIMENTACION_HORARIO_VAR=> 3,
            self::TICKET_HORARIO_FIJO, self::TICKET_HORARIO_VAR => 4,
            default => 5,
        };
    }

    /**
     * Dónde va este tipo dentro de su JORNADA cuando se cuenta el viaje.
     *
     * ⚠️ **El orden cronológico no es el orden narrativo, y ése era el problema.** Los componentes
     * salían por `fechaHoraInicio`, y como el check-in de un hotel es a media tarde, el alojamiento
     * aterrizaba **en medio del día** —entre el traslado de la mañana y la excursión— en todas las
     * vistas a la vez. Nadie cuenta un día así: se llega, se hace lo del día, se come y se duerme.
     *
     * Números con hueco (10, 20, 30…) para poder intercalar sin renumerar lo que ya existe.
     *
     * ⚠️ **Es sólo para LEER.** No cambia horas ni fechas, no toca la base y no decide nada de
     * operación: el tráfico sigue mandándose por hora real. Ver `docs/Cotizaciones.md`.
     */
    public function ordenNarrativo(): int
    {
        return match ($this) {
            // Llegar y moverse abre la jornada: es lo que pasa primero de verdad.
            self::VUELO, self::TREN, self::TRANSPORTE => 10,
            // El contacto es de la llegada: quien recibe, recibe al principio.
            self::CONTACTO => 20,
            // El cuerpo del día.
            self::EXCURSION_POOL, self::EXCURSION_PRIVADA, self::GUIADO => 30,
            self::TICKET_HORARIO_FIJO, self::TICKET_HORARIO_VAR => 40,
            self::ALIMENTACION_HORARIO_FIJO, self::ALIMENTACION_HORARIO_VAR => 50,
            // Lo accesorio, antes de cerrar.
            self::PERSONAL_EXTRA, self::EXTRAS => 60,
            // Dormir CIERRA el día. Es la razón de existir de este método.
            self::ALOJAMIENTO => 90,
        };
    }

    /**
     * ¿Quién identifica la fila: el SEGMENTO o el componente?
     *
     * Decide qué nombre va en grande en La Biblia y en la Orden que lee el proveedor. **Espejo de
     * `mandaElSegmento()` en `util/src/views/Operacion/OperacionView.vue`** — si cambia la regla,
     * se tocan LOS DOS.
     *
     * **Sólo `TRANSPORTE`**, y el motivo no es una preferencia de diseño: es un hueco del modelo.
     * `travel_componente` **no tiene columnas de origen ni de destino** —las tiene el segmento,
     * en `inicioPunto`/`finPunto`, y a veces como *modo*: «acaba en el alojamiento del
     * pasajero»—. Un componente de transporte **no puede** decir a dónde va hoy, y desde el
     * refactor del 29/08/2026 ni siquiera lo intenta: `Transporte Cusco ↔ Ollanta (ida o vuelta)`
     * sirve a tres segmentos a propósito.
     *
     * En todo lo demás el componente **sí** nombra lo comprado, y con la variante de tarifa al
     * lado no queda nada por distinguir:
     *
     * ```
     * ticket    Ingreso a Catedral              + Adulto Extranjero
     * guiado    Guiado Machu Picchu             + Privado Circuito 3      ← el circuito va en la variante
     * pool      Pool Paracas y Huacachina       + Cultur (Base 1, 2 pax)
     * vuelo     Vuelo Lima Cusco                + PR Expedition Adulto
     * ```
     *
     * Ahí el segmento es prosa de ITINERARIO, escrita para el cliente: «La Catedral: Encuentro e
     * Inmersión Colonial» no le dice al Arzobispado qué se le compró, y `Ingreso a Catedral` sí.
     *
     * ⚠️ **Se descartó decidirlo contando** a cuántos segmentos sirve cada componente. Da la
     * misma respuesta con los datos de hoy, pero es una **observación, no una declaración**: el
     * día que alguien enganche un segundo segmento a un componente, dos filas idénticas
     * empezarían a pintarse distinto sin que nada visible haya cambiado. El tipo se declara al
     * crear el componente y no se mueve solo.
     *
     * ⚠️ Y se probó antes de decidir, sobre las 47 filas reales de La Biblia: poner el segmento
     * arriba en todas habría encabezado un `Pool Valle Sagrado` y un `Boleto Turístico Parcial`
     * con «Recojo e inicio de excursión (Servicio Grupal)», que ni siquiera habla de esa fila.
     */
    public function mandaElSegmento(): bool
    {
        return $this === self::TRANSPORTE;
    }

}