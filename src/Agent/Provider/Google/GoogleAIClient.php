<?php

declare(strict_types=1);

namespace App\Agent\Provider\Google;

use App\Agent\Conversation\FalloDelMotor;
use App\Dto\Lee;
use App\Agent\Provider\CatalogoModelos;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
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

    /**
     * Cuántas veces se pide, y cuánto se espera entre una y otra, ante un fallo PASAJERO.
     *
     * 🔥 El 13/10/2026 a las 18:48 Google devolvió UNA respuesta que no era JSON —la página de un
     * 503 por sobrecarga, con toda probabilidad— y al segundo siguiente ya respondía bien. Sin
     * reintento, ese único tropiezo bastó para que el huésped recibiera el acuse de cortesía en
     * vez de su respuesta y para que `VigilanteDelMotor` mandara al equipo «El agente NO está
     * contestando — conviene mirarlo ya». Un segundo intento lo habría absorbido sin que nadie
     * lo notara. Si también falla, ya no es un tropiezo: el aviso sí toca.
     */
    private const int INTENTOS = 2;
    private const int ESPERA_ENTRE_INTENTOS_MS = 2000;

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
        private readonly ?LoggerInterface $logger = null,
        /** Sólo los tests la cambian: para no esperar dos segundos de verdad. */
        private readonly int $esperaEntreIntentosMs = self::ESPERA_ENTRE_INTENTOS_MS,
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
        for ($intento = 1; ; ++$intento) {
            try {
                return $this->llamar($modelo, $cuerpo);
            } catch (FalloPasajeroDeGoogle $e) {
                if ($intento >= self::INTENTOS) {
                    throw $e;
                }

                $this->logger?->warning(sprintf('[Agente] %s — se reintenta (%d/%d).', $e->getMessage(), $intento + 1, self::INTENTOS));
                usleep($this->esperaEntreIntentosMs * 1000);
            }
        }
    }

    /**
     * Una petición.
     *
     * ⚠️ **El código HTTP se lee ANTES que el cuerpo.** Antes `toArray()` iba dentro del mismo
     * `try` y, ante una página de error en HTML, lanzaba «Syntax error for "https://…"»: el aviso
     * al equipo decía eso y no «503», que es lo único que permite saber si hay que hacer algo.
     *
     * @param array<string, mixed> $cuerpo
     *
     * @return array<string, mixed>
     */
    private function llamar(string $modelo, array $cuerpo): array
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
            // `false`: el cuerpo de error de Google explica qué pasó y se pierde si dejamos que
            // la excepción automática de 4xx/5xx salte antes de leerlo.
            $crudo = $respuesta->getContent(false);
        } catch (TimeoutExceptionInterface $e) {
            // Dos minutos sin respuesta no se reintentan: serían cuatro con el huésped esperando.
            throw new RuntimeException('Google AI: ' . $e->getMessage(), 0, $e);
        } catch (TransportExceptionInterface $e) {
            throw new FalloPasajeroDeGoogle('Google AI (red): ' . $e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new RuntimeException('Google AI: ' . $e->getMessage(), 0, $e);
        }

        $datos = json_decode($crudo, true);

        if (!is_array($datos)) {
            $muestra = trim((string) preg_replace('/\s+/', ' ', strip_tags(mb_substr($crudo, 0, 300))));

            throw new FalloPasajeroDeGoogle(sprintf(
                'Google AI %d: respuesta que no es JSON%s',
                $estado,
                $muestra !== '' ? ' («' . mb_substr($muestra, 0, 80) . '»)' : ''
            ));
        }

        if ($estado !== 200) {
            $mensaje = sprintf('Google AI %d: %s', $estado, Lee::texto(Lee::en($datos, 'error', 'message')) ?? 'sin detalle');

            // Un 5xx es Google teniendo un mal momento; un 4xx —clave, créditos, modelo, cuota— no
            // se arregla pidiéndolo otra vez, y el aviso tiene que salir al primero.
            //
            // Si es DEFINITIVO lo dice el código y no el `status`: Gemini manda `RESOURCE_EXHAUSTED`
            // tanto en el 402 de créditos («Your prepayment credits are depleted…», visto el
            // 23/10/2026) como en el 429 de ritmo. Ver `FalloDelMotor`.
            throw $estado >= 500 ? new FalloPasajeroDeGoogle($mensaje) : FalloDelMotor::porCodigoHttp($mensaje, $estado);
        }

        return Lee::objeto($datos);
    }
}
