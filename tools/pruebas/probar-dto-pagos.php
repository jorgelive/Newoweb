<?php

declare(strict_types=1);

/**
 * Los DTO de pagos leen LO MISMO que las expresiones crudas a las que sustituyen.
 *
 * Sobre los datos reales guardados —las respuestas de Culqi de cada enlace pagado, los avisos de
 * las pasarelas y los intentos de cobro auditados—, compara campo a campo la lectura cruda de antes
 * (`$cargo['outcome']['type'] ?? null`…) con la del DTO. Sólo LEE; no imprime datos del titular.
 *
 * Uso (en el servidor): php tools/pruebas/probar-dto-pagos.php
 *
 * Ver `docs/FinanzasEnlacesPago.md` y `docs/TiposDeFrontera.md`.
 */

use App\Finanzas\Dto\AvisoDePasarela;
use App\Finanzas\Dto\RespuestaCulqi;
use App\Finanzas\Dto\TransaccionDePasarela;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$entorno = $_SERVER['APP_ENV'] ?? 'dev';
$kernel = new App\Kernel(is_string($entorno) ? $entorno : 'dev', false);
$kernel->boot();
/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
$db = $em->getConnection();

$difs = [];
$cuenta = [];
$comparar = static function (string $campo, mixed $viejo, mixed $nuevo) use (&$difs): void {
    $v = $viejo === null ? null : (is_scalar($viejo) ? (string) $viejo : '«' . gettype($viejo) . '»');
    $n = $nuevo === null ? null : (is_scalar($nuevo) ? (string) $nuevo : '«' . gettype($nuevo) . '»');
    if ($v !== $n) {
        $difs[$campo] = ($difs[$campo] ?? 0) + 1;
    }
};

// ── 1. Las respuestas de Culqi y la transacción normalizada de cada enlace pagado ──
foreach ($db->iterateColumn('SELECT respuesta_pasarela FROM fin_enlace_pago WHERE respuesta_pasarela IS NOT NULL') as $json) {
    $r = json_decode(is_string($json) ? $json : '', true);
    if (!is_array($r)) {
        continue;
    }
    $cuenta['enlaces con respuesta'] = ($cuenta['enlaces con respuesta'] ?? 0) + 1;

    // La transacción normalizada (lo que lee FinEnlacePagoService).
    $t = TransaccionDePasarela::primeraDe($r);
    $tx = is_array($r['transactions'] ?? null) && isset($r['transactions'][0]) && is_array($r['transactions'][0]) ? $r['transactions'][0] : [];
    $comparar('transaccion.uuid', $tx['uuid'] ?? null, $t->uuid);
    $comparar('transaccion.autorizacion', $tx['transactionDetails']['cardDetails']['authorizationResponse']['authorizationNumber'] ?? null, $t->autorizacion);
    $comparar('transaccion.marca', $tx['transactionDetails']['cardDetails']['effectiveBrand'] ?? null, $t->marca);
    $comparar('transaccion.pan', $tx['transactionDetails']['cardDetails']['pan'] ?? null, $t->pan);

    // El cargo crudo de Culqi, si lo hay (lo que leen cargoPagaElEnlace y comoRespuestaNormalizada).
    $cargo = $r['culqi'] ?? null;
    if (!is_array($cargo)) {
        continue;
    }
    $cuenta['cargos de Culqi'] = ($cuenta['cargos de Culqi'] ?? 0) + 1;
    $c = RespuestaCulqi::fromArray($cargo);
    $comparar('culqi.object', $cargo['object'] ?? null, $c->objeto);
    $comparar('culqi.outcome.type', $cargo['outcome']['type'] ?? null, $c->resultadoTipo);
    $comparar('culqi.outcome.code', $cargo['outcome']['code'] ?? null, $c->resultadoCodigo);
    $comparar('culqi.outcome.merchant_message', $cargo['outcome']['merchant_message'] ?? null, $c->resultadoMotivoComercio);
    $comparar('culqi.amount (int)', (int) ($cargo['amount'] ?? 0), $c->importeCentimos ?? 0);
    $comparar('culqi.currency_code', $cargo['currency_code'] ?? null, $c->moneda);
    $comparar('culqi.id', $cargo['id'] ?? null, $c->id);
    $comparar('culqi.reference_code', $cargo['reference_code'] ?? null, $c->codigoReferencia);
    $tarjeta = $cargo['source'] ?? [];
    $comparar('culqi.card_brand', $tarjeta['iin']['card_brand'] ?? null, $c->marcaTarjeta);
    $comparar('culqi.pan', isset($tarjeta['last_four']) ? '****' . $tarjeta['last_four'] : null, $c->ultimosCuatro !== null ? '****' . $c->ultimosCuatro : null);
}

// ── 2. Las respuestas auditadas de cada intento de cobro (incluye rechazos y retos 3DS) ──
foreach ($db->iterateColumn('SELECT respuesta FROM fin_pasarela_cobro_audit WHERE respuesta IS NOT NULL') as $json) {
    $d = json_decode(is_string($json) ? $json : '', true);
    if (!is_array($d)) {
        continue;
    }
    $cuenta['intentos auditados'] = ($cuenta['intentos auditados'] ?? 0) + 1;
    $c = RespuestaCulqi::fromArray($d);
    // Lo que leía CulqiRechazoException.
    $comparar('rechazo.action_code', $d['action_code'] ?? null, $c->actionCode);
    $comparar('rechazo.decline_code', $d['outcome']['decline_code'] ?? $d['decline_code'] ?? null, $c->resultadoCodigoRechazo ?? $c->codigoRechazo);
    $codigo = $d['outcome']['code'] ?? $d['code'] ?? null;
    $comparar('rechazo.codigo', is_string($codigo) ? $codigo : null, $c->resultadoCodigo ?? $c->codigo);
    $motivo = $d['outcome']['merchant_message'] ?? $d['merchant_message'] ?? null;
    $comparar('rechazo.motivo', is_string($motivo) ? $motivo : null, $c->resultadoMotivoComercio ?? $c->motivoComercio);
}

// ── 3. Los avisos de las pasarelas ──
foreach ($db->fetchAllAssociative('SELECT pasarela, payload_raw FROM fin_pasarela_webhook_audit WHERE payload_raw IS NOT NULL') as $fila) {
    $raw = is_string($fila['payload_raw']) ? $fila['payload_raw'] : '';
    $pasarela = is_string($fila['pasarela']) ? $fila['pasarela'] : '';
    if ($pasarela === 'culqi') {
        $p = json_decode($raw, true);
        if (!is_array($p)) {
            continue;
        }
        $cuenta['avisos de Culqi'] = ($cuenta['avisos de Culqi'] ?? 0) + 1;
        $a = AvisoDePasarela::deCulqi($p);
        $idViejo = null;
        foreach ([$p['data']['id'] ?? null, $p['object']['id'] ?? null, $p['id'] ?? null] as $cand) {
            if (is_string($cand) && str_starts_with($cand, 'chr_')) { $idViejo = $cand; break; }
        }
        $comparar('culqi.aviso.idCargo', $idViejo, $a->idCargo);
        $e = $p['data']['metadata']['enlaceId'] ?? $p['metadata']['enlaceId'] ?? null;
        $comparar('culqi.aviso.enlaceId', is_string($e) ? $e : null, $a->enlaceId);
        $o = $p['data']['metadata']['ordenId'] ?? null;
        $comparar('culqi.aviso.ordenId', is_string($o) ? $o : null, $a->ordenId);
    } elseif ($pasarela === 'izipay') {
        parse_str($raw, $post);
        $answer = $post['kr-answer'] ?? null;
        $r = is_string($answer) ? json_decode($answer, true) : null;
        if (!is_array($r)) {
            continue;
        }
        $cuenta['avisos de Izipay'] = ($cuenta['avisos de Izipay'] ?? 0) + 1;
        $a = AvisoDePasarela::deIzipay($r);
        $e = $r['transactions'][0]['metadata']['enlaceId'] ?? $r['metadata']['enlaceId'] ?? null;
        $comparar('izipay.aviso.enlaceId', is_string($e) ? $e : null, $a->enlaceId);
        $o = $r['orderDetails']['orderId'] ?? $r['orderId'] ?? null;
        $comparar('izipay.aviso.ordenId', is_string($o) ? $o : null, $a->ordenId);
    }
}

foreach ($cuenta as $que => $n) {
    printf("%-24s %5d\n", $que, $n);
}
if ($difs === []) {
    echo "\n✅ Idénticos: cada campo que leía el código de pagos sale igual por los DTO.\n";
    exit(0);
}
echo "\n❌ Campos que difieren:\n";
foreach ($difs as $campo => $n) {
    printf("  %-32s %5d\n", $campo, $n);
}
exit(1);
