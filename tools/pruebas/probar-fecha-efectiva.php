<?php

declare(strict_types=1);

/**
 * Comprueba con DATOS REALES que las consultas que ordenan por la fecha efectiva siguen
 * devolviendo lo mismo sin el `COALESCE`, y que ahora pasan por el índice.
 *
 * Nada de esto lo cubre un test unitario: son consultas contra base de datos. Se ejecuta en
 * transacción y se deshace, aunque sólo lee.
 *
 *   php tools/pruebas/probar-fecha-efectiva.php
 */

use App\Kernel;
use App\Message\Entity\Message;
use App\Message\Filter\MessageVistaDelHiloExtension;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

$kernel = new Kernel('dev', false);
$kernel->boot();
/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
$conexion = $em->getConnection();
$conexion->beginTransaction();

$fallos = 0;
$comprobar = static function (string $caso, bool $ok, string $detalle = '') use (&$fallos): void {
    if (!$ok) {
        $fallos++;
    }
    printf("  %s %s%s\n", $ok ? '✅' : '❌', $caso, $detalle === '' ? '' : " — $detalle");
};

// El hilo con MÁS CANCELADOS, no el más gordo: es el que tiene las tres pestañas llenas, y por
// tanto el único donde la partición se comprueba de verdad. El hilo más largo suele tener el
// historial entero y las otras dos vacías.
$hilo = $conexion->fetchAssociative(
    "SELECT BIN_TO_UUID(conversation_id) id, COUNT(*) n FROM msg_message
      GROUP BY conversation_id
      ORDER BY SUM(status = 'cancelled') DESC, SUM(ocurrio_at > NOW()) DESC, n DESC LIMIT 1"
);
printf("\nHilo de prueba: %s (%d mensajes)\n\n", $hilo['id'], $hilo['n']);

// ── 1. Ni un nulo, y la columna es NOT NULL ────────────────────────────────────
$nulos = (int) $conexion->fetchOne('SELECT COUNT(*) FROM msg_message WHERE ocurrio_at IS NULL');
$comprobar('ocurrio_at sin nulos', $nulos === 0, "nulos: $nulos");

$columna = $conexion->fetchAssociative("SHOW COLUMNS FROM msg_message LIKE 'ocurrio_at'");
$comprobar('la columna es NOT NULL', $columna['Null'] === 'NO', 'Null=' . $columna['Null']);

// ── 2. Las tres pestañas, con la extensión de verdad ───────────────────────────
$extension = new MessageVistaDelHiloExtension();
$pestanas = [
    MessageVistaDelHiloExtension::HISTORIAL => ['ocurrioAt' => 'DESC'],
    MessageVistaDelHiloExtension::PROGRAMADOS => ['ocurrioAt' => 'ASC'],
    MessageVistaDelHiloExtension::CANCELADOS => ['ocurrioAt' => 'DESC'],
];
$suma = 0;

foreach ($pestanas as $nombre => $orden) {
    $qb = $em->getRepository(Message::class)->createQueryBuilder('m')
        ->andWhere('m.conversation = :c')
        ->setParameter('c', $hilo['id'], 'uuid');

    $extension->applyToCollection(
        $qb,
        new class implements \ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface {
            public function generateJoinAlias(string $association): string { return $association . '_a'; }
            public function generateParameterName(string $name): string { return $name . '_p'; }
        },
        Message::class,
        new GetCollection(name: $nombre)
    );

    foreach ($orden as $campo => $sentido) {
        $qb->addOrderBy('m.' . $campo, $sentido);
    }
    $qb->addOrderBy('m.id', reset($orden));

    $sql = $qb->getQuery()->getSQL();
    $filas = $qb->getQuery()->getResult();
    $suma += count($filas);

    $comprobar("$nombre sin COALESCE en el SQL", !str_contains(strtoupper($sql), 'COALESCE'));
    printf("     %d mensajes\n", count($filas));
}

$total = (int) $conexion->fetchOne(
    'SELECT COUNT(*) FROM msg_message WHERE conversation_id = UUID_TO_BIN(?)',
    [$hilo['id']]
);
// Las tres pestañas son una partición del hilo: ni un mensaje se queda sin sitio ni sale dos veces.
$comprobar('las tres pestañas suman el hilo entero', $suma === $total, "$suma de $total");

// ── 3. Las consultas que llevaban COALESCE, una por una ────────────────────────
$dql = [
    'último saliente ya ocurrido (acuse)' => [
        'SELECT m FROM ' . Message::class . ' m
          WHERE m.conversation = :c AND m.direction = :d AND m.status != :x AND m.ocurrioAt <= :ahora
          ORDER BY m.ocurrioAt DESC, m.id DESC',
        ['c' => $hilo['id'], 'd' => Message::DIRECTION_OUTGOING, 'x' => Message::STATUS_CANCELLED, 'ahora' => new DateTimeImmutable()],
    ],
    'último mensaje real (menú de entrada)' => [
        'SELECT m FROM ' . Message::class . ' m
          WHERE m.conversation = :c AND m.ocurrioAt <= :ahora
          ORDER BY m.ocurrioAt DESC',
        ['c' => $hilo['id'], 'ahora' => new DateTimeImmutable()],
    ],
    'corte de la última salida (resumen)' => [
        'SELECT MAX(m.ocurrioAt) FROM ' . Message::class . ' m
          WHERE m.conversation = :c AND m.direction = :d
            AND (m.scheduledAt IS NULL OR m.scheduledAt <= :ahora)',
        ['c' => $hilo['id'], 'd' => Message::DIRECTION_OUTGOING, 'ahora' => new DateTimeImmutable()],
    ],
];

foreach ($dql as $caso => [$texto, $params]) {
    $q = $em->createQuery($texto)->setMaxResults(1);
    foreach ($params as $k => $v) {
        $q->setParameter($k, $v, $k === 'c' ? 'uuid' : null);
    }
    $r = $q->getOneOrNullResult();
    $comprobar($caso, $r !== null, is_array($r) ? json_encode($r) : ($r instanceof Message ? (string) $r->getId() : 'null'));
}

// ── 4. Y que el plan use el índice, que es la mitad del motivo ─────────────────
$plan = $conexion->fetchAssociative(
    "EXPLAIN SELECT id FROM msg_message
      WHERE conversation_id = UUID_TO_BIN(?) AND ocurrio_at <= NOW() AND status <> 'cancelled'
      ORDER BY ocurrio_at DESC, id DESC LIMIT 30",
    [$hilo['id']]
);
$comprobar(
    'el listado del hilo usa idx_msg_hilo_ocurrio y no ordena a mano',
    $plan['key'] === 'idx_msg_hilo_ocurrio' && !str_contains((string) $plan['Extra'], 'filesort'),
    sprintf('key=%s extra=%s', $plan['key'], $plan['Extra'])
);

$conexion->rollBack();

printf("\n%s\n\n", $fallos === 0 ? '✅ Todo en orden.' : "❌ $fallos comprobaciones fallaron.");
exit($fallos === 0 ? 0 : 1);
