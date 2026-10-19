<?php

declare(strict_types=1);

namespace App\Agent\Provider\Anthropic;

use Anthropic\Client;
use App\Agent\Provider\CatalogoModelos;

/**
 * Punto ÚNICO donde se construye el cliente de Anthropic.
 *
 * Existe para que la clave de API y el modelo se lean en un solo sitio, y sobre todo para
 * que la ausencia de credenciales no sea un error: en local o en un entorno recién montado
 * `ANTHROPIC_API_KEY` puede estar vacía, y eso tiene que degradar el sistema (el bot no
 * contesta) en vez de tumbar el webhook que trae el mensaje del huésped.
 *
 * Ver docs/Mensajeria.md §9.
 */
final class AnthropicClientFactory
{
    /** Se usa si `ANTHROPIC_MODEL` viene vacía, para que el motor nunca quede sin modelo. */
    private const string MODELO_FALLBACK = 'claude-opus-5';

    /**
     * Segundos máximos por llamada. El SDK trae 600 —diez minutos— y eso es demasiado aquí.
     *
     * Un turno puede dar hasta ocho vueltas de herramientas, así que el defecto del SDK permite
     * un turno de OCHENTA MINUTOS. Mientras tanto ese turno retiene el `GET_LOCK` de su mensaje
     * y todos los trabajos siguientes del mismo se retiran; y al otro lado hay un huésped
     * mirando el chat.
     *
     * 120 s es el mismo tope que ya usa el cliente de Google ({@see \App\Agent\Provider\Google\GoogleAIClient}),
     * y con él el peor caso baja de 80 minutos a 16. Que los dos proveedores se comporten igual
     * importa: el tramo de potencia se cambia con una variable de entorno, y nadie espera que
     * cambiar de modelo cambie cuánto puede tardar.
     */
    private const float TIMEOUT = 120.0;

    private ?Client $cliente = null;

    private readonly string $modelo;

    /** @var non-empty-list<string> */
    private readonly array $modelos;

    /**
     * @param string $modelos  Lista separada por comas (`ANTHROPIC_MODELS`).
     * @param string $esfuerzo `ANTHROPIC_EFFORT`; vacío = el del modelo. Ver `esfuerzo()`.
     * @param string $ajustes  `ANTHROPIC_AJUSTES_CHAT`; `1` los enciende. Ver `ajustesDeChat()`.
     */
    public function __construct(
        private readonly string $apiKey,
        string $modelo,
        string $modelos = '',
        private readonly string $esfuerzo = '',
        private readonly string $ajustes = '',
    ) {
        $this->modelo = trim($modelo) !== '' ? trim($modelo) : self::MODELO_FALLBACK;
        $this->modelos = CatalogoModelos::desde($this->modelo, $modelos);
    }

    /**
     * `null` cuando no hay credenciales configuradas. Quien lo consuma debe tratarlo como
     * «la IA no está disponible», no como un fallo.
     */
    public function crear(): ?Client
    {
        if (trim($this->apiKey) === '') {
            return null;
        }

        return $this->cliente ??= new Client(
            apiKey: $this->apiKey,
            requestOptions: ['timeout' => self::TIMEOUT],
        );
    }

    public function estaConfigurado(): bool
    {
        return trim($this->apiKey) !== '';
    }

    /**
     * Modelo por defecto de todo el proyecto. Se centraliza aquí para que cambiarlo sea una
     * variable de entorno y no una búsqueda por el código.
     */
    public function modelo(): string
    {
        return $this->modelo;
    }

    /**
     * El esfuerzo con que piensa Claude, o `null` para dejar el del modelo (18/10/2026).
     *
     * Los modelos 5.5 razonan SIEMPRE (no se puede apagar), y el esfuerzo es lo que dice cuánto:
     * Sonnet 5.5 viene en `high`. En la comparativa con conversaciones reales, Sonnet gastó una media
     * de ~500 tokens de salida por turno para respuestas de 50–100 —el resto, razonamiento— y salió
     * más insistente y más propenso a escalar que Gemini, que corre casi sin razonar. Para un chat,
     * la guía de Anthropic recomienda `low`; `medium` cuando hay varias herramientas encadenadas.
     *
     * Un valor que no sea un nivel válido se ignora (`null`): mejor el defecto del modelo que un 400
     * en cada turno por una errata en el `.env`.
     *
     * @return 'low'|'medium'|'high'|'xhigh'|'max'|null
     */
    public function esfuerzo(): ?string
    {
        return match (strtolower(trim($this->esfuerzo))) {
            'low' => 'low',
            'medium' => 'medium',
            'high' => 'high',
            'xhigh' => 'xhigh',
            'max' => 'max',
            default => null,
        };
    }

    /**
     * ¿Se añaden al prompt los ajustes de chat para Claude 5.5? Ver `AnthropicEngine::AJUSTES_DE_CHAT`.
     */
    public function ajustesDeChat(): bool
    {
        return in_array(strtolower(trim($this->ajustes)), ['1', 'true', 'si', 'sí', 'on'], true);
    }

    /**
     * Modelos que el panel puede pedir. Lista blanca: ver {@see \App\Agent\Conversation\AgentEngineInterface::modelos()}.
     *
     * @return non-empty-list<string>
     */
    public function modelos(): array
    {
        return $this->modelos;
    }
}
