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

    /**
     * El transporte que forma parte de una EXCURSIÓN privada, no un traslado con ruta.
     *
     * «Transporte Valle Sagrado», «Transporte Super Valle», «Transporte Maras», «Transporte
     * Combinada»: son `transporte` de verdad —un vehículo, un chofer— pero **no nombran una
     * ruta**, nombran la excursión. Sus segmentos son anclas: «Recojo en el Hotel (Servicio
     * Privado)», idéntico en todas.
     *
     * ⚠️ Nació el 29/08/2026 de un fallo que casi se cuela. Estaban como `TRANSPORTE`, y
     * `mandaElSegmento()` es true para todo `TRANSPORTE`, así que el día que se vendiera una de
     * estas excursiones el proveedor habría leído **«Recojo en el Hotel (Servicio Privado)»** en
     * grande y el encargo debajo. La simulación sobre las 47 filas de La Biblia no lo detectó
     * porque ninguna de las cuatro está viva hoy: **la prueba salió limpia sobre un hueco**.
     *
     * Se resuelve con un tipo y no con una excepción porque la diferencia es real y declarable:
     * se compra la excursión entera, no el trayecto. Y así {@see self::ocultaElSegmento()} las
     * cubre por el mismo motivo que a `pool` y `privada`.
     */
    case TRANSPORTE_EXCURSION = 'transporte_excursion';
    case ALOJAMIENTO = 'alojamiento';

    case ALIMENTACION_HORARIO_FIJO = 'alimentacion_fijo';
    case ALIMENTACION_HORARIO_VAR = 'alimentacion_variable';
    case EXCURSION_POOL = 'pool';
    case EXCURSION_PRIVADA = 'privada';
    case PERSONAL_EXTRA = 'personal_extra';
    case EXTRAS = 'extras';

    /**
     * Una actividad con FRANJA: la discoteca de 19:00 a 22:00, las olimpiadas de 11:00 a 13:30.
     *
     * ⚠️ **Existe porque `EXTRAS` declara `sinHorario() = true` y por tanto tira la hora.** No es
     * un descuido de aquel tipo: un extra es «algo más que se incluye» —una botella de bienvenida,
     * el late check-out— y ponerle hora sería inventarle una cita. Pero las actividades programadas
     * de un resort sí la tienen, y con `EXTRAS` la hora se guardaba en el pivote y **no llegaba a
     * la cotización**: el componente nacía con `sinHorario = true` y el bloque caía al final del
     * día. Sin error, como todo lo caro de este módulo.
     *
     * Sigue la pauta que el enum ya tenía para el mismo problema —`TICKET_HORARIO_FIJO` frente a
     * `TICKET_HORARIO_VAR`, `ALIMENTACION_HORARIO_FIJO` frente a `..._VAR`—: cuando algo existe con
     * y sin horario, son dos casos y no un booleano suelto.
     *
     * ⚠️ **El `value` se congela en los snapshots** (`CotizacionCotcomponente::$tipo` y
     * `OperacionServicio::$modoComponente` son strings). Renombrarlo después obliga a migrar datos,
     * así que se acierta ahora o no se acierta.
     */
    case ACTIVIDAD_HORARIO_FIJO = 'actividad_fijo';
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
            self::TRANSPORTE, self::TRANSPORTE_EXCURSION, self::TREN, self::VUELO,
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
     * ¿El servicio se comparte con otros pasajeros, o va sólo para este grupo?
     *
     * Decide **dónde se les deja al terminar**, y la regla es del negocio, no del código: lo
     * privado devuelve al hotel de cada uno; lo compartido deja a todos en el centro de la
     * ciudad, porque un bus con doce pasajeros de nueve hoteles no puede hacer nueve paradas.
     *
     * ⚠️ **Sólo `EXCURSION_POOL` comparte. Todo lo demás devuelve al hotel**, incluidos los dos
     * tipos de transporte, y no es un descuido.
     *
     * El aviso que había aquí decía que las versiones privadas de una excursión se montaban como
     * `TRANSPORTE` —«Transporte Vinicunca», «Transporte Combinada»— porque de `EXCURSION_PRIVADA`
     * sólo hay dos en el maestro. Seguía siendo cierto y era el síntoma de otra cosa: el
     * 29/08/2026 esos cuatro pasaron a {@see self::TRANSPORTE_EXCURSION}, que nació justo de ahí.
     * La clasificación no cambia —privados los dos— pero ya no hay que deducirlo del uso.
     */
    public function esCompartido(): bool
    {
        return $this === self::EXCURSION_POOL;
    }

    /**
     * ¿Cambia de ciudad, partiendo el día en dos?
     *
     * Un vuelo o un tren dividen la jornada: lo que ocurre antes termina en su punto de salida
     * —el aeropuerto, la estación— y lo posterior empieza en el de llegada. Sin esto, un traslado
     * de la mañana se leería como si terminara en el hotel de destino, que está en otra ciudad.
     *
     * ⚠️ Este docblock estuvo **huérfano**: quedó flotando encima del de `esCompartido()` y
     * `esSalto()` sin ninguno. Es el patrón contra el que avisa CLAUDE.md —insertar un miembro
     * «justo antes» de otro— y aquí salió barato porque no había atributos de por medio; con un
     * `#[Groups]` en el bloque, el campo habría desaparecido del esquema sin un solo error.
     */
    public function esSalto(): bool
    {
        return $this === self::VUELO || $this === self::TREN;
    }

    /**
     * ¿El componente nombra una RUTA, o la cosa comprada?
     *
     * Es la propiedad de la que cuelga {@see self::mandaElSegmento()}, y merece nombre propio
     * porque es lo que de verdad se está preguntando.
     *
     * ```
     * ruta      transporte · tren · vuelo      «Transporte Cusco - Ollanta», «Vuelo Lima Cusco»
     * cosa      ticket · alojamiento · pool…   «Ingreso a Catedral», «Pool Valle Sagrado»
     * ```
     *
     * ⚠️ **No es lo mismo que {@see self::puntosDeServicio()}.** Una excursión `pool` también
     * recoge y deja —tiene `INICIO_Y_FIN`— pero su componente se llama «Pool Valle Sagrado», que
     * es la cosa comprada. Tener puntos y nombrarse por ellos son preguntas distintas.
     *
     * **La consecuencia práctica:** un componente que nombra una ruta se puede fusionar por
     * sentido —una sola ficha para ida y vuelta, que es donde deja de haber dos precios que
     * mantener a mano y divergen sin avisar—. Uno que nombra una cosa, no: fusionarlo no
     * ahorraría nada y perdería el nombre.
     */
    public function nombraUnaRuta(): bool
    {
        return $this === self::TRANSPORTE || $this->esSalto();
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
            self::TRANSPORTE_EXCURSION,
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

            // El caso que separa la actividad programada del extra suelto: aquí la hora es el dato.
            self::ACTIVIDAD_HORARIO_FIJO => false,
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
            self::GUIADO, self::TRANSPORTE, self::TRANSPORTE_EXCURSION, self::EXCURSION_POOL, self::EXCURSION_PRIVADA, self::TREN => 1,
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
            self::VUELO, self::TREN, self::TRANSPORTE, self::TRANSPORTE_EXCURSION => 10,
            // El contacto es de la llegada: quien recibe, recibe al principio.
            self::CONTACTO => 20,
            // El cuerpo del día.
            self::EXCURSION_POOL, self::EXCURSION_PRIVADA, self::GUIADO => 30,
            self::TICKET_HORARIO_FIJO, self::TICKET_HORARIO_VAR => 40,
            self::ALIMENTACION_HORARIO_FIJO, self::ALIMENTACION_HORARIO_VAR => 50,
            // Lo accesorio, antes de cerrar.
            self::PERSONAL_EXTRA, self::EXTRAS, self::ACTIVIDAD_HORARIO_FIJO => 60,
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
     * **Manda el segmento exactamente donde el componente {@see self::nombraUnaRuta()}**, y las
     * dos cosas son la misma decisión tomada dos veces: en cuanto una ruta se fusiona por sentido
     * —una ficha para ida y vuelta— su nombre deja de decir hacia dónde se va hoy, y el único que
     * lo sabe es el segmento. Fusionar sin mover el display deja al proveedor una flecha de dos
     * puntas en grande y el destino escondido debajo.
     *
     * En lo demás el componente nombra lo comprado y la variante lo termina de distinguir:
     *
     * ```
     * ticket    Ingreso a Catedral          + Adulto Extranjero
     * guiado    Guiado Machu Picchu         + Privado Circuito 3     ← el circuito va en la variante
     * pool      Pool Paracas y Huacachina   + Cultur (Base 1, 2 pax)
     * ```
     *
     * Ahí el segmento es prosa de ITINERARIO escrita para el cliente: «La Catedral: Encuentro e
     * Inmersión Colonial» no le dice al Arzobispado qué se le compró.
     *
     * ⚠️ **Se descartó decidirlo contando** a cuántos segmentos sirve cada componente. Da la misma
     * respuesta con los datos de hoy, pero es una **observación, no una declaración**: el día que
     * alguien enganche un segundo segmento a un componente, dos filas idénticas empezarían a
     * pintarse distinto sin que nada visible haya cambiado.
     *
     * ⚠️ **Y se descartó el precio como criterio.** Parecía que un tren costaba 105 de subida y 60
     * de bajada, hasta que se supo que son tarifas REFERENCIALES: la diferencia no decía que
     * fueran productos distintos, decía que alguien actualizó un sentido y no el otro. Que es
     * justamente el argumento a favor de fusionarlos.
     *
     * ⚠️ Probado sobre las 47 filas reales antes de decidir: poner el segmento arriba en TODAS
     * habría encabezado un `Pool Valle Sagrado` con «Recojo e inicio de excursión (Servicio
     * Grupal)», que ni siquiera habla de esa fila.
     */
    public function mandaElSegmento(): bool
    {
        return $this->nombraUnaRuta();
    }

    /**
     * ¿El segmento sobra del todo en la ficha y en la orden?
     *
     * **Sí en las excursiones.** Su componente es el ANCLA de una plantilla de varios segmentos:
     * se cuelga del primero por necesidad técnica —algo tiene que sostener la tarifa— pero cubre
     * el día entero. Enseñar ese primer segmento al lado del componente **encoge el encargo**:
     *
     * ```
     * Pool Paracas y Huacachina
     *   Traslado Costero de Lima a Paracas    ← parece que sólo se contrató el traslado
     *   Full Day Paracas y Huacachina
     * ```
     *
     * El proveedor lleva el día completo —traslado, almuerzo, Huacachina, retorno— y esa segunda
     * línea sugiere que no. No es que estorbe: **miente por omisión**, que es peor que sobrar.
     *
     * ⚠️ Es una pregunta distinta de {@see self::mandaElSegmento()} y por eso va aparte. Ahí se
     * decide **cuál de los dos va en grande**; aquí, si el segmento pinta algo. Un `pool` responde
     * «no» a las dos, pero por motivos que no se parecen: no manda porque el componente ya nombra
     * lo comprado, y no se muestra porque el segmento **es sólo un capítulo** de lo comprado.
     *
     * ⚠️ Y NO afecta a la guía del huésped. Ahí el segmento es el relato del día y va en grande —
     * `pax` recibe `tituloSnapshot`, nunca los nombres internos, que no llevan el grupo
     * `pax_cotizacion:read`. Esta regla es de despacho.
     */
    public function ocultaElSegmento(): bool
    {
        return $this === self::EXCURSION_POOL
            || $this === self::EXCURSION_PRIVADA
            || $this === self::TRANSPORTE_EXCURSION;
    }

}