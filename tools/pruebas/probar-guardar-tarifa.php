<?php
declare(strict_types=1);
/**
 * `guardar_tarifa` con datos REALES, en transacción con ROLLBACK.
 *
 * Lo que se comprueba es lo que un unitario no puede: que confirmado=false NO escriba, que
 * confirmado=true sí, y que las listas cerradas rechacen lo inventado.
 */
use App\Agent\Access\AgentActor;
use App\Agent\Skill\Travel\CrearTarifaSkill;
use App\Agent\Skill\Travel\ModificarTarifaSkill;
use App\Agent\Skill\Travel\TarifaDesdeEntrada;
use App\Entity\User;
use App\Kernel;
use App\Security\Roles;
use App\Travel\Entity\TravelTarifa;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';
(new Dotenv())->bootEnv(__DIR__ . '/../.env');
$k = new Kernel('dev', false);
$k->boot();
$em = $k->getContainer()->get('doctrine')->getManager();
$tde = new TarifaDesdeEntrada($em);
$crear = new CrearTarifaSkill($em, $tde);
$modif = new ModificarTarifaSkill($em, $tde);

$u = (new User())->setEmail('op@ejemplo');
$u->setRoles([Roles::OPERACIONES_WRITE]);
$actor = AgentActor::delPanel($u);

$conn = $em->getConnection();
$conn->beginTransaction();
$antes = (int) $conn->fetchOne('SELECT COUNT(*) FROM travel_tarifa');

try {
    echo "tarifas al empezar: $antes\n\n";

    $r = $crear->ejecutar(['componente' => 'Almuerzo en Machu', 'nombre' => 'Prueba agente',
        'precio' => 42.5, 'moneda' => 'USD', 'procedencia' => 'extranjero',
        'edad_minima' => 12, 'confirmado' => false], $actor);
    printf("1. previsualizar → %s | tarifas ahora: %d\n", $r->datos['accion'] ?? $r->error,
        (int) $conn->fetchOne('SELECT COUNT(*) FROM travel_tarifa'));

    $r = $crear->ejecutar(['componente' => 'Almuerzo en Machu', 'nombre' => 'Prueba agente',
        'precio' => 42.5, 'moneda' => 'USD', 'procedencia' => 'marciano', 'confirmado' => true], $actor);
    printf("2. procedencia inventada → %s\n", mb_substr($r->error ?? 'SIN ERROR (¡mal!)', 0, 74));

    $r = $crear->ejecutar(['componente' => 'Almuerzo en Machu', 'nombre' => 'Prueba agente',
        'precio' => 10, 'edad_minima' => 30, 'edad_maxima' => 5, 'confirmado' => true], $actor);
    printf("3. rango al revés → %s\n", mb_substr($r->error ?? 'SIN ERROR (¡mal!)', 0, 74));

    $r = $crear->ejecutar(['componente' => 'Almuerzo en Machu', 'nombre' => 'Prueba agente',
        'precio' => 42.5, 'moneda' => 'USD', 'procedencia' => 'extranjero',
        'edad_minima' => 12, 'confirmado' => true], $actor);
    $nueva = $r->datos['tarifa']['tarifa_id'] ?? null;
    printf("4. confirmar → %s | tarifas ahora: %d\n", $r->datos['accion'] ?? '?',
        (int) $conn->fetchOne('SELECT COUNT(*) FROM travel_tarifa'));

    $r = $modif->ejecutar(['tarifa_id' => $nueva, 'precio' => 55, 'confirmado' => true], $actor);
    $t = $em->find(TravelTarifa::class, \Symfony\Component\Uid\Uuid::fromString($nueva));
    printf("5. modificar precio → %s | precio: %s | procedencia sigue: %s\n",
        $r->datos['accion'] ?? '?', $t?->getMonto(), $t?->getProcedencia()?->value);

    // 6. LA SUTIL: previsualizar una modificación NO puede colarse si algo flushea después.
    //    La entidad está gestionada y ya lleva el cambio en memoria; sin el refresh() del
    //    skill, este flush lo escribiría sin que nadie lo aprobara.
    $modif->ejecutar(['tarifa_id' => $nueva, 'precio' => 999, 'confirmado' => false], $actor);
    $em->flush();
    $em->clear();
    $t = $em->find(TravelTarifa::class, \Symfony\Component\Uid\Uuid::fromString($nueva));
    printf("6. previsualizar y flushear → precio en base: %s %s\n", $t?->getMonto(),
        $t?->getMonto() === '55.00' ? '✅ no se coló' : '❌ SE COLÓ');
} finally {
    $conn->rollBack();
    printf("\n↩️  rollback: tarifas %d (eran %d)\n",
        (int) $conn->fetchOne('SELECT COUNT(*) FROM travel_tarifa'), $antes);
}
