<?php

declare(strict_types=1);

/**
 * Comprueba el flujo de «quién limpia» de punta a punta contra la BD real, SIN dejar rastro:
 * todo va dentro de una transacción que se deshace al final.
 *
 * Lo que verifica —y que no ven ni `php -l` ni `lint:container`—:
 *
 * 1. La LECTURA (`getLimpieza()`) devuelve `[{id, nombre}]` y no la lista de objetos vacíos
 *    que salía al serializar la colección de `User` tal cual.
 * 2. `limpiezaIds` AUSENTE (null) no toca la asignación. Es lo que impide que un PATCH del
 *    drawer —cambiar el estado, tocar el importe— deje la estancia sin asignar en silencio.
 * 3. `limpiezaIds: []` sí la vacía: es una orden explícita.
 * 4. Un id que no es del equipo de limpieza se rechaza con 400 en vez de aceptarse y no
 *    servir para nada.
 * 5. El desplegable (`/tipo/user/enum/pms/limpiadores`) y el validador del processor miran
 *    la MISMA lista.
 *
 * Uso: php var/probar-limpieza.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Api\Controller\Tipo\PmsEnumAjaxController;
use App\Entity\User;
use App\Pms\ApiPlatform\State\PmsEventoCalendarioProcessor;
use App\Pms\Entity\PmsEventoCalendario;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$_SERVER['APP_ENV'] = 'dev';
$kernel = new App\Kernel('dev', true);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
/** @var UserRepository $usuarios */
$usuarios = $em->getRepository(User::class);

// El processor se arma sin constructor: la fábrica de links Beds24 no interviene en este
// camino (sólo se usa al crear o al mover de unidad) y pedirla obligaría a destapar servicios
// privados del contenedor.
$processor = (new ReflectionClass(PmsEventoCalendarioProcessor::class))->newInstanceWithoutConstructor();
foreach (['entityManager' => $em, 'usuarios' => $usuarios] as $prop => $valor) {
    (new ReflectionProperty(PmsEventoCalendarioProcessor::class, $prop))->setValue($processor, $valor);
}
$aplicar = (new ReflectionMethod(PmsEventoCalendarioProcessor::class, 'aplicarLimpieza'));

$ok = 0;
$fallos = [];
$comprobar = function (string $que, bool $bien, string $detalle = '') use (&$ok, &$fallos): void {
    if ($bien) {
        $ok++;
        printf("  ✅ %s\n", $que);

        return;
    }

    $fallos[] = $que;
    printf("  ❌ %s%s\n", $que, $detalle !== '' ? " — {$detalle}" : '');
};

// ── El equipo de limpieza, tal cual lo ve el desplegable ─────────────────────
$respuesta = (new PmsEnumAjaxController())->getLimpiadores($usuarios);
/** @var list<array{id: string, label: string}> $desplegable */
$desplegable = json_decode((string) $respuesta->getContent(), true, 512, JSON_THROW_ON_ERROR);

printf("\n=== /tipo/user/enum/pms/limpiadores ===\n");
foreach ($desplegable as $fila) {
    printf("  · %s  (%s)\n", $fila['label'], $fila['id']);
}

if ($desplegable === []) {
    exit("\n⚠️  Nadie tiene ROLE_LIMPIEZA en esta base: sin eso no hay nada que probar.\n");
}

// ── Una estancia real sobre la que trabajar ──────────────────────────────────
$evento = $em->createQueryBuilder()
    ->select('e')
    ->from(PmsEventoCalendario::class, 'e')
    ->where('e.reserva IS NOT NULL')
    ->andWhere('e.eventoOrigen IS NULL')
    ->setMaxResults(1)
    ->getQuery()
    ->getOneOrNullResult();

if (!$evento instanceof PmsEventoCalendario) {
    exit("\n⚠️  No hay ninguna estancia con reserva en esta base.\n");
}

printf("\n=== Estancia de pruebas: %s ===\n", (string) $evento);

$em->getConnection()->beginTransaction();

try {
    $unoId = $desplegable[0]['id'];
    $dosId = $desplegable[1]['id'] ?? null;

    // 1. AUSENTE (null) = no se toca nada.
    $evento->setLimpiezaIds([$unoId]);
    $aplicar->invoke($processor, $evento);
    $em->flush();
    $antes = $evento->getLimpieza();

    $evento->setLimpiezaIds(null);
    $aplicar->invoke($processor, $evento);
    $comprobar(
        'limpiezaIds ausente (null) NO toca la asignación',
        $evento->getLimpieza() === $antes,
        json_encode($evento->getLimpieza(), JSON_UNESCAPED_UNICODE)
    );

    // 2. La forma de lectura.
    $primera = $evento->getLimpieza()[0] ?? [];
    $comprobar(
        'getLimpieza() devuelve [{id, nombre}] con el nombre puesto',
        isset($primera['id'], $primera['nombre']) && $primera['nombre'] !== '',
        json_encode($primera, JSON_UNESCAPED_UNICODE)
    );

    // 3. Varias personas a la vez (una casita grande se limpia entre dos).
    if (null !== $dosId) {
        $evento->setLimpiezaIds([$unoId, $dosId]);
        $aplicar->invoke($processor, $evento);
        $em->flush();
        $comprobar('se pueden asignar DOS personas a la misma estancia', count($evento->getLimpieza()) === 2);

        // Y quitar sólo a una.
        $evento->setLimpiezaIds([$dosId]);
        $aplicar->invoke($processor, $evento);
        $em->flush();
        $quedan = array_column($evento->getLimpieza(), 'id');
        $comprobar('quitar a una deja a la otra', $quedan === [$dosId], json_encode($quedan));
    }

    // 4. Lista vacía = orden explícita de dejar la estancia sin asignar.
    $evento->setLimpiezaIds([]);
    $aplicar->invoke($processor, $evento);
    $em->flush();
    $comprobar('limpiezaIds: [] deja la estancia sin asignar', $evento->getLimpieza() === []);

    // 5. Un id de fuera del equipo se rechaza en voz alta.
    // El «de fuera» se elige en PHP y no con un `NOT IN (:uuids)`: los ids son BINARY(16) y
    // esa comparación con UUIDs en texto no casa nada, así que devolvía a alguien que SÍ era
    // del equipo — y la prueba se daba por buena creyendo que el processor no filtraba.
    $delEquipo = array_flip(array_column($desplegable, 'id'));
    $intruso = null;

    foreach ($em->getRepository(User::class)->findAll() as $candidato) {
        if (!isset($delEquipo[(string) $candidato->getId()])) {
            $intruso = $candidato;
            break;
        }
    }

    if ($intruso instanceof User) {
        $evento->setLimpiezaIds([(string) $intruso->getId()]);

        try {
            $aplicar->invoke($processor, $evento);
            $comprobar('un usuario sin ROLE_LIMPIEZA se rechaza', false, 'lo aceptó sin quejarse');
        } catch (BadRequestHttpException $e) {
            $comprobar('un usuario sin ROLE_LIMPIEZA se rechaza con 400: ' . $e->getMessage(), true);
        }
    }

    // 6. La verdad final está en la tabla, no en el objeto en memoria.
    $evento->setLimpiezaIds([$unoId]);
    $aplicar->invoke($processor, $evento);
    $em->flush();
    $filas = (int) $em->getConnection()->fetchOne(
        'SELECT COUNT(*) FROM pms_evento_limpieza WHERE evento_id = UUID_TO_BIN(:e)',
        ['e' => (string) $evento->getId()]
    );
    $comprobar('la asignación llega a pms_evento_limpieza', $filas === 1, "filas={$filas}");
} finally {
    $em->getConnection()->rollBack();
    printf("\n(transacción deshecha: la base queda como estaba)\n");
}

printf("\n%d comprobaciones OK, %d fallos\n", $ok, count($fallos));

exit($fallos === [] ? 0 : 1);
