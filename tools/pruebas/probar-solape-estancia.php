<?php

declare(strict_types=1);

/**
 * Comprueba con DATOS REALES qué frena el candado de solape (PmsEventoCalendarioSolapeListener).
 *
 * Sólo llama a `persist()` —que es donde corre `prePersist`— y nunca a `flush()`: no se guarda
 * nada ni sale nada hacia Beds24. Después, `clear()` y `rollback`.
 *
 *   php tools/pruebas/probar-solape-estancia.php "Casita 4" 2026-09-27 2026-09-28
 *
 * Prueba también la confirmada con `solapeAceptado` (la que guarda el editor con el aviso a la
 * vista, 20/10/2026): tiene que dejarse crear donde la otra frena.
 */

use App\Kernel;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsUnidad;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', false);
$kernel->boot();
/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
$em->getConnection()->beginTransaction();

[, $casita, $desde, $hasta] = $argv + [null, 'Casita 4', '2026-09-27', '2026-09-28'];
$unidad = $em->getRepository(PmsUnidad::class)->findOneBy(['nombre' => $casita]);

foreach ([
    [PmsEventoEstado::CODIGO_CONFIRMADA, false],
    [PmsEventoEstado::CODIGO_BLOQUEO, false],
    [PmsEventoEstado::CODIGO_CONFIRMADA, true],
] as [$estado, $aceptado]) {
    $evento = (new PmsEventoCalendario())
        ->setPmsUnidad($unidad)
        ->setEstado($em->getReference(PmsEventoEstado::class, $estado))
        ->setInicio(new DateTimeImmutable("$desde 14:00"))
        ->setFin(new DateTimeImmutable("$hasta 10:00"))
        ->setSolapeAceptado($aceptado);
    $etiqueta = $estado . ($aceptado ? ' aceptada' : '');

    try {
        $em->persist($evento);
        printf("  %-20s %s %s→%s: se deja crear\n", $etiqueta, $casita, $desde, $hasta);
    } catch (DomainException $e) {
        printf("  %-20s %s %s→%s: FRENADO — %s\n", $etiqueta, $casita, $desde, $hasta, $e->getMessage());
    }

    $em->clear();
}

$em->getConnection()->rollBack();
