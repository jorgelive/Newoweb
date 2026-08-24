<?php

declare(strict_types=1);

namespace App\Agent\Provider\DeepSeek;

use App\Agent\Provider\CatalogoModelos;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Punto ÚNICO donde se habla con la API de DeepSeek.
 *
 * **Por qué no hay SDK.** La API de DeepSeek es compatible con la de OpenAI: mismo
 * `/chat/completions`, mismos `tools` y `tool_calls`. Meter un SDK de OpenAI entero para usar un
 * endpoint es arrastrar una dependencia que hay que vigilar a cambio de nada; con el
 * `HttpClientInterface` que ya usa `src/Exchange/` sobra.
 *
 * Como en los otros dos proveedores, la falta de credenciales **no es un error**: con
 * `DEEPSEEK_API_KEY` vacía el motor se declara no disponible y el registro elige otro.
 *
 * ## 🔑 El caché de DeepSeek no se pide: se merece
 *
 * Anthropic obliga a marcar qué bloque se cachea. DeepSeek **no tiene marca**: cachea solo, por
 * PREFIJO, y el acierto exige que los primeros tokens coincidan **byte a byte** con una petición
 * anterior. De ahí salen las dos reglas que sigue este conector:
 *
 * 1. Lo estable va DELANTE y lo volátil detrás — igual que en Anthropic, pero aquí no hay forma
 *    de corregirlo con una marca: si el nombre del huésped va arriba, no hay caché y punto.
 * 2. El prefijo se mantiene **idéntico dentro de cada (rol, modelo)** — ver
 *    {@see self::firmaDeCache()}.
 *
 * El caché está aislado por cuenta, así que aquí no hay nada que compartir con nadie de fuera; lo
 * que se comparte es entre las conversaciones **de esta instalación**, que es donde está el
 * ahorro: el catálogo de skills son miles de tokens idénticos en todas.
 *
 * La respuesta trae `prompt_cache_hit_tokens` y `prompt_cache_miss_tokens`, que es lo que el motor
 * registra para poder ver si esto funciona de verdad.
 */
final class DeepSeekClient
{
    private const string BASE = 'https://api.deepseek.com/chat/completions';

    /** Se usa si `DEEPSEEK_MODEL` viene vacía, para que el motor nunca quede sin modelo. */
    private const string MODELO_FALLBACK = 'deepseek-chat';

    /**
     * Mismo tope que Anthropic y Google, y por el mismo motivo: el tramo de potencia se cambia
     * con una variable de entorno y nadie espera que cambiar de modelo cambie cuánto puede tardar.
     */
    private const int TIMEOUT = 120;

    private readonly string $modelo;

    /** @var non-empty-list<string> */
    private readonly array $modelos;

    /**
     * @param string $modelos Lista separada por comas (`DEEPSEEK_MODELS`).
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $apiKey,
        string $modelo,
        string $modelos = '',
    ) {
        $this->modelo = trim($modelo) !== '' ? trim($modelo) : self::MODELO_FALLBACK;
        $this->modelos = CatalogoModelos::desde($this->modelo, $modelos);
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
     * La firma que separa un caché de otro.
     *
     * ## El problema que resuelve
     *
     * El caché se indexa por **prefijo**: dos usos que empiecen igual comparten línea y dos que
     * empiecen distinto no comparten nada. Con los tramos de potencia eso importa, porque el mismo
     * modelo puede atender el tramo alto del panel y el bajo del chat, con catálogos distintos.
     *
     * ⚠️ **Sin separar, cada tramo pisa el prefijo del otro en cada consulta**: el alto escribe su
     * catálogo, llega una del bajo con otro prefijo y falla, escribe el suyo, vuelve una del alto y
     * falla otra vez. Nadie «borra» nada —el caché es por prefijo, no una casilla— pero el efecto
     * es el mismo: **ninguno acierta nunca** y se paga todo entero siempre. Es el fallo más caro
     * posible porque no da error: sólo una factura que no baja.
     *
     * ## ⚠️ Se firma por ROLES, nunca por persona
     *
     * Lo intuitivo es mandar quién pregunta, y destruye el ahorro. Lo que hace largo el prefijo es
     * el **catálogo de skills**, y ése depende de los ROLES del actor y de si se le permite
     * escribir — no de quién sea: todas las conversaciones del mismo rol lo tienen idéntico, y son
     * miles de tokens. Firmando por persona, cada una escribiría su copia para leerla dos turnos y
     * tirarla; y firmando por conversación —que es lo que devuelve `etiqueta()` para un huésped—
     * ni eso.
     *
     * Es exactamente el fallo que Anthropic tuvo aquí, documentado en `docs/Mensajeria.md` §12: el
     * `system` llevaba el nombre del huésped dentro y el caché no acertaba jamás.
     *
     * El modelo entra en la firma porque el tramo de potencia se elige por modelo: así cambiar de
     * potencia **estrena línea propia y deja intacta la de la anterior**.
     *
     * @param list<string> $roles Los del actor. Se ordenan: el mismo conjunto en otro orden es el
     *                            mismo catálogo, y sin ordenar daría dos firmas para un prefijo.
     */
    public static function firmaDeCache(array $roles, bool $permiteEscritura, string $modelo): string
    {
        sort($roles);

        return sprintf(
            '%s/%s/%s',
            $modelo,
            $permiteEscritura ? 'rw' : 'ro',
            // Corto y opaco: es una clave de agrupación, no algo que nadie tenga que leer, y la
            // lista entera de roles en claro se iría a los logs del proveedor.
            substr(hash('xxh128', implode(',', $roles)), 0, 12),
        );
    }

    /**
     * Una vuelta de `/chat/completions`.
     *
     * @param array<string, mixed> $cuerpo
     * @return array<string, mixed> La respuesta cruda; interpretarla es cosa del motor.
     * @throws RuntimeException Si la API responde con error. El mensaje puede incluir detalles de
     *         la petición, así que se registra arriba pero nunca se le enseña al operador.
     */
    public function completar(array $cuerpo): array
    {
        try {
            $respuesta = $this->http->request('POST', self::BASE, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $cuerpo,
                'timeout' => self::TIMEOUT,
            ]);

            $estado = $respuesta->getStatusCode();
            // `false` desactiva la excepción automática de 4xx/5xx: el cuerpo de error explica
            // qué pasó y se pierde si dejamos que salte antes de leerlo.
            $datos = $respuesta->toArray(false);
        } catch (Throwable $e) {
            throw new RuntimeException('DeepSeek: '.$e->getMessage(), 0, $e);
        }

        if ($estado !== 200) {
            $motivo = $datos['error']['message'] ?? 'respuesta '.$estado;

            throw new RuntimeException('DeepSeek: '.(is_string($motivo) ? $motivo : 'error '.$estado));
        }

        return $datos;
    }
}
