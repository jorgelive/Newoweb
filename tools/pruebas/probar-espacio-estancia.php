<?php

declare(strict_types=1);

/**
 * Qué ve el agente alrededor de una estancia, con DATOS REALES. Sólo lee.
 *
 * Nació del caso MMQSB2: la noche extra, cargada como segundo evento de la misma reserva,
 * aparecía como «otro huésped que entra el día que se va».
 *
 *   php tools/pruebas/probar-espacio-estancia.php MMQSB2 [OTRO_LOCALIZADOR…]
 */

use App\Kernel;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Service\Reserva\PmsEspacioEstancia;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', false);
$kernel->boot();
/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
$espacio = new PmsEspacioEstancia($em);

foreach (array_slice($argv, 1) as $localizador) {
    $evento = $em->getRepository(PmsEventoCalendario::class)->findOneBy(['localizador' => $localizador]);
    $reserva = $evento?->getReserva();

    if ($reserva === null) {
        printf("%s: no existe\n", $localizador);
        continue;
    }

    printf("%s (%s):\n", $localizador, $evento->getPmsUnidad()?->getNombre() ?? '—');
    foreach ($reserva->getEventosCalendario() as $e) {
        printf("   evento %s  %s → %s  %s\n", $e->getLocalizador(), $e->getInicio()?->format('d/m H:i'), $e->getFin()?->format('d/m H:i'), $e->getPmsUnidad()?->getNombre());
    }
    print_r($espacio->alrededorDe($reserva));
}
