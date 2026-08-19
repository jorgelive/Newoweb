<?php

declare(strict_types=1);

namespace App\Agent\Service;

use App\Agent\Access\ActorInterface;
use App\Agent\Conversation\AgentEngineRegistry;
use App\Agent\Conversation\ReglasCompartidas;
use App\Agent\Conversation\ConversationRequest;
use App\Agent\Conversation\ConversationResponse;
use App\Agent\Skill\SkillRegistry;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * El asistente del panel: adaptador delgado entre la interfaz del equipo y el motor.
 *
 * Sólo aporta lo que es suyo — el prompt del contexto interno y la política de qué hacer
 * cuando el motor responde sin usar ninguna skill. Ni las skills, ni los permisos, ni cómo
 * habla cada proveedor: eso vive en el registro de skills y en el motor.
 *
 * De proveedor y modelo sólo conoce lo que le llega en la llamada, y se lo pasa al
 * {@see AgentEngineRegistry} para que valide y elija. Ver docs/Mensajeria.md §11 y §12.
 */
final readonly class PanelAssistant
{
    /** Zona del negocio: decide qué es «hoy» y a qué año se refiere «12 de marzo». */
    private const string TZ = 'America/Lima';

    /** Techo de la pregunta. Corta pegotes accidentales antes de pagar tokens. */
    private const int MAX_CARACTERES = 500;

    public function __construct(
        private AgentEngineRegistry $motores,
        private SkillRegistry $skills,
    ) {}

    public function estaDisponible(): bool
    {
        return $this->motores->hayDisponible();
    }

    /**
     * @param list<array{rol: string, texto: string}> $historial Turnos previos del hilo.
     * @param string|null $proveedor Motor pedido desde el desplegable. `null` = el de por defecto.
     * @param string|null $modelo Modelo dentro de ese proveedor. `null` = el de por defecto.
     * @return array{respuesta: string, herramientas: list<string>, hubo_escritura: bool, proveedor: string, modelo: string}
     *         Se devuelve **quién contestó**: comparando dos proveedores sobre la misma
     *         pregunta, una respuesta sin firmar no vale para nada.
     */
    public function preguntar(
        string $pregunta,
        ActorInterface $actor,
        array $historial = [],
        ?string $proveedor = null,
        ?string $modelo = null,
    ): array {
        $pregunta = trim($pregunta);

        if ($pregunta === '') {
            throw new InvalidArgumentException('Escribe una pregunta.');
        }

        if (mb_strlen($pregunta) > self::MAX_CARACTERES) {
            throw new InvalidArgumentException(sprintf(
                'La pregunta no puede pasar de %d caracteres.',
                self::MAX_CARACTERES
            ));
        }

        // Un proveedor o un modelo que no existan son error de quien pregunta (400), no un
        // fallo del asistente: el registro lanza InvalidArgumentException y sube tal cual.
        $motor = $this->motores->elegir($proveedor);
        if ($motor === null) {
            throw new InvalidArgumentException('El asistente no está configurado en este entorno.');
        }

        $modelo = $this->motores->validarModelo($motor, $modelo);

        $respuesta = $motor->conversar(new ConversationRequest(
            actor: $actor,
            systemPrompt: $this->systemPrompt(),
            mensaje: $pregunta,
            historial: $historial,
            modelo: $modelo,
        ));

        return [
            'respuesta' => $this->texto($respuesta),
            'herramientas' => $respuesta->skillsUsadas,
            'hubo_escritura' => $this->huboEscritura($respuesta->skillsUsadas),
            'proveedor' => $motor->nombre(),
            'modelo' => $modelo ?? $motor->modeloPorDefecto(),
        ];
    }

    /**
     * ¿Alguna de las skills usadas dejó la pantalla desactualizada?
     *
     * Lo resuelve el backend y no el front a propósito: aquí se conoce el nivel de riesgo real
     * de cada skill ({@see \App\Agent\Access\NivelRiesgo::modificaDatos()}), mientras que en el
     * front habría que mantener a mano una lista de nombres que se desincroniza en cuanto
     * alguien añade una skill de escritura y se olvida de apuntarla.
     *
     * Una skill desconocida —renombrada, retirada— cuenta como que SÍ escribió: refrescar de
     * más cuesta una petición; refrescar de menos deja al operador mirando un dato viejo y
     * creyendo que su cambio no se aplicó.
     *
     * @param list<string> $usadas
     */
    private function huboEscritura(array $usadas): bool
    {
        foreach ($usadas as $nombre) {
            $skill = $this->skills->buscar($nombre);

            if ($skill === null || $skill->nivelRiesgo()->modificaDatos()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Catálogo de proveedores y modelos para el desplegable del panel.
     *
     * @return list<array{proveedor: string, etiqueta: string, disponible: bool, modelos: list<string>, modeloPorDefecto: string, porDefecto: bool}>
     */
    public function catalogoDeMotores(): array
    {
        return $this->motores->catalogo();
    }

    /**
     * Aquí se decide qué ve el operador en cada desenlace.
     *
     * A diferencia del chat del huésped, un `sin_skill` **sí se muestra**: quien pregunta es
     * un compañero que sabe interpretar un «esto no lo sé hacer», y saberlo es más útil que
     * un silencio.
     */
    private function texto(ConversationResponse $respuesta): string
    {
        return match ($respuesta->motivo) {
            'sin_permisos' => 'No tienes permisos para consultar nada por aquí.',
            'motor_no_disponible' => 'El asistente no está configurado en este entorno.',
            'rechazado' => 'No he podido procesar esa consulta.',
            default => $respuesta->tieneTexto()
                ? (string) $respuesta->texto
                : 'No he sabido responder a eso.',
        };
    }

    private function systemPrompt(): string
    {
        $hoy = new DateTimeImmutable('now', new DateTimeZone(self::TZ));

        // ⚠️ Le faltaba, y era el que MÁS lo necesitaba: aquí se pide redactar textos «para
        // copiárselo al huésped», así que un número de cuenta inventado no lo lee un bot, lo
        // pega un operador que se fía. Ver ReglasCompartidas.
        $copiar = ReglasCompartidas::DATOS_QUE_SE_COPIAN;

        return <<<PROMPT
        Eres el asistente interno del equipo de reservas de un alojamiento en Cusco, Perú.
        Hablas con un compañero de trabajo, no con un huésped.

        Hoy es {$hoy->format('Y-m-d')} ({$hoy->format('l')}), zona horaria America/Lima.
        Úsalo para resolver fechas relativas: si dicen «del 12 al 15 de marzo» sin año, se
        refieren a la próxima vez que ocurra esa fecha, no a una pasada.

        Cómo habla el equipo:
        - «pasajero» y «huésped» son lo mismo. Se opera también como agencia, así que los dos
          términos se mezclan a diario y ninguno tiene un significado propio en el sistema.
          Las skills dicen «huésped»; si te preguntan por «el pasajero», es la misma persona.
        - «casita» es la unidad alojable. «La 1», «casita 1» y «Casita 1» son la misma.

        {$copiar}

        Reglas:
        - NUNCA INVENTES EL VALOR DE UN PARÁMETRO. Es la regla más importante de todas. Si el
          operador no ha dicho un dato, OMÍTELO al llamar a la skill: ella te dirá que falta y
          con qué palabras preguntarlo. No rellenes con lo más probable, ni con lo que suele
          pasar, ni con lo que había en una consulta anterior.
          Ejemplo de lo que NO debes hacer: te dicen «natalia me pagó 60» y tú llamas con
          moneda="USD" (no lo dijeron), medio_pago="efectivo" (no lo dijeron) y sin cobrador
          (sí lo dijeron: «me» significa que lo cobró quien te habla). Eso registra un pago
          con tres datos inventados y el operador lo aprueba creyendo que se los preguntaste.
          Lo correcto ahí es mandar sólo el importe y el cobrador, y preguntar el resto.
          Un dato que el operador no ha dicho no es un dato: es una pregunta pendiente.
        - Cuando una skill devuelva «falta_datos» o un error diciendo que falta algo, PREGÚNTALE
          al operador con el texto que te da y espera su respuesta. No la vuelvas a llamar
          rellenando el hueco por tu cuenta, ni quitando el dato que causó el aviso: si dijiste
          que lo cobró alguien y la skill lo rechaza, eso se le cuenta al operador, no se
          silencia dejando que caiga en el cobrador por defecto.
        - Para cualquier dato del PMS, LLAMA a la skill correspondiente. Nunca respondas de
          memoria ni estimes: si falla, dilo.
        - Si no tienes ninguna skill para lo que te piden, dilo claramente en una frase. No
          improvises una respuesta ni prometas hacerlo luego.
        - Responde en español por defecto, breve y directo, como un compañero: sin preámbulos
          ni resúmenes de lo que vas a hacer.
        - FORMATO DE RESPUESTA:
          * Por defecto, responde en formato estructurado compatible con el visor HTML del panel (usa Markdown limpio con **negrita**, listas con guiones «- », saltos de línea claros y enlaces [texto](url)).
          * ÚNICAMENTE si el operador te pide explícitamente redactar un mensaje «para el huésped», «para WhatsApp» o «para copiárselo», redáctalo en el formato canónico de WhatsApp (usando *negrita* con asterisco simple, sin títulos ni sintaxis markdown compleja, listo para copiar y pegar directamente).
        - POLÍTICA DE MEDIOS DE PAGO SEGÚN NACIONALIDAD:
          * Huéspedes que pagan desde Perú (`huesped_paga_desde_peru: true`, que devuelven consultar_cuenta y consultar_medios_pago): Ofrecer opciones en Soles (Transferencia bancaria BCP/BBVA/Interbank, Yape, Plin) y pago con Tarjeta (con 5.5% de recargo). NUNCA ofrecer Western Union a peruanos.
          * Huéspedes que pagan desde fuera (`huesped_paga_desde_peru: false`): Ofrecer ÚNICAMENTE Tarjeta de crédito/débito (enlace seguro con recargo del +5.5% en USD) o Western Union. NUNCA ofrecer transferencia bancaria internacional a extranjeros (no se acepta).
        - Si te piden un texto para enviárselo a un huésped —«pásame su estado de cuenta para
          copiárselo», «escríbeselo en inglés»—, redáctalo en el idioma que te pidan, o en el
          del huésped si no lo dicen: las skills devuelven `idioma_huesped` cuando lo saben.
          Trabajamos en español, inglés, portugués, francés, italiano, alemán y neerlandés.
        - Los importes se dicen con su moneda y sin convertir: si la cuenta está en dólares,
          se habla en dólares.
        - Al listar casitas, di cuántas hay y nómbralas con su capacidad y tarifa base. Si no
          hay ninguna, dilo en una frase.
        - La tarifa base es orientativa: no la presentes como el precio final de venta.
        - Si una skill devuelve varias coincidencias (dos huéspedes con el mismo nombre, por
          ejemplo), NO elijas: enséñalas con el dato que las distingue —fechas, casita,
          localizador— y pregunta cuál. Es una conversación: puedes repreguntar y continuar
          con lo que te respondan.
        PROMPT;
    }
}
