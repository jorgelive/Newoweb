<?php

declare(strict_types=1);

/**
 * `Beds24WebhookSobre` y los DTO de sus piezas leen LO MISMO que el código de antes.
 *
 * Recorre los payloads auditados en `pms_beds24_webhook_audit` y compara, por los dos caminos:
 * el instante del evento (el colchón de 15 s), la etiqueta de la auditoría, qué reservas, mensajes
 * y facturas se reparten, y cada campo de texto de los mensajes y las facturas (que pasaron de
 * `trim((string) $v)` a `Lee::textoLimpio()`). Sólo LEE; no imprime datos personales.
 *
 * Uso (en el servidor): php tools/pruebas/probar-dto-beds24-webhook.php
 *
 * Ver `docs/TiposDeFrontera.md`.
 */

use App\Message\Dto\Beds24MessageDto;
use App\Pms\Dto\Beds24InvoiceItemDto;
use App\Pms\Dto\Beds24WebhookSobre;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$entorno = $_SERVER['APP_ENV'] ?? 'dev';
$kernel = new App\Kernel(is_string($entorno) ? $entorno : 'dev', false);
$kernel->boot();
/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();

$difs = [];
$cuenta = ['payloads' => 0, 'reservas' => 0, 'mensajes' => 0, 'facturas' => 0];
$anotar = static function (string $campo, mixed $v, mixed $n) use (&$difs): void {
    if ($v !== $n) {
        $difs[$campo] = ($difs[$campo] ?? 0) + 1;
    }
};
$viejoTexto = static function (mixed $v): ?string {
    if ($v === null) {
        return null;
    }
    $s = trim(is_scalar($v) ? (string) $v : '«' . gettype($v) . '»');

    return $s === '' ? null : $s;
};

$tabla = $em->getClassMetadata(App\Pms\Entity\PmsBeds24WebhookAudit::class)->getTableName();
foreach ($em->getConnection()->iterateColumn("SELECT payload_json FROM $tabla WHERE payload_json IS NOT NULL") as $json) {
    $payload = json_decode(is_string($json) ? $json : '', true);
    if (!is_array($payload)) {
        continue;
    }
    ++$cuenta['payloads'];
    $sobre = Beds24WebhookSobre::fromArray($payload);

    // ── El instante, como lo calculaba el controlador ──
    $ts = null;
    if (isset($payload['messages']) && is_array($payload['messages']) && !empty($payload['messages'])) {
        $ultimo = end($payload['messages']);
        if (is_array($ultimo) && ($ultimo['source'] ?? '') === 'host' && !empty($ultimo['time'])) {
            try { $ts = (new DateTimeImmutable($ultimo['time']))->getTimestamp(); } catch (Throwable) {}
        }
    }
    if ($ts === null) {
        if (!empty($payload['timeStamp'])) {
            try { $ts = (new DateTimeImmutable($payload['timeStamp'], new DateTimeZone('UTC')))->getTimestamp(); } catch (Throwable) {}
        } elseif (isset($payload['booking'])) {
            try {
                $h = !empty($payload['booking']['modifiedTime']) ? $payload['booking']['modifiedTime'] : ($payload['booking']['bookingTime'] ?? null);
                if ($h) { $ts = (new DateTimeImmutable($h, new DateTimeZone('UTC')))->getTimestamp(); }
            } catch (Throwable) {}
        }
    }
    $anotar('momento', $ts, $sobre->momento);

    // ── La etiqueta de la auditoría ──
    $b = $payload['booking'] ?? [];
    $anotar('etiqueta.id', (string) ($b['id'] ?? 'N/A'), $sobre->reservaId ?? 'N/A');
    $anotar('etiqueta.huesped', trim(($b['firstName'] ?? '') . ' ' . ($b['lastName'] ?? '')), $sobre->huesped);
    $anotar('etiqueta.canal', strtoupper($b['referer'] ?? 'DIRECT'), $sobre->canal);

    // ── El reparto ──
    $anotar('trae.reserva', isset($payload['booking']), $sobre->traeReserva);
    $anotar('trae.mensajes', isset($payload['messages']), $sobre->traeMensajes);
    $anotar('trae.facturas', isset($payload['invoiceItems']), $sobre->traeFacturas);
    $lista = static fn (mixed $n): array => is_array($n) && array_is_list($n) ? $n : [$n];
    $ids = static fn (array $xs): array => array_values(array_map(static fn ($x) => $x['id'], array_filter($xs, static fn ($x) => is_array($x) && isset($x['id']))));
    if (isset($payload['booking'])) {
        $anotar('reservas.ids', $ids($lista($payload['booking'])), array_map(static fn ($x) => $x['id'], $sobre->reservas));
        $cuenta['reservas'] += count($sobre->reservas);
    }
    if (isset($payload['messages'])) {
        $viejos = array_values(array_filter($lista($payload['messages']), static fn ($x) => is_array($x) && isset($x['id'])));
        $anotar('mensajes.ids', array_map(static fn ($x) => $x['id'], $viejos), array_map(static fn ($x) => $x['id'], $sobre->mensajes));
        foreach ($viejos as $m) {
            ++$cuenta['mensajes'];
            $d = Beds24MessageDto::fromArray($m);
            foreach (['id', 'bookingId', 'message', 'source', 'attachmentName', 'attachmentMimeType'] as $campo) {
                if (property_exists($d, $campo)) {
                    $anotar("mensaje.$campo", $viejoTexto($m[$campo] ?? null), $d->$campo);
                }
            }
        }
    }
    if (isset($payload['invoiceItems']) && is_array($payload['invoiceItems'])) {
        $viejas = array_values(array_filter($payload['invoiceItems'], static fn ($x) => is_array($x) && isset($x['id'])));
        $anotar('facturas.ids', array_map(static fn ($x) => $x['id'], $viejas), array_map(static fn ($x) => $x['id'], $sobre->facturas));
        foreach ($viejas as $f) {
            ++$cuenta['facturas'];
            $d = Beds24InvoiceItemDto::fromArray($f);
            foreach (['id', 'bookingId', 'invoiceId', 'type', 'description', 'status', 'createdBy'] as $campo) {
                $anotar("factura.$campo", $viejoTexto($f[$campo] ?? null), $d->$campo);
            }
            $anotar('factura.subType', isset($f['subType']) && $f['subType'] !== '' ? (int) $f['subType'] : null, $d->subType);
        }
    }
}

foreach ($cuenta as $que => $n) {
    printf("%-10s %6d\n", $que, $n);
}
if ($difs === []) {
    echo "\n✅ Idénticos: instante, etiqueta, reparto y campos salen igual por el sobre y sus DTO.\n";
    exit(0);
}
echo "\n❌ Campos que difieren:\n";
foreach ($difs as $campo => $n) {
    printf("  %-24s %5d\n", $campo, $n);
}
exit(1);
