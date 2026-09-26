<?php

declare(strict_types=1);

/**
 * La confirmación que hace un pago tiene que llegar a Beds24, no sólo a nuestra base.
 *
 * Reproduce el camino de José (TA3WSE, 25/09/2026) con una estancia de Booking real: se la pone
 * en `pago-total` como la dejaría el recálculo de finanzas, se corre `confirmarPorPago()` —el
 * UPDATE en SQL—, se pasa el mensaje por su handler y se mira que el push quede encolado y que
 * la estancia lleve la marca con la que la estrategia manda `status: confirmed`.
 *
 * Todo en transacción y deshecho al final. No sale nada a Beds24: el RunExchangeTaskDispatch
 * que encola el listener va a la tabla de Messenger, dentro de la misma transacción.
 *
 *   php tools/pruebas/probar-confirmacion-por-pago-al-canal.php
 */

use App\Exchange\Service\Context\SyncContext;
use App\Kernel;
use App\Pms\Dispatch\AnunciarConfirmacionAlCanalDispatch;
use App\Pms\DispatchHandler\AnunciarConfirmacionAlCanalDispatchHandler;
use App\Pms\Service\Finance\PmsEstadoPagoEventosService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

$kernel = new Kernel('dev', false);
$kernel->boot();
/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
$conexion = $em->getConnection();

$fallos = 0;
$comprobar = static function (string $caso, bool $ok, string $detalle = '') use (&$fallos): void {
    $fallos += $ok ? 0 : 1;
    printf("  %s %s%s\n", $ok ? '✅' : '❌', $caso, $detalle === '' ? '' : " — $detalle");
};

$fila = $conexion->fetchAssociative(
    "SELECT BIN_TO_UUID(e.id) ev, BIN_TO_UUID(i.id) info, HEX(i.id) info_hex
       FROM pms_evento_calendario e
       JOIN pms_informacion_financiera i ON i.reserva_id = e.reserva_id
       JOIN pms_evento_beds24_link l ON l.evento_id = e.id AND l.es_principal = 1 AND l.beds24_book_id IS NOT NULL
      WHERE e.channel_id = 'booking' AND e.estado_id = 'pendiente' AND e.evento_origen_id IS NULL
        AND e.estado_beds24 = 'new'
      ORDER BY e.inicio DESC LIMIT 1"
);

if ($fila === false) {
    echo "No hay una estancia de Booking pendiente con link principal: nada que probar.\n";
    exit(1);
}

printf("\nEstancia de prueba: %s\n\n", $fila['ev']);
$conexion->beginTransaction();

try {
    // Como la dejaría el recálculo de finanzas tras cobrar el total.
    $conexion->executeStatement(
        "UPDATE pms_evento_calendario SET estado_pago_id = 'pago-total', estado_push_solicitado = 0 WHERE id = UUID_TO_BIN(?)",
        [$fila['ev']]
    );

    $capturados = [];
    $bus = new class ($capturados) implements MessageBusInterface {
        /** @param list<object> $capturados */
        public function __construct(private array &$capturados) {}

        public function dispatch(object $message, array $stamps = []): Envelope
        {
            $this->capturados[] = $message;

            return new Envelope($message);
        }
    };

    $servicio = new PmsEstadoPagoEventosService(new SyncContext(), $bus);
    $binario = hex2bin($fila['info_hex']);
    $confirmar = new ReflectionMethod(PmsEstadoPagoEventosService::class, 'confirmarPorPago');
    $tocadas = $confirmar->invoke($servicio, $conexion, '?', [$binario], [\Doctrine\DBAL\ParameterType::BINARY]);

    $estado = $conexion->fetchOne('SELECT estado_id FROM pms_evento_calendario WHERE id = UUID_TO_BIN(?)', [$fila['ev']]);
    $comprobar('el UPDATE en SQL la confirma', $estado === 'confirmada', "estado=$estado, filas=$tocadas");

    $mensaje = $capturados[0] ?? null;
    $comprobar(
        'y manda el aviso al bus con esa estancia',
        $mensaje instanceof AnunciarConfirmacionAlCanalDispatch && in_array(strtolower($fila['ev']), $mensaje->eventoIds, true),
        $mensaje instanceof AnunciarConfirmacionAlCanalDispatch ? implode(',', $mensaje->eventoIds) : 'nada'
    );

    $colasAntes = (int) $conexion->fetchOne("SELECT COUNT(*) FROM pms_bookings_push_queue WHERE status = 'pending'");

    if ($mensaje instanceof AnunciarConfirmacionAlCanalDispatch) {
        (new AnunciarConfirmacionAlCanalDispatchHandler($em, new NullLogger()))($mensaje);
    }

    $marca = (int) $conexion->fetchOne('SELECT estado_push_solicitado FROM pms_evento_calendario WHERE id = UUID_TO_BIN(?)', [$fila['ev']]);
    $comprobar('el handler deja la marca con la que viaja el status', $marca === 1);

    $pendientes = (int) $conexion->fetchOne(
        "SELECT COUNT(*) FROM pms_bookings_push_queue q
           JOIN pms_evento_beds24_link l ON l.id = q.link_id
          WHERE l.evento_id = UUID_TO_BIN(?) AND l.es_principal = 1 AND q.status = 'pending'",
        [$fila['ev']]
    );
    $comprobar('y el push del link principal queda encolado', $pendientes > 0, "colas pendientes del principal: $pendientes (total antes: $colasAntes)");
} finally {
    $conexion->rollBack();
}

printf("\n%s\n\n", $fallos === 0 ? '✅ Todo en orden. Deshecho.' : "❌ $fallos comprobaciones fallaron. Deshecho.");
exit($fallos === 0 ? 0 : 1);
