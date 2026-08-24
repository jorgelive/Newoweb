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

    /**
     * Se usa si `DEEPSEEK_MODEL` viene vacía, para que el motor nunca quede sin modelo.
     *
     * ⚠️ **`deepseek-chat` y `deepseek-reasoner` están RETIRADOS** (24/07/2026). Los nombres
     * vigentes son `deepseek-v4-flash` y `deepseek-v4-pro`, y el modo pensante dejó de ser un
     * modelo aparte: va en el parámetro `thinking`. Nacer con un alias muerto no rompía nada
     * visible —`estaDisponible()` seguía diciendo que sí y cada llamada fallaba en silencio—,
     * que es la peor forma de estar roto.
     */
    private const string MODELO_FALLBACK = 'deepseek-v4-flash';

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
     * La firma que agrupa las peticiones equivalentes, para `user_id`.
     *
     * ## ⚠️ Lo que esto NO hace
     *
     * No parte el caché. La documentación de DeepSeek **no dice** que `user_id` intervenga en el
     * emparejamiento: el caché se indexa por PREFIJO y exige coincidencia completa —«A + C» no
     * casa con «A + B» aunque compartan la «A»—. Quien separa un tramo de otro es que **su
     * prefijo sea distinto**, cosa que ya ocurre sola: distinto modelo, distinto system, distinto
     * catálogo.
     *
     * Lo que sí hace es **declarar el grupo**: agrupa las llamadas equivalentes bajo una etiqueta
     * estable, que es lo que el proveedor usa para acotar límites de tasa y lo que permite mirar
     * la factura por tramo en vez de un montón indistinto. Y sobre todo deja escrito en el código
     * cuál se supone que es el prefijo compartido, que es lo que hay que mirar cuando el caché
     * deje de acertar.
     *
     * ## ⚠️ Se agrupa por lo que determina el PREFIJO, nunca por persona
     *
     * Lo intuitivo es mandar quién pregunta, y sería mentira: dos personas con los mismos roles
     * mandan exactamente el mismo prefijo —el catálogo de skills son miles de tokens idénticos—
     * y agruparlas aparte sugeriría una separación que no existe.
     *
     * Los cuatro ejes son los que de verdad cambian el prefijo:
     *
     * - **modelo**: cada uno tiene su caché.
     * - **catálogo sí/no**: `conversar()` adjunta las herramientas y `turnoDirecto()` no. Son dos
     *   prefijos distintos aunque el actor sea el mismo.
     * - **escritura**: cambia QUÉ skills entran en el catálogo.
     * - **roles + dominios**: `SkillRegistry::paraActor()` filtra por los dos, así que mismos
     *   roles con distinto dominio dan catálogos distintos.
     *
     * @param list<string> $roles Los del actor. Se ordenan: el mismo conjunto en otro orden es el
     *                            mismo catálogo, y sin ordenar daría dos etiquetas para un prefijo.
     * @param list<string> $dominios Ídem.
     */
    public static function firmaDeCache(
        array $roles,
        array $dominios,
        bool $conCatalogo,
        bool $permiteEscritura,
        string $modelo,
    ): string {
        sort($roles);
        sort($dominios);

        $firma = sprintf(
            '%s_%s_%s_%s',
            $modelo,
            $conCatalogo ? 'cat' : 'seco',
            $permiteEscritura ? 'rw' : 'ro',
            // Corto y opaco: es una clave de agrupación, no algo que nadie tenga que leer, y la
            // lista entera en claro se iría a los registros del proveedor.
            substr(hash('xxh128', implode(',', $roles).'|'.implode(',', $dominios)), 0, 12),
        );

        // ⚠️ La API sólo admite [a-zA-Z0-9-_] y 512 caracteres. Un nombre de modelo con un punto
        // —los ha habido— colaría un carácter inválido y la petición entera se rechazaría con un
        // 400, por un campo que sólo sirve para agrupar.
        return substr((string) preg_replace('/[^a-zA-Z0-9\-_]/', '-', $firma), 0, 512);
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
