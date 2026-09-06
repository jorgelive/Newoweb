<?php

declare(strict_types=1);

/**
 * ¿El filtro por dominio recorta lo que tiene que recortar, y NADA más?
 *
 * `app:agent:permisos` no sirve para esto: construye los actores con `AgentActor::` a secas,
 * sin dominios, así que nunca ejerce el filtro. Aquí se arman por la FACTORÍA, que es como
 * nacen en producción.
 *
 * Lo que se comprueba:
 *   1. Un huésped y un prospecto de hoy conservan exactamente el catálogo que ya tenían.
 *   2. Un actor de un negocio que no existe todavía (turismo) se queda sólo con lo transversal.
 *      Sin esto, el mecanismo entero podría estar sin efecto y nadie se enteraría.
 *
 * Uso: php var/probar-dominios.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Agent\Access\AgentActor;
use App\Agent\Access\AgentActorFactory;
use App\Agent\Command\AgentPermisosCommand;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillRegistry;
use App\Message\Service\EnumeradorDeFrentes;
use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Agent\PmsFrentes;
use App\Service\Phone\PhoneSanitizer;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$_SERVER['APP_ENV'] = 'dev';
$kernel = new App\Kernel('dev', true);
$kernel->boot();

// El registro de skills es un servicio privado: se saca del comando que ya lo recibe, mismo
// truco que usa var/probar-triaje.php con el asistente.
$app = new Application($kernel);
$comando = $app->find('app:agent:permisos');
if ($comando instanceof LazyCommand) {
    $comando = $comando->getCommand();
}

/** @var SkillRegistry $registro */
$registro = null;
foreach ((new ReflectionClass($comando))->getProperties() as $prop) {
    $valor = $prop->getValue($comando);
    if ($valor instanceof SkillRegistry) {
        $registro = $valor;
        break;
    }
}

if ($registro === null) {
    exit("No se pudo obtener el SkillRegistry de " . AgentPermisosCommand::class . "\n");
}

$em = $kernel->getContainer()->get('doctrine')->getManager();
$enumerador = new EnumeradorDeFrentes([
    new PmsFrentes($em->getRepository(PmsReserva::class), new PhoneSanitizer()),
]);
$factoria = new AgentActorFactory(new RoleHierarchy([]), $enumerador);

$nombres = static fn (array $skills): array => array_map(
    static fn (SkillInterface $s): string => $s->nombre(),
    $skills
);

$huesped = $factoria->huesped('whatsapp_meta', 'pms_reserva', 'ejemplo');
$prospecto = $factoria->prospecto('whatsapp_meta');

// Un pasajero de tours: el negocio todavía no existe, así que se simula a mano. Es justo el
// actor para el que se construyó el filtro.
$pasajero = AgentActor::huesped('whatsapp_meta', 'cotizacion_file', 'ejemplo', null, dominios: ['turistico']);

printf("\n=== Dominios que asigna la factoría ===\n");
printf("  huésped (pms_reserva) : %s\n", implode(', ', $huesped->dominios()));
printf("  prospecto (sin nada)  : %s\n", implode(', ', $prospecto->dominios()));
printf("  pasajero (turismo)    : %s\n", implode(', ', $pasajero->dominios()));

$deHuesped = $nombres($registro->paraActor($huesped));
$deProspecto = $nombres($registro->paraActor($prospecto));
$dePasajero = $nombres($registro->paraActor($pasajero));

printf("\n=== Catálogos ===\n");
printf("  huésped   (%2d): %s\n", count($deHuesped), implode(', ', $deHuesped));
printf("  prospecto (%2d): %s\n", count($deProspecto), implode(', ', $deProspecto));
printf("  pasajero  (%2d): %s\n", count($dePasajero), implode(', ', $dePasajero));

// 1. Nada cambia para quien ya existía: son actores de alojamiento y el alojamiento se vende.
$ok = $deHuesped !== [] && in_array('consultar_mi_reserva', $deHuesped, true);
$ok = $ok && in_array('consultar_disponibilidad', $deProspecto, true);

// 2. El pasajero de tours NO recibe skills de alojamiento, y sí lo transversal.
$fugas = array_intersect($dePasajero, ['consultar_mi_reserva', 'consultar_cuenta', 'consultar_codigos']);
$ok = $ok && $fugas === [];

printf(
    "\n%s\n%s\n",
    $ok
        ? '✅ El huésped y el prospecto conservan su catálogo.'
        : '❌ Se rompió el catálogo de alguien que ya funcionaba.',
    $fugas === []
        ? '✅ Al pasajero de turismo no le llega ninguna skill de alojamiento.'
        : '❌ FUGA de catálogo hacia otro negocio: ' . implode(', ', $fugas)
);

exit($ok ? 0 : 1);
