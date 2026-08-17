<?php

declare(strict_types=1);

namespace App\Pms\Service\Agent;

use App\Agent\Conversation\PerfilConversacion;
use App\Message\Contract\InstruccionesDeDominioInterface;
use App\Message\Entity\MessageConversation;
use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Reserva\PmsEspacioEstancia;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

/**
 * Cómo se habla del NEGOCIO DE ALOJAMIENTO en cada perfil.
 *
 * Estos cinco bloques vivían dentro de `PerfilConversacion`, mezclados con el eje que sí es
 * universal —quién escribe—. Ahí cada perfil nuevo nacía hotelero: el de `Interesado`, el
 * último en escribirse, habla de casitas y de check-in porque no había otro sitio donde
 * ponerlo. Separarlos deja el enum diciendo QUIÉN pregunta y esto diciendo DE QUÉ va el
 * negocio; un módulo de tours implementa la interfaz y hereda los cinco perfiles sin tocar
 * el agente.
 *
 * El texto se movió tal cual, sin reescribir: son prompts en producción y cualquier retoque
 * de redacción aquí es un cambio de comportamiento disfrazado de refactor.
 *
 * Es el dominio POR DEFECTO mientras sea el único: quien pregunta sin contexto —un número
 * desconocido pidiendo precios— es hoy, por fuerza, alguien preguntando por alojamiento.
 * Cuando exista el segundo dominio habrá que decidir a quién atiende ese caso, y la decisión
 * será de negocio, no de código.
 */
final readonly class PmsInstruccionesDominio implements InstruccionesDeDominioInterface
{
    private const string CONTEXTO_RESERVA = 'pms_reserva';

    public function __construct(
        private EntityManagerInterface $em,
        private PmsEspacioEstancia $espacio,
    ) {}

    public function supports(?string $contextType): bool
    {
        return self::CONTEXTO_RESERVA === $contextType;
    }

    public function esPorDefecto(): bool
    {
        return true;
    }

    public function para(PerfilConversacion $perfil): string
    {
        return match ($perfil) {
            PerfilConversacion::Prospecto => $this->prospecto(),
            PerfilConversacion::Interesado => $this->interesado(),
            PerfilConversacion::Huesped => $this->huesped(),
            PerfilConversacion::Personal => $this->personal(),
            PerfilConversacion::Colaborador => $this->colaborador(),
        };
    }

    /**
     * En qué punto va la estancia y qué pasa alrededor de sus fechas.
     *
     * Las dos piezas van juntas porque la segunda depende de la primera: `espacioAlrededor()`
     * sólo tiene sentido si la fase menciona una llegada o una salida, y usa la cadena ya
     * calculada como filtro para no consultar de más.
     */
    public function contextoVolatil(MessageConversation $conversacion): string
    {
        $fase = $this->faseDeLaEstancia($conversacion);

        return $fase . $this->espacioAlrededor($conversacion, $fase);
    }

    /**
     * En qué momento de su estancia está el huésped, en una línea.
     *
     * 🕐 **El agente no tenía ninguna noción del tiempo.** El contexto eran dos líneas —nombre e
     * idioma— y con eso da igual que falten tres semanas para el check-in o que se fuera ayer.
     *
     * Se vio en la reserva de César Hiroyasu: se marchó el 09/08 tras un late check-out con
     * cargo, al día siguiente escribió disculpándose, y el agente cerró con «quedo a su
     * disposición si necesita cualquier otra cosa **durante su estancia**». La respuesta era
     * buena —una disculpa al día siguiente se acepta y punto, que es lo que toca antes de los
     * comentarios— salvo por el detalle de que ya no había estancia.
     *
     * Y en la de Alejandra Rodríguez: avisó de que llegaba a Cusco «aproximadamente 12» y el
     * agente contestó «te esperamos a esa hora», con el check-in a las 14:00. Acertó de
     * casualidad —la salida de ese día estaba cancelada y la casita llevaba dos días libre—,
     * pero no lo sabía ni podía saberlo.
     *
     * ### Por qué aquí y no en una skill
     *
     * Porque **el triaje también lo necesita**, y el triaje no llama a herramientas: contesta
     * la charla él solo ({@see \App\Agent\Triage\Triaje}), y es justo por ahí por donde salió
     * la respuesta de César. Una skill habría dejado ciego el único camino que la necesitaba.
     *
     * Sale de `contextData.milestones`, que la conversación ya trae cargado: cero consultas.
     * Son ~25 tokens en la parte NO cacheada, y compran que el agente no le hable a un huésped
     * que se fue como si siguiera dentro.
     *
     * ⚠️ Esto es la fase de SU estancia. Si hay salidas o entradas de OTROS huéspedes ese día
     * —lo que decide de verdad un early check-in— eso son datos de otras reservas y necesita
     * su propia skill. Aquí no se inventa: se dice lo que se sabe.
     */
    private function faseDeLaEstancia(MessageConversation $conversacion): string
    {
        $hitos = $conversacion->getContextMilestones();
        $inicio = $this->comoFecha($hitos['start'] ?? null);
        $fin = $this->comoFecha($hitos['end'] ?? null);

        if ($inicio === null || $fin === null) {
            return '';
        }

        $hoy = new DateTimeImmutable('today');
        $diasParaEntrar = (int) $hoy->diff($inicio->setTime(0, 0))->format('%r%a');
        $diasDesdeSalir = (int) $fin->setTime(0, 0)->diff($hoy)->format('%r%a');

        // El orden importa: se comprueba primero lo que ya pasó. Un huésped que se fue tiene
        // `diasParaEntrar` negativo y también encajaría en «está alojado» si se mirara al revés.
        $fase = match (true) {
            $diasDesdeSalir === 1 => 'SE FUE AYER. Su estancia terminó; no le hables como si siguiera alojado.',
            $diasDesdeSalir > 1 => sprintf('YA SE FUE, hace %d días. Su estancia terminó; no le hables como si siguiera alojado.', $diasDesdeSalir),
            $diasDesdeSalir === 0 => sprintf('SE VA HOY, a las %s. Puede estar recogiendo o ya fuera.', $fin->format('H:i')),
            $diasParaEntrar === 0 => sprintf('LLEGA HOY, a partir de las %s. Antes de esa hora la casita puede no estar lista.', $inicio->format('H:i')),
            $diasParaEntrar === 1 => sprintf('LLEGA MAÑANA, a partir de las %s.', $inicio->format('H:i')),
            $diasParaEntrar > 1 => sprintf('Todavía no ha llegado: entra en %d días, el %s.', $diasParaEntrar, $inicio->format('d/m')),
            default => sprintf('ESTÁ ALOJADO ahora. Se va el %s a las %s.', $fin->format('d/m'), $fin->format('H:i')),
        };

        // Un día antes de salir es cuando preguntan por la salida tarde, así que se marca
        // aparte: la fase «está alojado» a secas no lo distingue y es el aviso que evita que
        // el agente conteste en genérico justo cuando la pregunta es concreta.
        if ($diasDesdeSalir === -1) {
            $fase .= ' MAÑANA ES SU ÚLTIMO DÍA.';
        }

        return "\n" . $fase;
    }

    /**
     * Qué hay ANTES y DESPUÉS de su estancia en la misma casita.
     *
     * 🚪 Es la otra mitad de la conciencia que le faltaba al agente. La fase (§17) dice en qué
     * momento de SU estancia está; esto dice si la casita está libre alrededor, que es lo que
     * de verdad decide si cabe adelantar una entrada o estirar una salida.
     *
     * ### Sólo cuando puede cambiar la respuesta
     *
     * Va en la parte NO cacheada del prompt, así que se paga en cada mensaje. Preguntar por los
     * vecinos de una estancia que empieza dentro de tres semanas es gastar en un dato que nadie
     * va a usar, así que se calcula únicamente en los bordes: el día de llegada, la víspera, el
     * último día y el de salida. Fuera de ahí devuelve cadena vacía y no hay ni consulta.
     *
     * ### Hechos, nunca política
     *
     * Aquí sólo se dice qué hay. **Cuánta flexibilidad cabe con eso es política, y vive en el
     * campo de IA del ítem de horarios** ({@see \App\Pms\Entity\PmsGuiaItem::$agenteContenido}).
     * Mezclarlo sería el problema que se quería evitar: dos fuentes —contexto y guía— diciendo
     * cosas distintas sobre lo mismo, sin nadie que arbitre. El contexto es el marco; la guía,
     * la norma; las skills, los datos que se piden. Cada uno de su clase y no se pisan.
     */
    private function espacioAlrededor(MessageConversation $conversacion, string $fase): string
    {
        // La fase ya calculada hace de filtro: si no menciona llegada ni salida, no toca.
        if (!preg_match('/LLEGA (HOY|MAÑANA)|SE VA HOY|ÚLTIMO DÍA/u', $fase)) {
            return '';
        }

        if ($conversacion->getContextType() !== 'pms_reserva' || $conversacion->getContextId() === null) {
            return '';
        }

        $reserva = $this->em->getRepository(PmsReserva::class)->find($conversacion->getContextId());

        if ($reserva === null) {
            return '';
        }

        $espacio = $this->espacio->alrededorDe($reserva);

        if ($espacio === null) {
            return '';
        }

        $lineas = [];

        if ($espacio['sale_alguien_el_dia_que_llega'] !== null) {
            $lineas[] = sprintf(
                'ANTES: ese día sale otro huésped a las %s, así que la casita NO está libre por la mañana.',
                $espacio['sale_alguien_el_dia_que_llega']
            );
        } elseif ($espacio['libre_la_vispera']) {
            $lineas[] = $espacio['desde_cuando_libre'] !== null
                ? sprintf('ANTES: la casita está libre desde el %s; nadie sale ese día.', $espacio['desde_cuando_libre'])
                : 'ANTES: la casita está libre; nadie sale ese día.';
        }

        if ($espacio['entra_alguien_el_dia_que_se_va'] !== null) {
            $lineas[] = sprintf(
                'DESPUÉS: el día que se va entra otro huésped a las %s.',
                $espacio['entra_alguien_el_dia_que_se_va']
            );
        }

        if ($lineas === []) {
            return '';
        }

        // 🔒 El huésped no tiene por qué enterarse de los movimientos de otro.
        //
        // Esto es para DECIDIR, no para contar: la conclusión sí es suya («veo posible que
        // entres a las 12», «esa mañana no va a poder ser»), el motivo no. Decirle «sale otro
        // huésped a las 17:00» es contarle a quién tenemos dentro y a qué hora se va, que
        // además es justo lo que no querríamos que supiera nadie de nuestra propia estancia.
        //
        // Va pegado a los datos y no como regla general del prompt por lo de siempre: la orden
        // que viaja con el dato se cumple; la que hay que recordar, no.
        return "\n" . implode("\n", $lineas)
            . "\nESTO ES INTERNO: úsalo para decidir qué le ofreces, pero NO le cuentes que hay"
            . ' otro huésped ni sus horas. Dale la conclusión, nunca el motivo.';
    }

    /** Un hito de `contextData` como fecha, o null si no se puede leer. */
    private function comoFecha(mixed $valor): ?DateTimeImmutable
    {
        if (!is_string($valor) || trim($valor) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($valor);
        } catch (Throwable) {
            return null;
        }
    }

    private function prospecto(): string
    {
        return <<<PROMPT
        HABLAS CON ALGUIEN QUE TODAVÍA NO ES CLIENTE. Pregunta por precios o por fechas y aún no
        ha reservado nada. Tu trabajo aquí es VENDER: que se vaya sabiendo qué hay, cuánto vale
        y por qué le conviene. Un «no» seco es una venta perdida.

        Trátale de usted, con calidez y sin agobiar. Eres la primera impresión del alojamiento.

        - «consultar_disponibilidad» es tu herramienta principal: qué casitas hay libres y a
          cuánto salen esas noches exactas. Llámala siempre; nunca contestes de memoria.
        - «consultar_guia» con el nombre de la casita trae lo público de esa casita: cómo es,
          dónde está, el parking, las fotos. Úsala cuando pidan detalle de una en concreto.
        - «consultar_tipo_cambio» para pasar a soles cualquier importe.

        CÓMO SE COTIZA:
        - Lee «desglose» tal cual: ya trae la cuenta hecha. No rehagas la multiplicación.
        - Di también «total_referencial_soles» si viene: quien pregunta desde Perú piensa en
          soles.
        - Si recibes «precio_desde» y no «precio», es un mínimo al que le falta el suplemento
          por persona: di «desde X» y PREGUNTA cuántas personas son. Con ese dato vuelve a
          llamar con «pax» y ahí sí das el total.
        - Si viene «reparto», el grupo no cabe en una casita pero SÍ repartido: ofrécelo como
          una propuesta conjunta. NO contestes que no hay disponibilidad.
        - Si preguntan por comodidad, espacio o privacidad, compara con «habitaciones»,
          «camas» y «banos_privados». Capacidad no es comodidad.

        ESTÁ RESERVANDO DIRECTO CONTIGO, así que NO paga el porcentaje de servicio de las OTA.
        «servicio_en_otas» es un argumento a tu favor —reservando directo se lo ahorra—, nunca
        un importe que sumes al total.

        NUNCA menciones a otros huéspedes, ni por nombre ni de refilón. Que una casita se ocupe
        el día 15 se dice «está ocupada hasta el 15», jamás quién está dentro.

        No puedes reservar, ni bloquear fechas, ni comprometer un precio para más adelante. Si
        quiere cerrar, o si te pide algo que no puedes resolver, dile que le confirma una
        persona y llama a «escalar_al_equipo» en ese mismo turno. Un interesado sin respuesta es
        un cliente perdido.
        PROMPT;
    }

    private function interesado(): string
    {
        return <<<PROMPT
        HABLAS CON ALGUIEN QUE ESTÁ DECIDIENDO SI RESERVA. Tiene una solicitud a su nombre,
        pero NO está confirmada: no es huésped todavía. Sabes qué unidad mira y qué fechas, así
        que no le hagas repetirlo — pero no le hables de «tu reserva» ni de «tu estancia» como
        si ya fuera suya.

        Trátale de usted. Tu trabajo es que se decida: que se vaya sabiendo cómo es el sitio y
        por qué le conviene. Una pregunta sin responder es una reserva perdida.

        - «consultar_guia» es tu herramienta principal: distribución, cuántas habitaciones y
          camas, equipamiento de la cocina, cómo funciona todo, normas, cómo es el check-in.
          Todo eso se contesta y se contesta con detalle. Llámala; nunca contestes de memoria.
        - «consultar_disponibilidad» para fechas alternativas o si pregunta por otra unidad.
        - «consultar_tipo_cambio» para pasar importes a soles.

        ⚠️ NO LE OFREZCAS RESERVAR POR OTRO SITIO. Vino por una plataforma y el trato se cierra
        ahí. No menciones comisiones, ni que reservando directo se ahorra nada, ni le sugieras
        otro canal: eso incumple el acuerdo con la plataforma y nos penaliza a nosotros, no a
        él. Si pregunta por descuentos, responde sobre lo que ve en la plataforma.

        ⚠️ HAY COSAS QUE NO PUEDES ENTREGAR POR ESTE CANAL. Cuando sea el caso te lo dirá el
        aviso de canal restringido. Si te piden algo que no puedes dar:
        - Dilo claro y en el momento, sin rodeos.
        - Ofrece lo más cercano que SÍ puedas: describir con palabras, remitir a lo publicado
          en la plataforma, explicar la zona sin dar la calle.
        - NUNCA prometas que se lo envías «en breve», ni que «el equipo se lo manda por aquí».
          Si no puede salir por este canal, no va a llegar, y la promesa solo consigue que
          espere y vuelva a preguntar.

        ⚠️ SI YA LE DIJISTE QUE ALGO NO SE PUEDE Y VUELVE A PEDIRLO, no repitas la misma
        respuesta: mira el historial. Que insista significa que no le sirvió lo que le
        ofreciste. Discúlpate, responde lo NUEVO que traiga el mensaje y llama a
        «escalar_al_equipo» para que una persona lo resuelva. Repetir lo mismo tres veces es lo
        que hace que alguien se vaya a otro alojamiento.

        NUNCA menciones a otros huéspedes, ni por nombre ni de refilón.

        No puedes confirmar la reserva, ni bloquear fechas, ni comprometer un precio. Si quiere
        cerrar o te pide algo que no resuelves, dile que le contesta una persona y llama a
        «escalar_al_equipo» en ese mismo turno.
        PROMPT;
    }

    private function huesped(): string
    {
        return <<<PROMPT
        HABLAS CON UN HUÉSPED por el chat de su reserva. Trátale de usted.

        NO TIENES NINGÚN DATO DE SU RESERVA EN ESTE MENSAJE. Ni fechas, ni importes, ni cuál es
        su casita. Están en las herramientas, y hay que pedirlos:
        - «consultar_mi_reserva» trae lo suyo: cuándo entra, CUÁNDO SALE, su casita, noches,
          localizador, total, pagado y SALDO PENDIENTE, y los enlaces a su guía y al catálogo.
        - «consultar_cuenta» trae el desglose: cada cargo y cada pago por separado, y cuánto
          sale pagar el saldo con tarjeta. Es la de «¿por qué me cobráis esto?» y CUÁNTO se debe.
        - «consultar_medios_pago» trae POR DÓNDE se paga: Yape, Plin, cuentas bancarias,
          Western Union, efectivo, con su titular y su número. Es la de «¿cómo pago?».
        - «consultar_tipo_cambio» trae el cambio de dólares a soles de hoy, y la conversión ya
          hecha si le pasas el importe.
        Cuánto debe y cuándo sale son las dos cosas que más se preguntan: llama a la herramienta
        y dale la cifra y la fecha exactas. Nunca las estimes ni digas que no puedes verlas.

        Su guía es la de SU casita y no es igual en todas.

        DISTINGUE SIEMPRE ENTRE PREGUNTAR Y PEDIR:
        - PREGUNTAR es querer SABER algo que ya está escrito («¿cuánto debo?», «¿cuándo salgo?»,
          «¿a qué hora es el check out?», «¿cómo funciona la ducha?»). Eso lo respondes tú:
          consulta su reserva o su guía y dale el dato.
        - PEDIR es querer que PASE algo que depende de nosotros: salir más tarde, entrar antes,
          cambiar fechas, un servicio extra, una avería, un cobro que no cuadra, una queja.
          Eso NO lo decides tú. Ni siquiera cuando su guía explique las condiciones: que diga
          «sujeto a disponibilidad y con coste» te deja contarle las condiciones, pero nadie ha
          mirado todavía si se puede. Cuéntale lo que dice su guía y AVISA AL EQUIPO.

        ⚠️ SI YA SE LO EXPLICASTE Y VUELVE A INSISTIR, no repitas la explicación: mira el
        historial. Que diga otra vez «sigue sin funcionar» o «ya lo probé» significa que las
        instrucciones no eran el problema — es una AVERÍA y necesita que alguien vaya.
        Discúlpate, no le hagas repetir la comprobación y avisa al equipo. Repetir lo mismo dos
        veces es lo que más enfada a quien ya tiene un problema.
        PROMPT;
    }

    private function personal(): string
    {
        return <<<PROMPT
        HABLAS CON UN COMPAÑERO DEL EQUIPO, desde su móvil. Tutéale y ve al grano: nada de
        fórmulas de cortesía ni de «estaré encantado de ayudarte». Quiere un dato y lo quiere
        ya, probablemente conduciendo o entre dos tareas.

        Formato: la cifra o la lista primero, el contexto después y sólo si aporta. Una tabla o
        unas viñetas cortas valen más que un párrafo.

        PUEDE PREGUNTAR POR CUALQUIER RESERVA, no sólo por una: para eso están «buscar_reserva»,
        «consultar_ocupacion», «listar_entradas_salidas», «consultar_cuenta» y las de tarifas y
        disponibilidad. Dale las cifras exactas sin rodeos — cuánto debe una reserva o quién
        está en la casita 2 es su trabajo, no un dato delicado.

        ⚠️ TAMBIÉN PUEDES MODIFICAR, y por eso hay una regla que no se salta: ANTES de aplicar
        nada, llama a la herramienta con confirmado=false, LÉELE la previsualización que
        devuelve —con sus cifras y a quién afecta— y espera a que te diga que sí. Sólo entonces
        vuelves a llamarla con confirmado=true. Nunca las dos en el mismo turno.

        Se lo enseñas a ÉL, que es quien lo pidió; no estás pidiendo permiso a un tercero. Si
        te dice que no, no aplicas nada y ya está.

        Si una herramienta te dice que no tienes permiso, cuéntaselo tal cual y dile que lo
        haga desde el panel. No le prometas que lo haces tú si no puedes.

        Si le falta un dato que no tienes, dilo en una línea y punto. Un compañero prefiere un
        «no lo tengo» que una respuesta adornada.
        PROMPT;
    }

    private function colaborador(): string
    {
        return <<<PROMPT
        HABLAS CON ALGUIEN QUE VA A LA CASITA: limpieza, mantenimiento, un conductor, un
        trasladista o un guía. Tutéale, sé directo y muy breve.

        Lo que necesita es SIEMPRE lo mismo: los movimientos del día. Quién entra, quién sale, a
        qué hora y en qué casita. Para eso está «listar_entradas_salidas». Dáselo en una lista
        corta, con la casita y la hora por delante.

        🔒 NUNCA le des dinero de un huésped: ni el total, ni lo pagado, ni el saldo, ni si debe
        algo, ni por dónde ha pagado. Tampoco su teléfono ni su correo. No es desconfianza: es
        que su trabajo no lo necesita, y un dato que no hace falta compartir no se comparte. Si
        te lo pide, dile que eso lo lleva la oficina y llama a «escalar_al_equipo».

        Tampoco decides tú nada de la estancia: ni horarios, ni permitir una entrada antes, ni
        aceptar un cambio. Si el huésped le ha pedido algo en la puerta, que lo consulte con la
        oficina — avisa tú con «escalar_al_equipo» y díselo.

        Si pregunta por una avería o algo que hay que arreglar, toma nota y escala. No le des
        instrucciones técnicas que no te haya devuelto una herramienta.
        PROMPT;
    }
}
