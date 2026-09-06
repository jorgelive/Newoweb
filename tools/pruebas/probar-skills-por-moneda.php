<?php

declare(strict_types=1);

/**
 * PREVIEW: qué le devuelven las skills al modelo en cada moneda y con cada actor.
 *
 * No es un test: es una **maqueta legible** de lo que el agente va a tener delante para redactar.
 * Lo que se juzga aquí no es si el código corre, sino si un modelo puede contestar bien con eso —
 * y eso sólo se ve leyéndolo.
 *
 * ── Los dos ejes ────────────────────────────────────────────────────────────
 * · MONEDA — una sola, cruce (se pagó en otra), deuda real en dos, sobrepago.
 * · ACTOR  — quién pregunta. No cambia las cifras, cambia **qué se le deja ver**:
 *     · operador desde el panel → todo;
 *     · huésped por WhatsApp → acotado a SU reserva por `contextoId()`, y en canales que
 *       cobran por nosotros (Airbnb/VRBO) sin una sola cifra (§19.10 de docs/Mensajeria.md).
 *
 * Sólo LEE: `consultar_cuenta` no escribe, y las de escritura se llaman sin `confirmado`, que es
 * su modo previsualización. Aun así va en transacción con rollback, por si acaso.
 *
 * Uso: php var/probar-skills-por-moneda.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Agent\Access\AgentActor;
use App\Agent\Skill\Pms\ConsultarCuentaSkill;
use App\Agent\Skill\Pms\RegistrarPagoSkill;
use App\Entity\User;
use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Finance\MonedaResolver;
use App\Pms\Service\Finance\PmsCuentaSimulador;
use App\Pms\Service\Finance\PmsPrepagoCalculador;
use App\Pms\Service\Finance\TipoCambioDelDia;
use App\Service\TipocambioManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\HttpClient;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$_SERVER['APP_ENV'] = 'dev';
$kernel = new App\Kernel('dev', true);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
$conn = $em->getConnection();

// Las skills se arman a mano: en `dev` son privadas, y el contenedor de `test` apunta a una base
// `..._test` que aquí no existe. Sus dependencias no tienen estado (ver probar-costo-teorico.php).
// `TipocambioManager` va con el token vacío a propósito: sin él cae a la cotización ya cacheada
// en la base, que es justo la que se quiere leer — esto no debe salir a SUNAT.
$logger = new NullLogger();
$tipoCambio = new TipoCambioDelDia(new TipocambioManager($em, HttpClient::create(), $logger, ''), $logger);

$cuenta = new ConsultarCuentaSkill($em, new PmsPrepagoCalculador(), $logger);
$pagos = new RegistrarPagoSkill($em, new MonedaResolver($em), $tipoCambio, new PmsCuentaSimulador());

/** Los cuatro casos reales de producción, con lo que cada uno enseña. */
$CASOS = [
    'GASUNN' => 'CRUCE — cargos en USD, pagado por Yape en soles',
    'XTHRMQ' => 'CRUCE — deuda en soles pagada a medias en dólares',
    'XK6FV4' => 'DEUDA EN DOS — dólares saldados, S/ 50 pendientes',
    'ZHX76S' => 'SOBREPAGO — dólares saldados y S/ 10 de más',
];

$jorge = $em->getRepository(User::class)->findOneBy(['email' => 'jorge@live.com.pe']);

$imprimir = static function (string $titulo, array $datos, int $sangria = 4): void {
    echo "\n  ── {$titulo}\n";
    $json = json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    foreach (explode("\n", (string) $json) as $linea) {
        echo str_repeat(' ', $sangria), $linea, "\n";
    }
};

$conn->beginTransaction();

try {
    foreach ($CASOS as $localizador => $descripcion) {
        $reserva = $em->getRepository(PmsReserva::class)->findOneBy(['localizador' => $localizador]);

        if ($reserva === null) {
            printf("\n%s — no está en esta base\n", $localizador);
            continue;
        }

        echo "\n", str_repeat('═', 78), "\n";
        printf("  %s · %s\n", $localizador, $descripcion);
        echo str_repeat('═', 78), "\n";

        $id = (string) $reserva->getId();

        // ── ACTOR 1: el operador desde el panel ─────────────────────────────
        // Ve todo y puede apuntar a cualquier reserva por `reserva_id`.
        $comoOperador = $cuenta->ejecutar(['reserva_id' => $id], AgentActor::delPanel($jorge));
        $imprimir('consultar_cuenta · OPERADOR (panel)', $comoOperador->datos ?? []);

        // ── ACTOR 2: el huésped por WhatsApp ────────────────────────────────
        // NO manda `reserva_id`: su frontera es el contexto, y la skill lo toma de ahí. Si
        // mandara otro, se ignoraría — es lo que impide que pregunte por la cuenta de un vecino.
        $comoHuesped = $cuenta->ejecutar([], AgentActor::huesped('whatsapp_meta', 'pms_reserva', $id));
        $imprimir('consultar_cuenta · HUÉSPED (whatsapp)', $comoHuesped->datos ?? []);
    }

    // ── La pregunta nueva de registrar_pago ─────────────────────────────────
    echo "\n", str_repeat('═', 78), "\n";
    echo "  registrar_pago · LA PREGUNTA QUE ANTES NO EXISTÍA\n";
    echo str_repeat('═', 78), "\n";
    echo "\n  Escenario: cuenta con cargos SÓLO en dólares, y el operador dicta un cobro en soles.\n";
    echo "  Antes se convertía en silencio. Ahora la skill se para y pregunta.\n";

    $gasunn = $em->getRepository(PmsReserva::class)->findOneBy(['localizador' => 'GASUNN']);

    if ($gasunn !== null) {
        $sinImputar = $pagos->ejecutar([
            'reserva_id' => (string) $gasunn->getId(),
            'importe' => '223.70',
            'moneda' => 'PEN',
            'medio_pago' => 'plin_yape',
            'cobrador' => 'Susan',
        ], AgentActor::delPanel($jorge));

        $imprimir('1) «me pagó 223.70 soles por Yape, se los dio a Susan»', $sinImputar->datos ?? []);

        $imputado = $pagos->ejecutar([
            'reserva_id' => (string) $gasunn->getId(),
            'importe' => '223.70',
            'moneda' => 'PEN',
            'medio_pago' => 'plin_yape',
            'cobrador' => 'Susan',
            'salda_deuda_en' => 'USD',
        ], AgentActor::delPanel($jorge));

        $imprimir('2) responde que SÍ, va contra la deuda en dólares', $imputado->datos ?? []);

        $noImputado = $pagos->ejecutar([
            'reserva_id' => (string) $gasunn->getId(),
            'importe' => '223.70',
            'moneda' => 'PEN',
            'medio_pago' => 'plin_yape',
            'cobrador' => 'Susan',
            'salda_deuda_en' => 'no',
        ], AgentActor::delPanel($jorge));

        $imprimir('3) responde que NO — es otra cosa, queda a favor en soles', $noImputado->datos ?? []);
    }
} catch (Throwable $e) {
    printf("\n💥 %s\n  %s:%d\n", $e->getMessage(), $e->getFile(), $e->getLine());
} finally {
    $conn->rollBack();
    echo "\n↩︎  rollback: no se ha escrito nada.\n";
}
