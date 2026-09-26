<?php

declare(strict_types=1);

namespace App\Tests\Agent\Provider;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\GuardiaDeSkills;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Access\RestriccionCanal;
use App\Agent\Conversation\ConversationRequest;
use App\Agent\Provider\DeepSeek\DeepSeekClient;
use App\Agent\Provider\DeepSeek\DeepSeekEngine;
use App\Agent\Provider\DeepSeek\DeepSeekSkillAdapter;
use App\Agent\Provider\Google\GoogleAIClient;
use App\Agent\Provider\Google\GoogleAIEngine;
use App\Agent\Provider\Google\GoogleAISkillAdapter;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillRegistry;
use App\Agent\Skill\SkillResult;
use App\Contract\VinculoComercial;
use App\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * El motor entero, con la API sustituida por respuestas grabadas: qué se registra en `info.log`, qué
 * se le devuelve al proveedor en la vuelta siguiente y qué recibe la skill.
 *
 * Las líneas `Agent (deepseek): …` y `Agent (google): …` son de donde sale el coste real del agente
 * (docs/Agent.md §3.4). Aquí se comprueban con las cifras exactas que habría escrito el código de
 * antes del DTO sobre las mismas respuestas.
 */
#[CoversClass(DeepSeekEngine::class)]
#[CoversClass(GoogleAIEngine::class)]
final class LineasDeConsumoTest extends TestCase
{
    /** @var list<array<mixed>> Los cuerpos que el motor mandó a la API, en orden. */
    private array $enviados = [];

    /** @var list<string> Los mismos, sin decodificar: `{}` y `[]` sólo se distinguen aquí. */
    private array $crudos = [];

    /** @var list<array{string, string}> [nivel, mensaje] */
    private array $lineas = [];

    /** @var list<array<string, mixed>> Lo que recibió la skill en cada ejecución. */
    private array $recibido = [];

    // ── DeepSeek ─────────────────────────────────────────────────────────────

    public function testDeepSeekSumaLasVueltasYDevuelveElTurnoTalCual(): void
    {
        $turnoConLlamada = [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [[
                'id' => 'call_0_7f3a',
                'type' => 'function',
                'function' => ['name' => 'consultar_libres', 'arguments' => '{"desde":"2026-10-01","pax":4}'],
            ]],
        ];

        $motor = $this->deepSeek([
            ['choices' => [['message' => $turnoConLlamada, 'finish_reason' => 'tool_calls']],
                'usage' => ['prompt_cache_hit_tokens' => 5888, 'prompt_cache_miss_tokens' => 242, 'completion_tokens' => 41]],
            ['choices' => [['message' => ['role' => 'assistant', 'content' => ' La casita 3 está libre. '], 'finish_reason' => 'stop']],
                'usage' => ['prompt_cache_hit_tokens' => 6144, 'prompt_cache_miss_tokens' => 310, 'completion_tokens' => 58]],
        ]);

        $respuesta = $motor->conversar($this->peticion());

        self::assertSame('La casita 3 está libre.', $respuesta->texto);
        self::assertSame(['consultar_libres'], $respuesta->skillsUsadas);
        self::assertContains(
            ['info', 'Agent (deepseek): deepseek-v4-flash · 2 vuelta(s) · caché acierto 12032 · caché fallo 552 · salida 99 tokens.'],
            $this->lineas,
        );

        // La skill recibió los argumentos decodificados de la cadena JSON.
        self::assertSame([['desde' => '2026-10-01', 'pax' => 4]], $this->recibido);

        // Y la segunda petición lleva el turno del asistente INTACTO y la respuesta emparejada.
        $mensajes = $this->enviados[1]['messages'] ?? [];
        self::assertIsArray($mensajes);
        $ultimos = array_slice($mensajes, -2);
        self::assertSame($turnoConLlamada, $ultimos[0]);
        self::assertSame('tool', $ultimos[1]['role'] ?? null);
        self::assertSame('call_0_7f3a', $ultimos[1]['tool_call_id'] ?? null);
    }

    public function testDeepSeekTurnoDirecto(): void
    {
        $motor = $this->deepSeek([
            ['choices' => [['message' => ['content' => '{"tipo":"conversacion"}'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_cache_hit_tokens' => 1024, 'prompt_cache_miss_tokens' => 88, 'completion_tokens' => 9]],
        ]);

        self::assertSame('{"tipo":"conversacion"}', $motor->turnoDirecto($this->peticion()));
        self::assertContains(
            ['info', 'Agent (deepseek): turno directo · deepseek-v4-flash · caché acierto 1024 · caché fallo 88 · salida 9 tokens.'],
            $this->lineas,
        );
    }

    public function testDeepSeekFiltradaEsRechazada(): void
    {
        $motor = $this->deepSeek([
            ['choices' => [['message' => ['content' => 'a medias'], 'finish_reason' => 'content_filter']]],
        ]);

        self::assertSame('rechazado', $motor->conversar($this->peticion())->motivo);
    }

    // ── Gemini ───────────────────────────────────────────────────────────────

    public function testGeminiRegistraCadaVueltaYDevuelveLaFirmaDePensamiento(): void
    {
        $motor = $this->google([
            ['candidates' => [[
                'content' => ['role' => 'model', 'parts' => [[
                    'functionCall' => ['name' => 'consultar_libres', 'args' => []],
                    'thoughtSignature' => 'CiIBVKhc7s',
                ]]],
                'finishReason' => 'STOP',
            ]],
                'usageMetadata' => ['promptTokenCount' => 20635, 'cachedContentTokenCount' => 16384, 'thoughtsTokenCount' => 0, 'candidatesTokenCount' => 33]],
            ['candidates' => [[
                'content' => ['role' => 'model', 'parts' => [['text' => 'Libres: '], ['text' => 'la 3.']]],
                'finishReason' => 'STOP',
            ]],
                'usageMetadata' => ['promptTokenCount' => 20860, 'candidatesTokenCount' => 7]],
        ]);

        $respuesta = $motor->conversar($this->peticion());

        self::assertSame('Libres: la 3.', $respuesta->texto);
        $info = array_values(array_map(
            static fn (array $l): string => $l[1],
            array_filter($this->lineas, static fn (array $l): bool => $l[0] === 'info' && str_starts_with($l[1], 'Agent (google)')),
        ));
        self::assertCount(2, $info);
        self::assertMatchesRegularExpression('/^Agent \(google\): gemini-3-flash · vuelta 1 · \d+\.\d s · entrada 20635 · cacheado 16384 · pensamiento 0 · salida 33 tokens\.$/', $info[0]);
        self::assertMatchesRegularExpression('/^Agent \(google\): gemini-3-flash · vuelta 2 · \d+\.\d s · entrada 20860 · cacheado 0 · pensamiento 0 · salida 7 tokens\.$/', $info[1]);

        // El turno del modelo vuelve con su firma y con `args` como OBJETO: `[]` sería una lista y
        // la API tumbaría el turno (ver GoogleAIEngine::devolverParte()).
        $cuerpo = $this->crudos[1];
        self::assertStringContainsString('"thoughtSignature":"CiIBVKhc7s"', $cuerpo);
        self::assertStringContainsString('"args":{}', $cuerpo);
        self::assertSame([[]], $this->recibido);
    }

    /** El aviso de truncado distingue «0» de «no lo dijo», que es lo que orienta el arreglo. */
    public function testGeminiTruncadoDiceEnQueSeFueElPresupuesto(): void
    {
        $motor = $this->google([
            ['candidates' => [['content' => ['parts' => [['text' => '{"tipo":"peticion","skill']]], 'finishReason' => 'MAX_TOKENS']],
                'usageMetadata' => ['promptTokenCount' => 912, 'thoughtsTokenCount' => 488]],
        ]);

        self::assertNull($motor->turnoDirecto($this->peticion(), ['type' => 'object']));

        $avisos = array_filter($this->lineas, static fn (array $l): bool => $l[0] === 'warning');
        self::assertCount(1, $avisos);
        self::assertStringContainsString('(pensamiento: 488, salida: ?)', array_values($avisos)[0][1]);
    }

    public function testGeminiPreguntaBloqueada(): void
    {
        $motor = $this->google([
            ['promptFeedback' => ['blockReason' => 'PROHIBITED_CONTENT'], 'usageMetadata' => ['promptTokenCount' => 14]],
        ]);

        self::assertSame('rechazado', $motor->conversar($this->peticion())->motivo);
        self::assertContains(['warning', 'Agent (google): petición declinada por los filtros para doble (PROHIBITED_CONTENT).'], $this->lineas);
    }

    // ── Montaje ──────────────────────────────────────────────────────────────

    /** @param list<array<mixed>> $respuestas */
    private function deepSeek(array $respuestas): DeepSeekEngine
    {
        return new DeepSeekEngine(
            new DeepSeekClient($this->http($respuestas), 'clave-de-prueba', 'deepseek-v4-flash'),
            $this->registro(),
            new DeepSeekSkillAdapter($this->logger(), new GuardiaDeSkills()),
            $this->logger(),
        );
    }

    /** @param list<array<mixed>> $respuestas */
    private function google(array $respuestas): GoogleAIEngine
    {
        return new GoogleAIEngine(
            new GoogleAIClient($this->http($respuestas), 'clave-de-prueba', 'gemini-3-flash'),
            $this->registro(),
            new GoogleAISkillAdapter($this->logger(), new GuardiaDeSkills()),
            $this->logger(),
        );
    }

    /** @param list<array<mixed>> $respuestas */
    private function http(array $respuestas): MockHttpClient
    {
        return new MockHttpClient(function (string $metodo, string $url, array $opciones) use (&$respuestas): MockResponse {
            $crudo = is_string($opciones['body'] ?? null) ? $opciones['body'] : '';
            $cuerpo = json_decode($crudo, true);
            $this->crudos[] = $crudo;
            $this->enviados[] = is_array($cuerpo) ? $cuerpo : [];

            return new MockResponse(json_encode(array_shift($respuestas) ?? []) ?: '{}');
        });
    }

    private function logger(): AbstractLogger
    {
        $lineas = &$this->lineas;

        return new class ($lineas) extends AbstractLogger {
            /** @param list<array{string, string}> $lineas */
            public function __construct(private array &$lineas) {}

            /** @param array<mixed> $context */
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lineas[] = [is_string($level) ? $level : '?', (string) $message];
            }
        };
    }

    private function registro(): SkillRegistry
    {
        $recibido = &$this->recibido;

        return new SkillRegistry([new class ($recibido) implements SkillInterface {
            /** @param list<array<string, mixed>> $recibido */
            public function __construct(private array &$recibido) {}

            public function nombre(): string { return 'consultar_libres'; }
            public function definicion(): SkillDefinition { return new SkillDefinition(descripcion: 'Casitas libres.'); }
            public function rolesRequeridos(): array { return []; }
            public function nivelRiesgo(): NivelRiesgo { return NivelRiesgo::Lectura; }

            public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
            {
                $this->recibido[] = $entrada;

                return SkillResult::ok(['libres' => ['Casita 3']]);
            }
        }]);
    }

    private function peticion(): ConversationRequest
    {
        return new ConversationRequest(
            actor: new class implements ActorInterface {
                public function roles(): array { return []; }
                public function origen(): string { return 'test'; }
                public function contextoTipo(): ?string { return null; }
                public function contextoId(): ?string { return null; }
                public function conversacionId(): ?string { return null; }
                public function vinculo(): VinculoComercial { return VinculoComercial::Ninguno; }
                public function restriccion(): RestriccionCanal { return RestriccionCanal::Ninguna; }
                public function dominios(): array { return []; }
                public function esDelEquipo(): bool { return true; }
                public function esProspecto(): bool { return false; }
                public function usuario(): ?User { return null; }
                public function etiqueta(): string { return 'doble'; }
                public function tieneRol(string $rol): bool { return false; }
                public function tieneAlguno(array $roles): bool { return $roles === []; }
            },
            systemPrompt: 'Eres el asistente.',
            mensaje: '¿Qué casitas hay libres?',
            permitirEscritura: false,
            maxTokens: 500,
        );
    }
}
