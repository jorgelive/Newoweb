<?php

declare(strict_types=1);

/**
 * ¿El desplegable «Servicio del prestador» enseña SÓLO los del prestador elegido?
 *
 * El fallo que motiva esta sonda no daba error ni lista vacía: daba **de más**. La extensión
 * que filtraba leía el parámetro `organización_id` —con tilde, cortesía del renombrado de
 * `proveedor`— y el front manda `organizacion`, así que no entró ni una vez en doce días.
 * API Platform ignora un parámetro que no reconoce y devuelve la colección entera, de modo
 * que bajo un prestador de Punta Cana salían las habitaciones del Tambo del Inka.
 *
 * Se comprueban las tres respuestas posibles, porque las tres se parecen en pantalla:
 *
 *   · sin filtro   → el catálogo entero   (el fallo de más: lo que pasaba)
 *   · mal atado    → cero                 (el fallo de menos: `SearchFilter` con uuid)
 *   · bien         → los de esa empresa
 *
 * Uso: php var/probar-filtro-servicios-organizacion.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use App\Api\Filter\UuidRelacionFilter;
use App\Travel\Entity\TravelOrganizacionServicio;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$_SERVER['APP_ENV'] = 'dev';
$kernel = new App\Kernel('dev', true);
$kernel->boot();

/** @var ManagerRegistry $registry */
$registry = $kernel->getContainer()->get('doctrine');
/** @var EntityManagerInterface $em */
$em = $registry->getManager();

$total = (int) $em->createQuery('SELECT COUNT(s) FROM ' . TravelOrganizacionServicio::class . ' s')->getSingleScalarResult();

// La organización con más servicios: la que mejor delata un filtro que no filtra.
$fila = $em->getConnection()->fetchAssociative(
    'SELECT o.id, o.nombre_comercial AS nombre, COUNT(s.id) AS n
       FROM travel_organizacion o
       JOIN travel_organizacion_servicio s ON s.organizacion_id = o.id
      GROUP BY o.id, o.nombre_comercial
      ORDER BY n DESC
      LIMIT 1'
);

if ($fila === false) {
    echo "No hay ninguna organización con servicios: nada que comprobar.\n";
    exit(0);
}

$id = strtolower((string) Symfony\Component\Uid\Uuid::fromBinary((string) $fila['id']));
$esperados = (int) $fila['n'];

printf("Catálogo: %d servicios en total\n", $total);
printf("Prestador de prueba: %s (%d servicios suyos)\n\n", (string) $fila['nombre'], $esperados);

$filtro = new UuidRelacionFilter($registry);

/** Cuenta lo que devolvería la colección con estos filtros de query. */
$contar = static function (array $filtros) use ($em, $filtro): int {
    $qb = $em->createQueryBuilder()
        ->select('s')
        ->from(TravelOrganizacionServicio::class, 's');

    $filtro->apply($qb, new QueryNameGenerator(), TravelOrganizacionServicio::class, null, ['filters' => $filtros]);

    return count($qb->getQuery()->getResult());
};

$casos = [
    ['que' => 'sin parámetro (el fallo de MÁS)', 'filtros' => [],                             'espera' => $total],
    ['que' => 'con el nombre mal escrito',       'filtros' => ['organización' => $id],        'espera' => $total],
    ['que' => 'organizacion=<uuid>',             'filtros' => ['organizacion' => $id],        'espera' => $esperados],
    ['que' => 'organizacion=<IRI>',              'filtros' => ['organizacion' => '/platform/travel/organizaciones/' . $id], 'espera' => $esperados],
    ['que' => 'organizacion=basura',             'filtros' => ['organizacion' => 'no-es-un-uuid'], 'espera' => 0],
];

foreach ($casos as $caso) {
    $n = $contar($caso['filtros']);
    printf("   %-32s → %-3d (espera %-3d) %s\n", $caso['que'], $n, $caso['espera'], $n === $caso['espera'] ? '✔' : '✘');
}

printf("\nOjo a la segunda línea: un nombre mal escrito NO da error, devuelve el catálogo entero.\n");
printf("Por eso el filtro va declarado —sale en `api.d.ts`— y no en una extensión a mano.\n");
