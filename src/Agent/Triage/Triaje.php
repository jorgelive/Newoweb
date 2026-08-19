<?php

declare(strict_types=1);

namespace App\Agent\Triage;

use App\Agent\Access\ActorInterface;
use App\Agent\Conversation\ConversationRequest;
use App\Agent\Conversation\PotenciaRequerida;
use App\Agent\Conversation\SelectorDePotencia;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillRegistry;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * El primer paso del turno: qué clase de mensaje es esto y quién debería atenderlo.
 *
 * ### Por qué existe
 *
 * Un turno del agente costaba lo mismo dijera lo que dijera el huésped. «Hola» y «¿por qué me
 * cobráis 40 soles de más?» pagaban idéntico: el catálogo entero de skills del huésped en el
 * prompt, el bucle de herramientas girando, y el modelo grande decidiendo. En un chat donde la
 * mitad de los mensajes son cortesía, eso es pagar el precio del caso difícil por el caso
 * trivial, y es lo que hace que un asistente con IA no salga a cuenta.
 *
 * El triaje es una llamada **barata y sin herramientas** que dice si esto es charla, una
 * petición o una emergencia; qué skill parece responder; y —si es la guía— por qué tema. Y una
 * cosa más: **si es charla, la contesta él mismo, en esta misma llamada.** Clasificar un «hola»
 * y responderlo son el mismo trabajo de leerlo; separarlos era pagar dos llamadas (clasificar
 * en tramo medio + charlar en tramo bajo) por lo que cabe en una. De paso resuelve el caso que
 * preocupa de verdad: si en mitad de la charla aparece una petición, quien la detecta es el
 * mismo clasificador que estaba charlando — no hay un «modo charla» del que salir.
 *
 * ```
 *   mensaje del huésped
 *          │
 *          ▼
 *   ┌─────────────────────────────────────────────┐
 *   │ TRIAJE  ·  sin herramientas  ·  JSON forzado│
 *   │ prompt: reglas + lista de skills (1 línea)  │  ← estable ⇒ cacheable
 *   │ contexto volátil: idioma y nombre, 2 líneas │  ← fuera del caché
 *   └─────────────────────────────────────────────┘
 *          │
 *          ├─ conversacion  → `respuesta` viene en el MISMO JSON  ·  0 llamadas más
 *          ├─ emergencia    → camino largo + orden explícita de avisar al equipo
 *          ├─ peticion      → camino largo, tramo de la skill elegida
 *          └─ indeterminado → camino largo, como si el triaje no existiera
 * ```
 *
 * La seguridad de esa respuesta de charla no está en el prompt: está en que **en esta llamada
 * no hay herramientas**. El modelo no puede consultar nada, así que tampoco puede citar mal
 * nada; lo peor que puede hacer es contestar de memoria, y para eso el prompt le prohíbe dar
 * cifras y le da la salida buena: ofrecerse a mirarlo.
 *
 * ### 🔑 Lo que el triaje NO hace, y es lo más importante de este archivo
 *
 * **No recorta el catálogo.** Elige skill, sí, pero como sugerencia: el paso siguiente sigue
 * viendo todas sus herramientas y puede llamar a otra. Recortar sería lo obvio —ahí está el
 * grueso de los tokens— y es justo lo que no se hace, porque un filtro que QUITA opciones
 * convierte sus errores en invisibles: la skill que se descartó no aparece en ningún log, y el
 * fallo se descubre en la cara del huésped semanas después. Un filtro previo puede AÑADIR,
 * nunca QUITAR.
 *
 * **No tira ningún mensaje.** Ninguna de las cuatro salidas termina en silencio.
 *
 * ### Y por qué el prompt del triaje sigue siendo cacheable
 *
 * Las reglas y la lista de skills no llevan NADA del huésped: son idénticas byte a byte en
 * todas las conversaciones, y son el bloque con marca de caché. Lo del huésped —idioma y
 * nombre, que hacen falta desde que el triaje contesta la charla— viaja en `$contexto`, que
 * los motores ponen DESPUÉS del corte: dos líneas que se pagan enteras, contra el prefijo
 * grande que ya pagó otra conversación.
 *
 * Ver docs/Mensajeria.md §13.
 */
final readonly class Triaje
{
    /** Turnos previos que ve el clasificador. Le basta con saber de qué se venía hablando. */
    private const int HISTORIAL_MAX = 6;

    /**
     * Cuatro campos cortos más la respuesta de charla, que son dos frases.
     *
     * Estuvo en 500 con el razonamiento de que «más presupuesto invita a razonar en voz alta».
     * El razonamiento no se sostiene aquí y el número salía caro:
     *
     * - **La salida la fija `responseSchema`**, no el presupuesto. Con esquema el modelo no
     *   puede irse por las ramas: los campos son los que son. El freno a la verborrea es el
     *   esquema, no el techo de tokens.
     * - **`maxOutputTokens` es un tope, no una reserva.** Subirlo no cuesta un céntimo mientras
     *   no se use: se factura lo que se emite. Bajarlo no ahorra; sólo corta.
     * - Y cortar aquí **no da una respuesta corta, da una respuesta rota**: el JSON llega a
     *   medias, `interpretar()` lo tira a `indeterminado` y el turno se va por el camino largo
     *   pagando una llamada de más. Se ahorraban tokens en el paso barato para gastarlos en el
     *   caro.
     *
     * Con 500 el truncado era intermitente —los mensajes largos lo disparaban—, que es la peor
     * forma de fallar: no se reproduce cuando lo buscas. 900 deja holgura para el `resumen` y
     * la `respuesta` de charla sin cambiar lo que se paga en el caso normal.
     *
     * Si vuelve a pasar, `GoogleAIEngine::turnoDirecto()` ya lo avisa con el desglose de en qué
     * se fue el presupuesto. Ver docs/Mensajeria.md §13.6 bis.
     */
    private const int MAX_TOKENS = 900;

    /** Tope del resumen de cada skill en la lista. Es un índice, no la descripción entera. */
    private const int RESUMEN_MAX = 180;

    public function __construct(
        private SelectorDePotencia $potencias,
        private SkillRegistry $skills,
        private IndiceDeTemasRegistry $indices,
        private LoggerInterface $logger,
        private bool $habilitado,
        private string $potencia,
    ) {}

    public function estaActivo(): bool
    {
        return $this->habilitado;
    }

    /**
     * @param list<array{rol: string, texto: string}> $historial
     * @param string $contexto Lo volátil de la conversación —idioma y nombre—, para que la
     *        respuesta de charla salga en el idioma del huésped. Va fuera del bloque cacheado.
     */
    public function clasificar(
        ActorInterface $actor,
        string $mensaje,
        array $historial = [],
        string $contexto = ''
    ): DecisionDeTriaje {
        if (!$this->habilitado) {
            return DecisionDeTriaje::indeterminado('triaje desactivado');
        }

        $mensaje = trim($mensaje);
        if ($mensaje === '') {
            return DecisionDeTriaje::indeterminado('mensaje vacío');
        }

        // El tramo del triaje se configura aparte de los demás porque es la decisión que más
        // se nota cuando se equivoca: confundir una avería con una pregunta manda al huésped a
        // releer la guía que ya le falló. Ver AGENT_IA_TRIAJE_POTENCIA.
        $elegido = $this->potencias->elegir(PotenciaRequerida::desde($this->potencia));
        if ($elegido === null) {
            return DecisionDeTriaje::indeterminado('ningún motor disponible');
        }

        // 🔥 `incluirEscritura` va por ACTOR, no fijo en `false`. Con `false` a secas, los
        // candidatos sólo podían ser de lectura y la guarda de escritura de
        // `AclaracionDeEmpate::obliga()` no podía darse NUNCA: el mecanismo de desambiguación
        // entero era código muerto, y su promesa —preguntar antes de ejecutar sobre lo que no
        // era— no la cumplía nadie. Es el mismo criterio que usan el camino largo y
        // `AiConversationProcessor::candidatasResueltas()`: contar sobre una lista distinta de
        // la que el modelo va a ver es contar otra cosa.
        //
        // Al huésped esto NO le cambia nada, y es lo que sostiene que no se le pregunte: su
        // catálogo no tiene una sola skill de escritura. La línea la sostiene el código, no una
        // instrucción del prompt. Ver docs/Mensajeria.md §22.24.
        $skills = $this->skills->paraActor($actor, incluirEscritura: CatalogoDelTriaje::veEscrituras($actor));
        if ($skills === []) {
            return DecisionDeTriaje::indeterminado('el actor no tiene ninguna skill');
        }

        // El índice de la guía sólo tiene sentido si el actor puede consultarla. Se incluye
        // SIEMPRE que pueda —aunque su reserva no resuelva casita— para que el prefijo cacheado
        // sea uno solo: un prompt «a veces con índice» son dos cachés compitiendo.
        $temasPermitidos = [];
        $indice = '';
        $nombres = array_map(static fn (SkillInterface $s): string => $s->nombre(), $skills);

        // Qué temas hay y cuáles le tocan lo sabe el DOMINIO. Aquí antes se cruzaba a mano el
        // mapa `porUnidad`, se hablaba de «su casita» y se comprobaba el nombre literal de una
        // skill del PMS: cuatro conceptos de alojamiento dentro del clasificador de mensajes,
        // que no tiene por qué saber de qué negocio se habla.
        $indiceDeTemas = $this->indices->para($actor->contextoTipo(), $nombres);

        if ($indiceDeTemas !== null) {
            $indice = $indiceDeTemas->bloqueParaElPrompt();
            $temasPermitidos = $indiceDeTemas->temasPermitidos($actor);

            // Lo que cambia por conversación va en lo volátil, nunca en el bloque cacheado.
            $linea = $indiceDeTemas->lineaVolatil($actor);

            if ($linea !== '') {
                $contexto = trim($contexto . "\n" . $linea);
            }
        }

        try {
            $crudo = $elegido->motor->turnoDirecto(
                new ConversationRequest(
                    actor: $actor,
                    systemPrompt: $this->reglas($skills, $indice),
                    contexto: $contexto,
                    mensaje: $mensaje,
                    historial: array_slice($historial, -self::HISTORIAL_MAX),
                    permitirEscritura: false,
                    maxTokens: self::MAX_TOKENS,
                    modelo: $elegido->modelo,
                ),
                $this->esquema($skills)
            );
        } catch (Throwable $e) {
            // El motor ya promete no lanzar, pero el triaje corre en el camino de un mensaje a
            // un cliente real: si algún día una implementación rompe esa promesa, el huésped no
            // se puede quedar sin respuesta por un paso que es una optimización.
            return DecisionDeTriaje::indeterminado('el motor falló: ' . $e->getMessage());
        }

        $decision = $this->interpretar($crudo, $skills, $temasPermitidos);

        $this->logger->info(sprintf(
            'Agent: triaje con %s · %s',
            $elegido->etiqueta(),
            $decision->etiqueta()
        ));

        return $decision;
    }

    /**
     * @param list<SkillInterface> $skills
     * @param list<string> $temasPermitidos Uuids de los temas de guía de LA CASITA del huésped.
     */
    private function interpretar(?string $crudo, array $skills, array $temasPermitidos = []): DecisionDeTriaje
    {
        if ($crudo === null) {
            return DecisionDeTriaje::indeterminado('el motor no devolvió nada');
        }

        // El esquema forzado debería bastar, pero no todos los proveedores lo respetan igual y
        // alguno envuelve el JSON en un bloque de código. Se limpia antes de decodificar: es
        // más barato que perder el triaje por tres backticks.
        $limpio = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($crudo)) ?? $crudo);
        $datos = json_decode($limpio, true);

        if (!is_array($datos)) {
            return DecisionDeTriaje::indeterminado('respuesta no era JSON: ' . mb_substr($limpio, 0, 120));
        }

        $tipo = TipoDeMensaje::tryFrom((string) ($datos['tipo'] ?? ''));
        if ($tipo === null || $tipo === TipoDeMensaje::Indeterminado) {
            return DecisionDeTriaje::indeterminado('tipo no reconocido: ' . (string) ($datos['tipo'] ?? '—'));
        }

        // 🔒 El nombre de la skill se comprueba contra las que ESTE actor puede usar, no contra
        // el catálogo entero. El modelo puede devolver un nombre inventado, o el de una skill
        // de escritura que vio en otro contexto; lo que no encaje se queda en `null` y el paso
        // siguiente decide por su cuenta, que es exactamente lo que pasaba antes del triaje.
        // ⚠️ DOS listas blancas, y la asimetría entre ellas es el mecanismo entero. Las dos son
        // puras y viven en `CatalogoDelTriaje`, donde se pueden probar sin montar nada.
        $skill = trim((string) ($datos['skill'] ?? ''));
        $permitidas = CatalogoDelTriaje::permitidas($skills);
        $directas = CatalogoDelTriaje::enrutablesDirectas($skills);

        if ($skill !== '' && !in_array($skill, $directas, true)) {
            // Se distingue el motivo en el log: «no existe» es el modelo inventando, y
            // «escribe» es el modelo acertando en una skill que a propósito no se enruta sola.
            $this->logger->info(sprintf(
                'Agent: el triaje propuso «%s» como skill directa (%s); se ignora y decide el camino largo.',
                $skill,
                in_array($skill, $permitidas, true) ? 'escribe' : 'no existe en su catálogo'
            ));
            $skill = '';
        }

        // Los candidatos pasan por la lista COMPLETA —la que sí lleva las de escritura—, porque
        // el empate es justo donde importan: «el 402 quiere salir a las 3» empata
        // `evaluar_cambio_horario` con `aplicar_cambio_horario`, y adivinar ahí es aplicar un
        // cambio que nadie pidió. Un nombre inventado sigue cayendo: convertiría una pregunta
        // clara en una aclaración absurda —«¿te refieres a X o a algo que no existe?»—.
        //
        // `$permitidas` viene recortada por los roles del actor, así que a un limpiador que
        // pregunta por salidas no le puede quedar más de un candidato aunque el modelo liste dos:
        // la skill de tours no está en su catálogo. Ése es justo el motivo de filtrar aquí y no
        // después.
        $candidatos = CatalogoDelTriaje::candidatos((array) ($datos['candidatos'] ?? []), $permitidas);

        // La pista es un TEMA, no una frase. Si viene larga es que el modelo pegó el mensaje
        // del huésped, y eso es justo lo que hace que la guía responda por casualidad: ver
        // ConsultarGuiaSkill::MAX_PALABRAS_BUSQUEDA.
        $pista = trim((string) ($datos['pista'] ?? ''));
        if ($pista !== '' && count(preg_split('/\s+/u', $pista) ?: []) > 3) {
            $pista = '';
        }

        // 🔒 El tema se valida contra los de LA CASITA del huésped, no contra el índice entero:
        // un uuid inventado, de otra casita, o elegido sin saber en cuál está, se descarta y
        // queda la pista de palabras, que era el comportamiento de antes del índice. La skill
        // volvería a filtrarlo igual (árbol podado), pero descartarlo aquí ahorra mandarle al
        // camino largo una sugerencia que no puede cumplir.
        $temaId = trim((string) ($datos['tema_id'] ?? ''));
        if ($temaId !== '' && !in_array($temaId, $temasPermitidos, true)) {
            $this->logger->info(sprintf(
                'Agent: el triaje propuso un tema de guía fuera de la casita («%s»); se ignora.',
                $temaId
            ));
            $temaId = '';
        }

        // La respuesta de charla sólo vale con su tipo. Con cualquier otro es el modelo
        // saltándose el reparto de trabajo —contestar una petición sin herramientas— y se tira.
        $respuesta = trim((string) ($datos['respuesta'] ?? ''));
        if ($tipo !== TipoDeMensaje::Conversacion) {
            $respuesta = '';
        }

        return new DecisionDeTriaje(
            tipo: $tipo,
            skill: $skill !== '' ? $skill : null,
            pista: $pista !== '' ? $pista : null,
            temaId: $temaId !== '' ? $temaId : null,
            motivo: trim((string) ($datos['motivo'] ?? '')),
            respuesta: $respuesta !== '' ? $respuesta : null,
            resumen: trim((string) ($datos['resumen'] ?? '')) ?: null,
            candidatos: $candidatos,
        );
    }

    /**
     * Las reglas del clasificador, con el índice de skills dentro.
     *
     * Se monta a partir de las descripciones que ya tienen las skills, recortadas a su primera
     * frase: así no hay una segunda lista que mantener al día. Añadir una skill la mete en el
     * triaje sola.
     *
     * @param list<SkillInterface> $skills
     * @param string $indiceGuia Bloque global de temas de la guía ({@see \App\Agent\Contract\IndiceDeTemasInterface}). Es
     *        estable entre conversaciones, así que puede vivir dentro del bloque cacheado.
     */
    private function reglas(array $skills, string $indiceGuia = ''): string
    {
        $lista = [];
        foreach ($skills as $skill) {
            $lista[] = sprintf('- %s: %s', $skill->nombre(), $this->resumen($skill->definicion()->descripcion));
        }

        $catalogo = implode("\n", $lista);
        $tipos = implode('», «', TipoDeMensaje::opciones());
        $guia = $indiceGuia !== '' ? "\n\n" . $indiceGuia : '';

        return <<<PROMPT
        Clasificas el mensaje que un huésped acaba de escribir en el chat de su reserva de un
        alojamiento en Cusco, Perú. NO llamas a nada: dices de qué clase es, y sólo si es pura
        charla la contestas tú. Todo lo demás lo responde otro paso.

        TIPOS POSIBLES («{$tipos}»):

        - «conversacion»: saludo, cortesía, agradecimiento, charla, o contar algo sin pedir nada
          («hola», «gracias», «ya llegamos», «la ducha del hotel anterior no iba, ésta va
          perfecta»). No hay ninguna pregunta ni ninguna petición dentro.
        - «peticion»: quiere SABER algo (cuánto debe, cuándo sale, cómo va la calefacción) o
          quiere que PASE algo (salir más tarde, un extra, arreglar una avería, revisar un
          cobro). Es la mayoría de los mensajes. Ante la duda entre esto y «conversacion»,
          elige «peticion»: pasarse de trabajador no molesta a nadie; ignorar una pregunta sí.
          ⚠️ AVISAR DE A QUÉ HORA LLEGA O SE VA ES «peticion», aunque no venga con pregunta
          («llego a las 12», «estoy en Urubamba», «mi vuelo sale a las 10 de la noche»). Casi
          siempre lleva dentro un «¿puedo entrar ya?» o un «¿dónde dejo las maletas?», y
          contestarlo como charla sale un «te esperamos» que promete lo que nadie ha mirado.
        - «emergencia»: hay alguien en peligro o algo que no aguanta hasta mañana —fuego, olor
          a gas, agua inundando, una intrusión, un problema médico, alguien encerrado fuera de
          noche, una persona sola y asustada—. Ante la duda entre esto y «peticion», elige
          «emergencia»: equivocarse por exceso despierta a alguien; equivocarse por defecto
          deja a una persona sola con un problema de verdad.

        HERRAMIENTAS QUE PUEDE USAR EL PASO SIGUIENTE:
        {$catalogo}

        CÓMO RELLENAR LA RESPUESTA:

        - «tipo»: uno de los tres de arriba. Obligatorio.
        - «skill»: el nombre EXACTO de la herramienta de la lista que responda a lo que pide,
          y sólo cuando el tipo es «peticion». En «conversacion» y en «emergencia» va vacío.
          Si ninguna encaja, déjalo vacío: eso significa que hará falta una persona, y se
          decide después. NO te inventes nombres.
        - «tema_id»: si lo que pide está en la lista TEMAS DE LA GUÍA del final Y ese tema
          aplica a la casita del huésped (te digo cuál es la suya), copia aquí su tema_id
          EXACTO. Si dudas entre varios temas, o el tema no está para su casita, o no sabes en
          cuál está, déjalo vacío y usa «pista». NUNCA lo inventes ni lo recortes.
        - «pista»: UNA O DOS PALABRAS con el tema, y sólo cuando la herramienta busca por tema
          —la guía de la casita—. Por ejemplo «ducha», «basura», «calefacción». NUNCA el
          mensaje del huésped ni una frase: con una frase larga la búsqueda acierta por
          casualidad. Si no está clarísimo, déjalo vacío.
        - «motivo»: media línea en español diciendo por qué lo has clasificado así. Es para el
          registro interno; no lo lee ningún huésped.
        - «respuesta»: SÓLO cuando el tipo es «conversacion»: contesta tú al huésped, como
          contestaría un anfitrión amable, en una o dos frases y en SU idioma. Con cualquier
          otro tipo va vacío. Reglas de esa respuesta:
          · EN ESTE TURNO NO PUEDES CONSULTAR NADA. No des ni una fecha, ni un importe, ni un
            horario, ni un código, ni una norma, ni un enlace, ni un número de cuenta o de
            Yape, ni un tipo de cambio — tampoco si crees recordarlos: aquí no hay nada que los
            respalde. La lista no es cerrada: si es un DATO, no sale de este turno.
          · Si al escribirla ves que sí había una pregunta escondida, NO era «conversacion»:
            cambia el tipo y deja la respuesta vacía.
          · No prometas plazos ni digas que has avisado a nadie: en este turno no has avisado.
          · No menciones que eres una IA salvo que te lo pregunten directamente.
          · Escribe como se habla: sin listas, sin negritas, sin títulos. Es un chat.
          · Si el historial muestra que ya lleváis varias vueltas de charla, cierra ofreciendo
            ayuda concreta con su reserva o su casita, en una frase corta y sin insistir.

        MIRA EL HISTORIAL ANTES DE DECIDIR. Que insista —«sigue sin funcionar», «ya lo probé»—
        después de que ya se le explicara algo NO es la misma pregunta otra vez: es una avería,
        y va como «peticion». Y clasificas igual escriba en el idioma que escriba: no traduzcas
        nada, sólo entiéndelo.{$guia}
        PROMPT;
    }

    /**
     * El esquema al que se fuerza la salida.
     *
     * Los cuatro campos van en `required` aunque tres puedan ir vacíos: los modos estrictos de
     * salida estructurada exigen que todo lo declarado esté presente, y un campo «presente pero
     * vacío» es más fácil de leer aquí que un campo que a veces falta.
     *
     * `enum` en «skill» no se usa a propósito. Cerrarlo a los nombres del catálogo obligaría a
     * añadir un valor «ninguna», y el modelo dejaría de poder decir «no hay herramienta para
     * esto» sin elegir una etiqueta que parece una decisión. Con el campo abierto, lo que no
     * exista se descarta en {@see self::interpretar()} y queda en el log.
     *
     * @param list<SkillInterface> $skills
     * @return array<string, mixed>
     */
    private function esquema(array $skills): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tipo' => [
                    'type' => 'string',
                    'enum' => TipoDeMensaje::opciones(),
                    'description' => 'Qué clase de mensaje es.',
                ],
                'skill' => [
                    'type' => 'string',
                    'description' => 'Nombre exacto de la herramienta, o cadena vacía si ninguna '
                        . 'encaja o el tipo no es «peticion».',
                ],
                'tema_id' => [
                    'type' => 'string',
                    'description' => 'El tema_id EXACTO de la lista de temas de la guía, sólo '
                        . 'si aplica a la casita del huésped. Si no, cadena vacía.',
                ],
                'pista' => [
                    'type' => 'string',
                    'description' => 'Una o dos palabras con el tema, o cadena vacía.',
                ],
                'motivo' => [
                    'type' => 'string',
                    'description' => 'Media línea en español explicando la clasificación.',
                ],
                'respuesta' => [
                    'type' => 'string',
                    'description' => 'Sólo con tipo «conversacion»: la contestación al huésped, '
                        . 'en su idioma. Con cualquier otro tipo, cadena vacía.',
                ],
                // Sale GRATIS: el clasificador ya ha leído estos mensajes para decidir de
                // qué van. Pedirle además una frase de resumen no añade una llamada; sin
                // esto, ResumenConversacionService hace una segunda pasada sobre el MISMO
                // texto. Ver docs/Mensajeria.md §7.
                // Preguntar «cuáles PODRÍAN» sale casi gratis: el clasificador ya ha leído el
                // catálogo entero para elegir `skill`. Y es una pregunta CERRADA —nombres de una
                // lista— que es donde mejor se porta.
                //
                // ⚠️ Él sólo LISTA. Quien decide qué hacer con eso es el código: cuenta los
                // candidatos que sobreviven al filtro de roles del actor y, si quedan dos o más,
                // fuerza la pregunta de aclaración. Sin esto sería otra instrucción de prompt
                // —«si dudas, pregunta»— y el modelo elegiría una con toda la seguridad del mundo.
                'candidatos' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Nombres EXACTOS de las herramientas que podrían responder '
                        . 'esta pregunta, incluida la que elegiste. Normalmente será una sola, o '
                        . 'ninguna. Pon dos sólo si de verdad la pregunta no distingue entre '
                        . 'ellas —«quiénes salen mañana» cuando hay salidas de alojamiento y de '
                        . 'tours—, no porque ambas suenen parecidas. Lista vacía si el tipo no es '
                        . '«peticion».',
                ],
                'resumen' => [
                    'type' => 'string',
                    'description' => 'UNA frase en español, 12 palabras máximo, diciendo QUÉ '
                        . 'quiere o necesita el huésped. Sin saludos, comillas ni punto final. '
                        . 'Si no pide nada, dilo igual («Agradece la ayuda»). Es para que el '
                        . 'equipo sepa de un vistazo qué hay pendiente.',
                ],
            ],
            'required' => ['tipo', 'skill', 'tema_id', 'pista', 'motivo', 'respuesta', 'candidatos', 'resumen'],
            'additionalProperties' => false,
        ];
    }

    /**
     * La primera frase de la descripción, que es la que dice PARA QUÉ sirve la skill.
     *
     * Las descripciones son largas a propósito —son prompt, con sus avisos y sus contraejemplos—
     * pero el triaje no necesita saber usar la herramienta, sólo reconocerla. Mandar las
     * descripciones enteras aquí sería pagar dos veces por el mismo catálogo, y es exactamente
     * el gasto que este paso viene a evitar.
     */
    private function resumen(string $descripcion): string
    {
        $descripcion = trim((string) preg_replace('/\s+/u', ' ', $descripcion));

        // Se corta en el primer punto que deje una frase con sentido: hay descripciones que
        // empiezan con una abreviatura o una sigla y partirlas ahí no dice nada.
        $punto = mb_strpos($descripcion, '. ', 40);
        if ($punto !== false && $punto < self::RESUMEN_MAX) {
            return mb_substr($descripcion, 0, $punto + 1);
        }

        if (mb_strlen($descripcion) <= self::RESUMEN_MAX) {
            return $descripcion;
        }

        $corte = mb_substr($descripcion, 0, self::RESUMEN_MAX);
        $espacio = mb_strrpos($corte, ' ');

        return ($espacio !== false ? mb_substr($corte, 0, $espacio) : $corte) . '…';
    }
}
