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
     * @param string $modelos Lista separada por comas (`ANTHROPIC_MODELS`).
     */
    public function __construct(
        private readonly string $apiKey,
        string $modelo,
        string $modelos = '',
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
     * Modelos que el panel puede pedir. Lista blanca: ver {@see \App\Agent\Conversation\AgentEngineInterface::modelos()}.
     *
     * @return non-empty-list<string>
     */
    public function modelos(): array
    {
        return $this->modelos;
    }
}
