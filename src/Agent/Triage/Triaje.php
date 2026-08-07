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
 * El triaje es una llamada **barata y sin herramientas** que dice sólo tres cosas: si esto es
 * charla, una petición o una emergencia; qué skill parece responder; y —si es la guía— por qué
 * tema. Con eso, cada camino se paga a su precio.
 *
 * ```
 *   mensaje del huésped
 *          │
 *          ▼
 *   ┌─────────────────────────────────────────────┐
 *   │ TRIAJE  ·  sin herramientas  ·  JSON forzado│
 *   │ prompt: reglas + lista de skills (1 línea)  │  ← estable ⇒ 100 % cacheable
 *   └─────────────────────────────────────────────┘
 *          │
 *          ├─ conversacion  → turno seco, tramo BAJO, sin catálogo  ·  céntimos
 *          ├─ emergencia    → camino largo + orden explícita de avisar al equipo
 *          ├─ peticion      → camino largo, tramo de la skill elegida
 *          └─ indeterminado → camino largo, como si el triaje no existiera
 * ```
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
 * ### Y por qué el prompt del triaje es 100 % cacheable
 *
 * No lleva NADA del huésped: ni nombre, ni idioma, ni su reserva. Son las reglas y la lista de
 * skills de su rol, idénticas byte a byte en todas las conversaciones. El mensaje va en
 * `messages`, después del corte de caché. Es el prefijo más barato de todo el módulo.
 *
 * Ver docs/Mensajeria.md §12.
 */
final readonly class Triaje
{
    /** Turnos previos que ve el clasificador. Le basta con saber de qué se venía hablando. */
    private const int HISTORIAL_MAX = 6;

    /** La salida son cuatro campos cortos; más presupuesto sólo invita a razonar en voz alta. */
    private const int MAX_TOKENS = 300;

    /** Tope del resumen de cada skill en la lista. Es un índice, no la descripción entera. */
    private const int RESUMEN_MAX = 180;

    public function __construct(
        private SelectorDePotencia $potencias,
        private SkillRegistry $skills,
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
     */
    public function clasificar(ActorInterface $actor, string $mensaje, array $historial = []): DecisionDeTriaje
    {
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

        $skills = $this->skills->paraActor($actor, incluirEscritura: false);
        if ($skills === []) {
            return DecisionDeTriaje::indeterminado('el actor no tiene ninguna skill');
        }

        try {
            $crudo = $elegido->motor->turnoDirecto(
                new ConversationRequest(
                    actor: $actor,
                    systemPrompt: $this->reglas($skills),
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

        $decision = $this->interpretar($crudo, $skills);

        $this->logger->info(sprintf(
            'Agent: triaje con %s · %s',
            $elegido->etiqueta(),
            $decision->etiqueta()
        ));

        return $decision;
    }

    /**
     * @param list<SkillInterface> $skills
     */
    private function interpretar(?string $crudo, array $skills): DecisionDeTriaje
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
        $skill = trim((string) ($datos['skill'] ?? ''));
        $permitidas = array_map(static fn (SkillInterface $s): string => $s->nombre(), $skills);

        if ($skill !== '' && !in_array($skill, $permitidas, true)) {
            $this->logger->info(sprintf('Agent: el triaje propuso una skill inexistente («%s»); se ignora.', $skill));
            $skill = '';
        }

        // La pista es un TEMA, no una frase. Si viene larga es que el modelo pegó el mensaje
        // del huésped, y eso es justo lo que hace que la guía responda por casualidad: ver
        // ConsultarGuiaSkill::MAX_PALABRAS_BUSQUEDA.
        $pista = trim((string) ($datos['pista'] ?? ''));
        if ($pista !== '' && count(preg_split('/\s+/u', $pista) ?: []) > 3) {
            $pista = '';
        }

        return new DecisionDeTriaje(
            tipo: $tipo,
            skill: $skill !== '' ? $skill : null,
            pista: $pista !== '' ? $pista : null,
            motivo: trim((string) ($datos['motivo'] ?? '')),
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
     */
    private function reglas(array $skills): string
    {
        $lista = [];
        foreach ($skills as $skill) {
            $lista[] = sprintf('- %s: %s', $skill->nombre(), $this->resumen($skill->definicion()->descripcion));
        }

        $catalogo = implode("\n", $lista);
        $tipos = implode('», «', TipoDeMensaje::opciones());

        return <<<PROMPT
        Clasificas el mensaje que un huésped acaba de escribir en el chat de su reserva. NO le
        contestas y NO llamas a nada: sólo dices de qué clase es. Otro paso se encarga de
        responder.

        TIPOS POSIBLES («{$tipos}»):

        - «conversacion»: saludo, cortesía, agradecimiento, charla, o contar algo sin pedir nada
          («hola», «gracias», «ya llegamos», «la ducha del hotel anterior no iba, ésta va
          perfecta»). No hay ninguna pregunta ni ninguna petición dentro.
        - «peticion»: quiere SABER algo (cuánto debe, cuándo sale, cómo va la calefacción) o
          quiere que PASE algo (salir más tarde, un extra, arreglar una avería, revisar un
          cobro). Es la mayoría de los mensajes. Ante la duda entre esto y «conversacion»,
          elige «peticion»: pasarse de trabajador no molesta a nadie; ignorar una pregunta sí.
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
        - «pista»: UNA O DOS PALABRAS con el tema, y sólo cuando la herramienta busca por tema
          —la guía de la casita—. Por ejemplo «ducha», «basura», «calefacción». NUNCA el
          mensaje del huésped ni una frase: con una frase larga la búsqueda acierta por
          casualidad. Si no está clarísimo, déjalo vacío.
        - «motivo»: media línea en español diciendo por qué lo has clasificado así. Es para el
          registro interno; no lo lee ningún huésped.

        MIRA EL HISTORIAL ANTES DE DECIDIR. Que insista —«sigue sin funcionar», «ya lo probé»—
        después de que ya se le explicara algo NO es la misma pregunta otra vez: es una avería,
        y va como «peticion». Y clasificas igual escriba en el idioma que escriba: no traduzcas
        nada, sólo entiéndelo.
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
                'pista' => [
                    'type' => 'string',
                    'description' => 'Una o dos palabras con el tema, o cadena vacía.',
                ],
                'motivo' => [
                    'type' => 'string',
                    'description' => 'Media línea en español explicando la clasificación.',
                ],
            ],
            'required' => ['tipo', 'skill', 'pista', 'motivo'],
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
