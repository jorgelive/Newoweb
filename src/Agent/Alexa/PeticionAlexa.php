<?php

declare(strict_types=1);

namespace App\Agent\Alexa;

use App\Dto\Lee;

/**
 * El JSON que manda Alexa, leído en términos nuestros.
 *
 * Existe para que el controlador no ande metiendo la mano en un array de seis niveles. Sólo
 * expone lo que este skill usa; el resto del sobre —`context.Viewport`, `AudioPlayer`…— se
 * ignora a propósito.
 *
 * Es un DTO de frontera: {@see self::fromArray()} es el único sitio que toca el JSON de Amazon, y lo
 * lee con `App\Dto\Lee` —lo que no es del tipo esperado es «no llegó»—. Ver
 * `docs/TiposDeFrontera.md`.
 *
 * Ver docs/AgentVoz.md §2.
 */
final readonly class PeticionAlexa
{
    /**
     * Intents que llevan una palabra CON SENTIDO en el nombre: aquí se le devuelve al texto.
     *
     * Alexa sólo enruta un `AMAZON.SearchQuery` si va detrás de una palabra portadora, y esa
     * palabra **se la come**: con «quién» de portadora, «quién está en la casa 1» llega como «está
     * en la casa 1»; con «no», «no, la casita 5» llega como «la casita 5» —lo contrario—. Por eso
     * cada palabra que cambia el sentido tiene su propio intent (con `{palabra} {consulta}` de
     * única frase) y la recupera aquí. En `ConsultaIntent` sólo quedan las de relleno («dime»,
     * «oye», «para»), que no importa perder.
     *
     * ⚠️ **Es la mitad del modelo de `tools/alexa/modelo-interaccion.json`**: una palabra nueva va
     * en los dos sitios, y `PeticionAlexaTest` comprueba que coinciden. Ver docs/AgentVoz.md §9.
     */
    public const array PALABRA_DEL_INTENT = [
        'AMAZON.YesIntent' => 'sí',
        'AfirmacionIntent' => 'sí',
        'AMAZON.NoIntent' => 'no',
        'NegacionIntent' => 'no',
        'QuienIntent' => 'quién',
        'QuienesIntent' => 'quiénes',
        'QueIntent' => 'qué',
        'CuantoIntent' => 'cuánto',
        'CuantaIntent' => 'cuánta',
        'CuantosIntent' => 'cuántos',
        'CuantasIntent' => 'cuántas',
        'CualIntent' => 'cuál',
        'CualEsIntent' => 'cuál es',
        'CualesIntent' => 'cuáles',
        'CuandoIntent' => 'cuándo',
        'DondeIntent' => 'dónde',
        'ComoIntent' => 'cómo',
        'AQueHoraIntent' => 'a qué hora',
        'HayIntent' => 'hay',
        'EstaIntent' => 'está',
        'EstanIntent' => 'están',
        'SaleIntent' => 'sale',
        'SalenIntent' => 'salen',
        'LlegaIntent' => 'llega',
        'LleganIntent' => 'llegan',
        'EntraIntent' => 'entra',
        'EntranIntent' => 'entran',
        'TengoIntent' => 'tengo',
        'TenemosIntent' => 'tenemos',
        'SoloIntent' => 'solo',
        'YSiIntent' => 'y si',
    ];

    /**
     * @param string $tipo `LaunchRequest`, `IntentRequest` o `SessionEndedRequest`.
     * @param array<mixed> $atributos Lo que devolvimos en el turno anterior. Es donde
     *        viaja el historial: Alexa lo guarda por nosotros y lo reenvía en cada turno de la
     *        misma sesión, así el endpoint sigue sin estado como el del panel.
     * @param string|null $apiEndpoint Y {@see self::$apiToken}: con qué preguntarle a la API de
     *        Amazon por el perfil de voz ({@see DiagnosticoAlexa}). ⚠️ El token da acceso a esa
     *        API en nombre del cliente: nunca al log.
     */
    private function __construct(
        public string $tipo,
        public ?string $intent,
        public ?string $consulta,
        public ?string $applicationId,
        public ?string $usuarioAlexa,
        public ?string $dispositivo,
        public ?string $persona,
        public ?string $timestamp,
        public array $atributos,
        public ?string $sesion = null,
        public bool $sesionNueva = false,
        public ?string $idioma = null,
        public ?string $apiEndpoint = null,
        public ?string $apiToken = null,
    ) {}

    /**
     * @param array<mixed> $sobre
     */
    public static function fromArray(array $sobre): self
    {
        $peticion = self::sub($sobre, 'request');
        $sesion = self::sub($sobre, 'session');
        $sistema = self::sub(self::sub($sobre, 'context'), 'System');

        // El applicationId viaja en dos sitios y no siempre en los dos: `context` es el bueno
        // en las peticiones modernas, `session` sobrevive por compatibilidad.
        $aplicacion = self::sub($sistema, 'application');
        if ($aplicacion === []) {
            $aplicacion = self::sub($sesion, 'application');
        }

        $intent = self::sub($peticion, 'intent');
        $nombre = Lee::texto($intent['name'] ?? null);

        return new self(
            tipo: Lee::texto($peticion['type'] ?? null) ?? '',
            intent: $nombre,
            consulta: self::consulta($nombre, $intent),
            applicationId: Lee::texto($aplicacion['applicationId'] ?? null),
            usuarioAlexa: self::usuario($sistema, $sesion),
            dispositivo: self::id(self::sub($sistema, 'device'), 'deviceId'),
            // `person` sólo viaja cuando Alexa RECONOCIÓ la voz contra un perfil entrenado.
            // Ausente no significa «no era nadie»: significa «no sé quién era».
            persona: self::id(self::sub($sistema, 'person'), 'personId'),
            timestamp: Lee::texto($peticion['timestamp'] ?? null),
            atributos: self::sub($sesion, 'attributes'),
            // Para cruzar en el log la identidad con la pregunta: ver DiagnosticoAlexa.
            sesion: self::id($sesion, 'sessionId'),
            sesionNueva: ($sesion['new'] ?? false) === true,
            idioma: Lee::texto($peticion['locale'] ?? null),
            apiEndpoint: self::id($sistema, 'apiEndpoint'),
            apiToken: self::id($sistema, 'apiAccessToken'),
        );
    }

    /**
     * Lo que se le pasa al modelo: el slot y, en un «sí»/«no», la palabra delante.
     *
     * @param array<mixed> $intent
     */
    private static function consulta(?string $nombre, array $intent): ?string
    {
        $texto = self::slot($intent);
        $palabra = self::PALABRA_DEL_INTENT[$nombre ?? ''] ?? null;

        if ($palabra === null) {
            return $texto;
        }
        if ($texto === null) {
            return $palabra;
        }

        // A veces Alexa SÍ deja la palabra dentro del slot (el 17/10 llegó «quiénes salen mañana»
        // entero): no se duplica. Se compara sin tildes porque el reconocedor no siempre las pone.
        if (str_starts_with(self::sinTildes($texto) . ' ', self::sinTildes($palabra) . ' ')) {
            return $texto;
        }

        // El «sí»/«no» se separa con coma: «no, la casita 5» y no «no la casita 5».
        return $palabra . (in_array($palabra, ['sí', 'no'], true) ? ', ' : ' ') . $texto;
    }

    private static function sinTildes(string $texto): string
    {
        return strtr(mb_strtolower($texto), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
    }

    /**
     * El texto dictado por el operador.
     *
     * Se busca `consulta` primero y, si no está, se juntan los slots con valor: así una respuesta
     * suelta con número (`CantidadIntent`) llega entera, y renombrar el slot en la consola de
     * Amazon no deja el skill mudo sin ninguna pista de por qué.
     *
     * @param array<mixed> $intent
     */
    private static function slot(array $intent): ?string
    {
        $slots = self::sub($intent, 'slots');

        $valor = self::sub($slots, 'consulta')['value'] ?? null;
        if (is_string($valor) && trim($valor) !== '') {
            return trim($valor);
        }

        // Sin `consulta`, todos los slots con valor, en orden: «cuatro personas» llega en dos
        // (`CantidadIntent`: cantidad + unidad) y quedarse con el primero diría sólo «4».
        $partes = [];
        foreach ($slots as $slot) {
            if (is_array($slot) && is_string($slot['value'] ?? null) && trim($slot['value']) !== '') {
                $partes[] = trim($slot['value']);
            }
        }

        return $partes === [] ? null : implode(' ', $partes);
    }

    /**
     * @param array<mixed> $sistema
     * @param array<mixed> $sesion
     */
    private static function usuario(array $sistema, array $sesion): ?string
    {
        $usuario = self::sub($sistema, 'user');
        if ($usuario === []) {
            $usuario = self::sub($sesion, 'user');
        }

        $id = $usuario['userId'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @param array<mixed> $origen
     */
    private static function id(array $origen, string $clave): ?string
    {
        $id = $origen[$clave] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Los tres identificadores que manda Alexa, **del más específico al más vago**. Es el orden
     * en que se busca a quién atribuir la consulta: ver {@see AlexaUsuarios::resolver()}.
     *
     * @return list<string>
     */
    public function identidades(): array
    {
        return array_values(array_filter([$this->persona, $this->dispositivo, $this->usuarioAlexa]));
    }

    /**
     * @param array<mixed> $origen
     * @return array<mixed>
     */
    private static function sub(array $origen, string $clave): array
    {
        return Lee::mapa($origen[$clave] ?? null);
    }

    public function esLanzamiento(): bool
    {
        return $this->tipo === 'LaunchRequest';
    }

    public function esFin(): bool
    {
        return $this->tipo === 'SessionEndedRequest';
    }

    public function esIntent(): bool
    {
        return $this->tipo === 'IntentRequest';
    }

    /** Los intents con los que Alexa exige que cualquier skill sepa cerrar la sesión. */
    public function pideSalir(): bool
    {
        return in_array($this->intent, ['AMAZON.StopIntent', 'AMAZON.CancelIntent'], true);
    }

    public function pideAyuda(): bool
    {
        return $this->intent === 'AMAZON.HelpIntent';
    }

    /**
     * El historial que dejamos en `sessionAttributes` el turno anterior.
     *
     * Se reconstruye con desconfianza —Alexa lo devuelve tal cual se lo dimos, pero el formato
     * es el que espera el motor y una entrada torcida rompería el turno— y se recorta: la
     * respuesta a Alexa tiene tope de tamaño y un hilo largo lo agota.
     *
     * @return list<array{rol: string, texto: string}>
     */
    public function historial(int $maxTurnos): array
    {
        $crudo = $this->atributos['historial'] ?? null;
        if (!is_array($crudo)) {
            return [];
        }

        $turnos = [];
        foreach ($crudo as $turno) {
            if (!is_array($turno)) {
                continue;
            }

            $rol = Lee::texto($turno['rol'] ?? null) ?? '';
            $texto = trim(Lee::texto($turno['texto'] ?? null) ?? '');

            if ($texto === '' || !in_array($rol, ['usuario', 'asistente'], true)) {
                continue;
            }

            $turnos[] = ['rol' => $rol, 'texto' => $texto];
        }

        return array_slice($turnos, -$maxTurnos);
    }
}
