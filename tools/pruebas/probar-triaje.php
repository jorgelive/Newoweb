<?php

declare(strict_types=1);

/**
 * Comprueba SIN API las dos piezas frágiles del triaje:
 *
 * 1. `Triaje::interpretar()` — qué hace con lo que devuelva el modelo, incluido lo que no
 *    debería devolver (skills inventadas, pistas que son el mensaje entero, JSON envuelto).
 * 2. `GoogleAIEngine::esquemaGemini()` — la traducción del JSON Schema al subconjunto que
 *    Gemini admite. Si esto sale mal, Gemini contesta con un 400 y no con un aviso.
 *
 * Uso: php var/probar-triaje.php
 */

use App\Agent\Provider\Google\GoogleAIEngine;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Triage\DecisionDeTriaje;
use App\Agent\Triage\TipoDeMensaje;
use App\Agent\Triage\Triaje;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$fallos = 0;
$ok = static function (string $caso, bool $bien, string $detalle = '') use (&$fallos): void {
    if (!$bien) {
        $fallos++;
    }
    printf("%s  %s%s\n", $bien ? '  ok ' : 'FALLA', $caso, $detalle !== '' ? "  — {$detalle}" : '');
};

// ── Skills de mentira, sólo con nombre ──────────────────────────────────────────────────
$skill = static fn (string $nombre): SkillInterface => new class ($nombre) implements SkillInterface {
    public function __construct(private string $n) {}
    public function nombre(): string { return $this->n; }
    public function definicion(): SkillDefinition { return new SkillDefinition('x'); }
    public function rolesRequeridos(): array { return []; }
    public function nivelRiesgo(): App\Agent\Access\NivelRiesgo { return App\Agent\Access\NivelRiesgo::Lectura; }
    public function ejecutar(array $entrada, App\Agent\Access\ActorInterface $actor): App\Agent\Skill\SkillResult
    {
        return App\Agent\Skill\SkillResult::ok([]);
    }
};

$skills = [$skill('consultar_guia'), $skill('escalar_al_equipo')];

$triaje = (new ReflectionClass(Triaje::class))->newInstanceWithoutConstructor();
// `interpretar()` avisa por el log cuando descarta una skill inventada, así que hace falta uno.
(new ReflectionProperty($triaje, 'logger'))->setValue($triaje, new Psr\Log\NullLogger());
$interpretar = new ReflectionMethod($triaje, 'interpretar');

/** @return DecisionDeTriaje */
$leer = static fn (?string $crudo) => $interpretar->invoke($triaje, $crudo, $skills);

// ── 1. Lo normal ────────────────────────────────────────────────────────────────────────
$d = $leer('{"tipo":"peticion","skill":"consultar_guia","pista":"ducha","motivo":"pregunta por el agua"}');
$ok('petición con skill y pista', $d->tipo === TipoDeMensaje::Peticion && $d->skill === 'consultar_guia' && $d->pista === 'ducha');

$d = $leer('{"tipo":"conversacion","skill":"","pista":"","motivo":"saluda"}');
$ok('conversación sin skill', $d->tipo === TipoDeMensaje::Conversacion && $d->skill === null && $d->pista === null);

$d = $leer('{"tipo":"conversacion","skill":"","pista":"","motivo":"saluda","respuesta":"¡Hola! Bienvenidos."}');
$ok('conversación trae su respuesta', $d->respuesta === '¡Hola! Bienvenidos.');

$d = $leer('{"tipo":"conversacion","skill":"","pista":"","motivo":"saluda","respuesta":""}');
$ok('conversación sin respuesta queda en null (→ camino largo)', $d->respuesta === null);

$d = $leer('{"tipo":"peticion","skill":"consultar_guia","pista":"","motivo":"x","respuesta":"La ducha va así…"}');
$ok('respuesta con tipo peticion se tira', $d->respuesta === null, 'contestar sin herramientas no es su trabajo');

// ── 2b. El tema de la guía se valida contra la casita ───────────────────────────────────
/** @return DecisionDeTriaje */
$leerConTemas = static fn (?string $crudo, array $temas) => $interpretar->invoke($triaje, $crudo, $skills, $temas);

$d = $leerConTemas('{"tipo":"peticion","skill":"consultar_guia","tema_id":"uuid-ducha","pista":"ducha","motivo":"x"}', ['uuid-ducha', 'uuid-wifi']);
$ok('tema_id de su casita se conserva', $d->temaId === 'uuid-ducha');

$d = $leerConTemas('{"tipo":"peticion","skill":"consultar_guia","tema_id":"uuid-de-otra-casita","pista":"ducha","motivo":"x"}', ['uuid-ducha']);
$ok('tema_id de otra casita se descarta (queda la pista)', $d->temaId === null && $d->pista === 'ducha');

$d = $leer('{"tipo":"peticion","skill":"consultar_guia","tema_id":"uuid-cualquiera","pista":"","motivo":"x"}');
$ok('tema_id sin casita resuelta se descarta', $d->temaId === null, 'sin unidades no hay temas permitidos');

$d = $leer('{"tipo":"emergencia","skill":"","pista":"","motivo":"huele a gas"}');
$ok('emergencia', $d->tipo === TipoDeMensaje::Emergencia);

// ── 2. Lo que el modelo NO debería hacer y hará igual ────────────────────────────────────
$d = $leer('{"tipo":"peticion","skill":"consultar_el_futuro","pista":"","motivo":"x"}');
$ok('skill inventada se descarta', $d->tipo === TipoDeMensaje::Peticion && $d->skill === null, 'skill=' . var_export($d->skill, true));

$d = $leer('{"tipo":"peticion","skill":"consultar_guia","pista":"la ducha del hotel anterior no iba pero esta va bien","motivo":"x"}');
$ok('pista demasiado larga se descarta', $d->pista === null, 'pista=' . var_export($d->pista, true));

$d = $leer("```json\n{\"tipo\":\"conversacion\",\"skill\":\"\",\"pista\":\"\",\"motivo\":\"hola\"}\n```");
$ok('JSON envuelto en backticks', $d->tipo === TipoDeMensaje::Conversacion);

// ── 3. Lo que tiene que acabar en «indeterminado» ────────────────────────────────────────
foreach ([
    'null (motor sin respuesta)' => null,
    'texto que no es JSON' => 'Pues mira, parece una pregunta.',
    'tipo desconocido' => '{"tipo":"queja","skill":"","pista":"","motivo":"x"}',
    'el modelo eligió «indeterminado»' => '{"tipo":"indeterminado","skill":"","pista":"","motivo":"x"}',
] as $caso => $crudo) {
    $ok("indeterminado: {$caso}", $leer($crudo)->tipo === TipoDeMensaje::Indeterminado);
}

// ── 4. El esquema traducido a Gemini ─────────────────────────────────────────────────────
$motor = (new ReflectionClass(GoogleAIEngine::class))->newInstanceWithoutConstructor();
$traducir = new ReflectionMethod($motor, 'esquemaGemini');

$fuente = [
    'type' => 'object',
    'properties' => [
        'tipo' => ['type' => 'string', 'enum' => ['a', 'b']],
        'skill' => ['type' => ['string', 'null'], 'description' => 'opcional'],
    ],
    'required' => ['tipo', 'skill'],
    'additionalProperties' => false,
];

$g = $traducir->invoke($motor, $fuente);

$ok('esquema Gemini: sin additionalProperties', !array_key_exists('additionalProperties', $g));
$ok('esquema Gemini: type en lista → escalar', $g['properties']['skill']['type'] === 'string');
$ok('esquema Gemini: nullable marcado', ($g['properties']['skill']['nullable'] ?? false) === true);
$ok('esquema Gemini: el opcional sale de required', $g['required'] === ['tipo']);
$ok('esquema Gemini: el enum se respeta', $g['properties']['tipo']['enum'] === ['a', 'b']);

// ── 5. El selector de potencia contra el entorno REAL ────────────────────────────────────
//
// No llama a ninguna API: `estaDisponible()` sólo mira si hay clave. Lo que se comprueba es la
// degradación, que es la parte que no se puede ver leyendo el código: con los tramos apuntando
// a un proveedor sin credenciales, ¿sigue habiendo quien conteste?
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$_SERVER['APP_ENV'] = 'dev';
$kernel = new App\Kernel('dev', true);
$kernel->boot();

$app = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
$comando = $app->find('app:agent:preguntar');
if ($comando instanceof Symfony\Component\Console\Command\LazyCommand) {
    $comando = $comando->getCommand();
}

$asistente = (new ReflectionProperty($comando, 'asistente'))->getValue($comando);
$registro = (new ReflectionProperty($asistente, 'motores'))->getValue($asistente);

$selector = new App\Agent\Conversation\SelectorDePotencia(
    $registro,
    new Psr\Log\NullLogger(),
    alta: getenv('AGENT_IA_POTENCIA_ALTA') ?: ($_ENV['AGENT_IA_POTENCIA_ALTA'] ?? ''),
    media: 'proveedor-que-no-existe:modelo-fantasma',
    baja: '',
);

printf("\n--- selector de potencia, con el .env de este entorno ---\n");
foreach (App\Agent\Conversation\PotenciaRequerida::cases() as $potencia) {
    $elegido = $selector->elegir($potencia);
    printf("  %-6s → %s\n", $potencia->value, $elegido?->etiqueta() ?? '(ningún motor disponible)');
}

$ok(
    'un tramo mal configurado no deja sin motor',
    $registro->hayDisponible() ? $selector->elegir(App\Agent\Conversation\PotenciaRequerida::Media) !== null : true,
    'media apuntaba a un proveedor inexistente'
);

// ── 6. El índice de la guía contra la BD REAL ────────────────────────────────────────────
//
// Lo que importa del índice no es el contenido: es que sea DETERMINISTA (dos construcciones
// seguidas, byte a byte iguales, o el caché de Anthropic no acierta nunca) y que el mapa por
// unidad cuadre con el bloque.
$em = $kernel->getContainer()->get('doctrine')->getManager();
// ⚠️ `IndiceDeGuia` desapareció al hacerse el índice POR DOMINIO: ahora cada uno aporta el
// suyo por `IndiceDeTemasInterface` y el del PMS es `PmsIndiceDeTemas`, que se pide al
// contenedor porque tiene dependencias. Lo que se comprueba no cambia: que el bloque sea
// DETERMINISTA entre dos construcciones, o el caché de Anthropic no acierta nunca.
$cargador = new ReflectionMethod($kernel->getContainer(), 'load');
$cargador->setAccessible(true);
$indice = $cargador->invoke($kernel->getContainer(), 'getPmsIndiceDeTemasService');

$a = $indice->bloqueParaElPrompt();
$em->clear(); // sin la caché de entidades de la primera pasada, que escondería un orden inestable
$b = $indice->bloqueParaElPrompt();

$ok('índice de guía: determinista', $a === $b, 'dos construcciones idénticas');
$ok('índice de guía: no vacío en esta BD', $a !== '');

// ⚠️ Aquí había una tercera comprobación —«el bloque y el mapa por unidad cuadran»— y se retiró
// con la API que la sostenía: el índice pasó a ser POR DOMINIO (`IndiceDeTemasInterface`) y
// `PmsIndiceDeTemas` devuelve el bloque como texto; el mapa por unidad ya no se expone, y lo que
// acota los temas ahora es `temasPermitidos($actor)`. Reescribir la comprobación contra ese
// método es otra prueba, no ésta.

preg_match_all('/^- (\S+) — /mu', $a, $m);

printf(
    "\n--- índice de guía ---\n  %d temas · %d chars ≈ %d tokens\n",
    count($m[1]),
    mb_strlen($a),
    (int) round(mb_strlen($a) / 3.6)
);

printf("\n%s\n", $fallos === 0 ? '✅ Todo bien.' : "❌ {$fallos} comprobación(es) fallidas.");
exit($fallos === 0 ? 0 : 1);
