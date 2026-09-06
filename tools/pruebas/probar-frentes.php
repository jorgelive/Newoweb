<?php

declare(strict_types=1);

/**
 * Enseña los FRENTES que vería el triaje para un teléfono real. Sólo lee.
 *
 * Comprueba lo que ni `php -l` ni los tests unitarios pueden: que las etiquetas salgan legibles
 * con datos de verdad, que el desempate del PMS se respete, y que el bloque del prompt no
 * arrastre ningún identificador interno.
 *
 * Uso: php var/probar-frentes.php [telefono]
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Contract\Frente;
use App\Message\Service\EnumeradorDeFrentes;
use App\Pms\Entity\PmsReserva;
use App\Pms\Repository\PmsReservaRepository;
use App\Pms\Service\Agent\PmsFrentes;
use App\Service\Phone\PhoneSanitizer;
use Doctrine\ORM\EntityManagerInterface;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$_SERVER['APP_ENV'] = 'dev';
$kernel = new App\Kernel('dev', true);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
/** @var PmsReservaRepository $reservas */
$reservas = $em->getRepository(PmsReserva::class);

$enumerador = new EnumeradorDeFrentes([new PmsFrentes($reservas, new PhoneSanitizer())]);

// Sin argumento, se busca un teléfono real que tenga algo vivo: probar con uno inventado sólo
// enseñaría las puertas de venta, que es justo la mitad aburrida.
$telefono = $argv[1] ?? $em->getConnection()->fetchOne(
    'SELECT telefono FROM pms_reserva WHERE telefono IS NOT NULL AND telefono <> "" ORDER BY fecha_llegada DESC LIMIT 1'
);

printf("\n=== FRENTES para %s ===\n\n", $telefono ?: '(nadie)');

$frentes = $enumerador->paraTelefono($telefono !== false ? (string) $telefono : null);

foreach ($frentes as $frente) {
    printf(
        "  %-9s %-10s %-10s %s%s\n",
        $frente->id(),
        $frente->negocio,
        $frente->momento->value,
        $frente->etiqueta,
        $frente->porDefecto ? '  ← por defecto' : ''
    );
}

printf("\n=== Lo que vería el modelo (bloque volátil) ===\n\n%s\n", $enumerador->bloqueParaElPrompt($frentes));

// El bloque no puede llevar el uuid de ninguna entidad: es lo único que se le enseña al modelo.
$fugas = array_filter(
    $frentes,
    fn (Frente $f): bool => $f->entidadId !== null
        && str_contains($enumerador->bloqueParaElPrompt($frentes), $f->entidadId)
);

printf(
    "\n%s\n",
    $fugas === []
        ? '✅ Ningún identificador de entidad viaja en el bloque.'
        : '❌ FUGA: el bloque lleva el id de una entidad.'
);

printf(
    "✅ Ids estables: %s\n",
    $enumerador->paraTelefono($telefono !== false ? (string) $telefono : null)[0]->id() === ($frentes[0]->id() ?? '')
        ? 'sí'
        : 'NO'
);
