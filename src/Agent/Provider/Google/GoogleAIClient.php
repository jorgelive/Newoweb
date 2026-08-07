<?php

declare(strict_types=1);

namespace App\Agent\Provider\Google;

use App\Agent\Provider\CatalogoModelos;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Punto ÚNICO donde se habla con la API de Google AI Studio (Gemini).
 *
 * **Por qué no hay SDK.** Google no publica cliente PHP oficial para la API de Gemini, y los
 * de la comunidad van por detrás justo en lo que aquí importa —el *function calling*—. La
 * API REST es un `POST` con JSON: envolverla con el `HttpClientInterface` que ya usa
 * `src/Exchange/` cuesta menos que arrastrar una dependencia que habría que vigilar.
 *
 * Como en Anthropic, la falta de credenciales **no es un error**: con `GOOGLE_AI_API_KEY`
 * vacía el motor se declara no disponible y el registro elige otro proveedor.
 *
 * Ver docs/Mensajeria.md §12.
 */
final class GoogleAIClient
{
    private const string BASE = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /** Se usa si `GOOGLE_AI_MODEL` viene vacía, para que el motor nunca quede sin modelo. */
    private const string MODELO_FALLBACK = 'gemini-2.5-flash';

    /** Un turno con varias herramientas encadenadas puede tardar. Corta el cuelgue. */
    private const int TIMEOUT = 120;

    private readonly string $modelo;

    /** @var non-empty-list<string> */
    private readonly array $modelos;

    private readonly string $razonamiento;

    /**
     * @param string $modelos Lista separada por comas (`GOOGLE_AI_MODELS`).
     * @param string $razonamiento `low` | `high`, o vacío para no mandar nada.
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $apiKey,
        string $modelo,
        string $modelos = '',
        string $razonamiento = '',
    ) {
        $this->modelo = trim($modelo) !== '' ? trim($modelo) : self::MODELO_FALLBACK;
        $this->modelos = CatalogoModelos::desde($this->modelo, $modelos);
        $this->razonamiento = trim($razonamiento);
    }

    /**
     * Nivel de razonamiento, o `null` para dejar el del modelo.
     *
     * Los Gemini 3.x **razonan por defecto**, y en un agente con 22 herramientas eso se paga
     * en cada vuelta del bucle: medido, «hola» gastaba 159 tokens de pensamiento y una
     * consulta de dos skills tardaba 35 s. Con `low` baja a 0 y responde en un tercio.
     *
     * ⚠️ Es un parámetro de la generación 3.x. Los modelos 2.5 usan `thinkingBudget` y
     * **rechazan éste con un 400**; si vuelves a meter un 2.5 en la lista blanca, deja
     * `GOOGLE_AI_THINKING` vacía.
     */
    public function razonamiento(): ?string
    {
        return $this->razonamiento === '' ? null : $this->razonamiento;
    }

    public function estaConfigurado(): bool
    {
        return trim($this->apiKey) !== '';
    }

    public function modelo(): string
    {
        return $this->modelo;
    }

    /** @return non-empty-list<string> */
    public function modelos(): array
    {
        return $this->modelos;
    }

    /**
     * Una vuelta de `generateContent`.
     *
     * @param array<string, mixed> $cuerpo
     * @return array<string, mixed> La respuesta cruda; interpretarla es cosa del motor.
     * @throws RuntimeException Si la API responde con error. El mensaje puede incluir detalles
     *         de la petición, así que se registra arriba pero nunca se le enseña al operador.
     */
    public function generarContenido(string $modelo, array $cuerpo): array
    {
        try {
            $respuesta = $this->http->request('POST', sprintf(self::BASE, $modelo), [
                // La clave va en cabecera y no en `?key=`: en la query acabaría en los logs de
                // acceso de cualquier proxy por el que pase la petición.
                'headers' => [
                    'x-goog-api-key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $cuerpo,
                'timeout' => self::TIMEOUT,
            ]);

            $estado = $respuesta->getStatusCode();
            // `false` desactiva la excepción automática de 4xx/5xx: el cuerpo de error de
            // Google explica qué pasó y se pierde si dejamos que salte antes de leerlo.
            $datos = $respuesta->toArray(false);
        } catch (Throwable $e) {
            throw new RuntimeException('Google AI: ' . $e->getMessage(), 0, $e);
        }

        if ($estado !== 200) {
            throw new RuntimeException(sprintf(
                'Google AI %d: %s',
                $estado,
                is_string($datos['error']['message'] ?? null) ? $datos['error']['message'] : 'sin detalle'
            ));
        }

        return $datos;
    }
}
