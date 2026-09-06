<?php
declare(strict_types=1);
use App\Agent\Access\AgentActor;
use App\Agent\Skill\Travel\BuscarComponentesSkill;
use App\Entity\User;
use App\Kernel;
use App\Security\Roles;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';
(new Dotenv())->bootEnv(__DIR__ . '/../.env');
$k = new Kernel('dev', false); $k->boot();
$em = $k->getContainer()->get('doctrine')->getManager();
$s = (new ReflectionClass(BuscarComponentesSkill::class))->newInstance($em);
$u = (new User())->setEmail('op@x'); $u->setRoles([Roles::OPERACIONES_SHOW]);
$a = AgentActor::delPanel($u);

$casos = [
    ['busqueda' => 'puno'],
    ['busqueda' => 'machu'],
    ['tipo' => 'tren'],
    ['busqueda' => 'valle'],
    ['tipo' => 'inventado'],
    ['busqueda' => 'zzzz'],
];
foreach ($casos as $c) {
    $r = $s->ejecutar($c, $a);
    $etiqueta = json_encode($c, JSON_UNESCAPED_UNICODE);
    if ($r->esError()) { printf("%-28s ⛔ %s\n", $etiqueta, mb_substr($r->error, 0, 60)); continue; }
    $comps = $r->datos['componentes'] ?? [];
    printf("%-28s → %d\n", $etiqueta, count($comps));
    foreach (array_slice($comps, 0, 2) as $x) {
        printf("     %-46s %-14s %d tarifa(s)%s\n",
            mb_substr($x['nombre'] ?? '?', 0, 46), $x['tipo'] ?? '?', $x['tarifas'] ?? 0,
            isset($x['lugares']) ? ' · ' . mb_substr($x['lugares'], 0, 26) : '');
    }
}

// ── La cadena completa: localizar → precios por id ──────────────────────────
$tarifas = (new ReflectionClass(App\Agent\Skill\Travel\BuscarTarifasSkill::class))->newInstance($em);
$r = $s->ejecutar(['busqueda' => 'puno', 'tipo' => 'transporte'], $a);
$elegido = ($r->datos['componentes'] ?? [])[0] ?? null;

if ($elegido) {
    printf("\n→ elegido: %s (%s)\n", $elegido['nombre'], $elegido['componente_id']);
    $t = $tarifas->ejecutar(['componente_id' => $elegido['componente_id']], $a);
    $comp = ($t->datos['componentes'] ?? [])[0] ?? null;
    printf("   por id → %s · %d tarifa(s)\n", $comp['componente'] ?? '?', count($comp['tarifas'] ?? []));
    foreach (array_slice($comp['tarifas'] ?? [], 0, 3) as $x) {
        printf("     %-34s %s %s | %s\n", mb_substr($x['nombre'] ?? '?', 0, 34),
            $x['precio'], $x['moneda'] ?? '', $x['capacidad'] ?? '?');
    }
    $malo = $tarifas->ejecutar(['componente_id' => 'no-es-un-uuid'], $a);
    printf("   id inválido → %d componente(s)\n", count($malo->datos['componentes'] ?? []));
}
