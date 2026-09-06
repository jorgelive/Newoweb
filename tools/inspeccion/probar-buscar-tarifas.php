<?php
declare(strict_types=1);
use App\Agent\Access\AgentActor;
use App\Agent\Skill\Travel\BuscarTarifasSkill;
use App\Entity\User;
use App\Kernel;
use App\Security\Roles;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../../vendor/autoload.php';
(new Dotenv())->bootEnv(__DIR__ . '/../../.env');
$k = new Kernel('dev', false);
$k->boot();
$skill = $k->getContainer()->get('doctrine')->getManager();

$ref = new ReflectionClass(BuscarTarifasSkill::class);
$s = $ref->newInstance($skill);

$u = (new User())->setEmail('op@ejemplo');
$u->setRoles([Roles::OPERACIONES_SHOW]);
$actor = AgentActor::delPanel($u);

foreach (['machu', 'tour', 'zzz'] as $q) {
    $r = $s->ejecutar(['busqueda' => $q], $actor);
    $d = $r->datos ?? [];
    printf("«%s» → %d componente(s)\n", $q, count($d['componentes'] ?? []));
    foreach (array_slice($d['componentes'] ?? [], 0, 1) as $c) {
        printf("   %s · prestador: %s · %d tarifa(s)\n", $c['componente'] ?? '?', $c['prestador'] ?? '—', count($c['tarifas'] ?? []));
        foreach (array_slice($c['tarifas'] ?? [], 0, 2) as $t) {
            printf("     - %s %s | proc: %s | edades: %s | cap: %s\n",
                $t['precio'] ?? '?', $t['moneda'] ?? '', $t['procedencia'] ?? '?', $t['edades'] ?? '?', $t['capacidad'] ?? '?');
        }
    }
}
