<?php

declare(strict_types=1);

namespace App\Agent\Alexa;

/**
 * El JSON que manda Alexa, leído en términos nuestros.
 *
 * Existe para que el controlador no ande metiendo la mano en un array de seis niveles. Sólo
 * expone lo que este skill usa; el resto del sobre —`context.Viewport`, `AudioPlayer`…— se
 * ignora a propósito.
 *
 * Ver docs/AgentVoz.md §2.
 */
final readonly class PeticionAlexa
{
    /**
     * @param string $tipo `LaunchRequest`, `IntentRequest` o `SessionEndedRequest`.
     * @param array<string, mixed> $atributos Lo que devolvimos en el turno anterior. Es donde
     *        viaja el historial: Alexa lo guarda por nosotros y lo reenvía en cada turno de la
     *        misma sesión, así el endpoint sigue sin estado como el del panel.
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
    ) {}

    /**
     * @param array<string, mixed> $sobre
     */
    public static function desde(array $sobre): self
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

        return new self(
            tipo: (string) ($peticion['type'] ?? ''),
            intent: isset($intent['name']) ? (string) $intent['name'] : null,
            consulta: self::slot($intent),
            applicationId: isset($aplicacion['applicationId']) ? (string) $aplicacion['applicationId'] : null,
            usuarioAlexa: self::usuario($sistema, $sesion),
            dispositivo: self::id(self::sub($sistema, 'device'), 'deviceId'),
            // `person` sólo viaja cuando Alexa RECONOCIÓ la voz contra un perfil entrenado.
            // Ausente no significa «no era nadie»: significa «no sé quién era».
            persona: self::id(self::sub($sistema, 'person'), 'personId'),
            timestamp: isset($peticion['timestamp']) ? (string) $peticion['timestamp'] : null,
            atributos: self::sub($sesion, 'attributes'),
        );
    }

    /**
     * El texto dictado por el operador.
     *
     * Se busca `consulta` primero y, si no está, se coge el primer slot con valor: el modelo de
     * interacción es de un solo slot `AMAZON.SearchQuery`, pero renombrarlo en la consola de
     * Amazon no debería dejar el skill mudo sin ninguna pista de por qué.
     *
     * @param array<string, mixed> $intent
     */
    private static function slot(array $intent): ?string
    {
        $slots = self::sub($intent, 'slots');

        $valor = self::sub($slots, 'consulta')['value'] ?? null;
        if (is_string($valor) && trim($valor) !== '') {
            return trim($valor);
        }

        foreach ($slots as $slot) {
            if (is_array($slot) && is_string($slot['value'] ?? null) && trim($slot['value']) !== '') {
                return trim($slot['value']);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $sistema
     * @param array<string, mixed> $sesion
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
     * @param array<string, mixed> $origen
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
     * @param array<string, mixed> $origen
     * @return array<string, mixed>
     */
    private static function sub(array $origen, string $clave): array
    {
        $valor = $origen[$clave] ?? null;

        return is_array($valor) ? $valor : [];
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

            $rol = (string) ($turno['rol'] ?? '');
            $texto = trim((string) ($turno['texto'] ?? ''));

            if ($texto === '' || !in_array($rol, ['usuario', 'asistente'], true)) {
                continue;
            }

            $turnos[] = ['rol' => $rol, 'texto' => $texto];
        }

        return array_slice($turnos, -$maxTurnos);
    }
}
