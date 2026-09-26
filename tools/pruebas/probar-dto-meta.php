<?php

declare(strict_types=1);

/**
 * Los DTO del webhook de Meta leen LO MISMO que las expresiones crudas a las que sustituyen.
 *
 * Recorre los payloads guardados en `msg_meta_webhook_audit` —el webhook de verdad, desde junio de
 * 2026— y compara, campo por campo, la lectura cruda que hacía `WhatsappMetaReceivePersister`
 * (`$messageData['text']['body'] ?? null`, `(string) ($errorInfo['code'] ?? 'unknown')`…) con la
 * del DTO. También cuenta mensajes, estados y llamadas por los dos recorridos del sobre.
 *
 * Sólo LEE: no crea ni toca nada. No imprime datos personales —sólo campos y cuentas—.
 *
 * Uso (en el servidor, que es donde están los payloads):
 *   php tools/pruebas/probar-dto-meta.php
 *
 * Ver `docs/Mensajeria.md` — el webhook de Meta y sus DTO.
 */

use App\Message\Dto\Meta\MetaWebhookSobre;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

$entorno = $_SERVER['APP_ENV'] ?? 'dev';
$kernel = new App\Kernel(is_string($entorno) ? $entorno : 'dev', false);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();

/** Lo que hacía el código viejo con un valor suelto al usarlo como texto. */
$comoTexto = static function (mixed $v): ?string {
    if ($v === null) {
        return null;
    }

    return is_scalar($v) ? (string) $v : '«' . gettype($v) . '»';
};

$diferencias = [];
$anotar = static function (string $campo, mixed $viejo, mixed $nuevo) use (&$diferencias, $comoTexto): void {
    $v = $comoTexto($viejo);
    $n = $nuevo === null ? null : (string) $nuevo;
    if ($v !== $n) {
        $diferencias[$campo] = ($diferencias[$campo] ?? 0) + 1;
    }
};

$cuenta = ['payloads' => 0, 'mensajes_viejo' => 0, 'mensajes_nuevo' => 0, 'estados_viejo' => 0, 'estados_nuevo' => 0, 'llamadas_viejo' => 0, 'llamadas_nuevo' => 0];

foreach ($em->getConnection()->iterateColumn('SELECT payload_json FROM msg_meta_webhook_audit WHERE payload_json IS NOT NULL') as $json) {
    $payload = json_decode(is_string($json) ? $json : '', true);
    if (!is_array($payload)) {
        continue;
    }
    ++$cuenta['payloads'];

    // ── El recorrido VIEJO, tal cual estaba en el controlador (con `@` donde habría avisado) ──
    $viejos = ['m' => [], 'c' => [], 's' => [], 'l' => []];
    foreach (($payload['entry'] ?? []) as $entry) {
        foreach ((@$entry['changes'] ?? []) as $change) {
            $value = $change['value'];
            if (isset($value['messages']) && isset($value['contacts'])) {
                foreach ($value['messages'] as $m) {
                    $viejos['m'][] = $m;
                    $viejos['c'][] = @$value['contacts'][0];
                }
            }
            foreach (($value['statuses'] ?? []) as $s) {
                $viejos['s'][] = $s;
            }
            foreach (($value['calls'] ?? []) as $l) {
                $viejos['l'][] = $l;
            }
        }
    }

    // ── El recorrido NUEVO ──
    $sobre = MetaWebhookSobre::fromArray($payload);
    $nuevos = ['m' => [], 'c' => [], 's' => [], 'l' => []];
    foreach ($sobre->cambios as $cambio) {
        if ($cambio->contacto !== null) {
            foreach ($cambio->mensajes as $m) {
                $nuevos['m'][] = $m;
                $nuevos['c'][] = $cambio->contacto;
            }
        }
        array_push($nuevos['s'], ...$cambio->estados);
        array_push($nuevos['l'], ...$cambio->llamadas);
    }

    $cuenta['mensajes_viejo'] += count($viejos['m']);
    $cuenta['mensajes_nuevo'] += count($nuevos['m']);
    $cuenta['estados_viejo'] += count($viejos['s']);
    $cuenta['estados_nuevo'] += count($nuevos['s']);
    $cuenta['llamadas_viejo'] += count($viejos['l']);
    $cuenta['llamadas_nuevo'] += count($nuevos['l']);

    // ── Campo a campo: las lecturas exactas del persister ──
    foreach ($viejos['m'] as $i => $m) {
        $d = $nuevos['m'][$i] ?? null;
        $c = $viejos['c'][$i];
        $dc = $nuevos['c'][$i] ?? null;
        if ($d === null || $dc === null) {
            $diferencias['(mensaje que falta)'] = ($diferencias['(mensaje que falta)'] ?? 0) + 1;
            continue;
        }
        $tipo = $m['type'] ?? 'text';
        $anotar('id', $m['id'] ?? null, $d->id);
        $anotar('tipo', $tipo, $d->tipo);
        $anotar('timestamp', $m['timestamp'] ?? null, $d->timestamp);
        $anotar('texto', $m['text']['body'] ?? null, $d->texto);
        $anotar('boton.payload', $m['button']['payload'] ?? null, $d->botonPayload);
        $anotar('boton.texto', $m['button']['text'] ?? null, $d->botonTexto);
        $anotar('interactivo.tipo', $m['interactive']['type'] ?? null, $d->interactivoTipo);
        $intTipo = $m['interactive']['type'] ?? '';
        if (in_array($intTipo, ['button_reply', 'list_reply'], true)) {
            $anotar('interactivo.id', $m['interactive'][$intTipo]['id'] ?? null, $d->interactivoId);
            $anotar('interactivo.titulo', $m['interactive'][$intTipo]['title'] ?? null, $d->interactivoTitulo);
        }
        if (in_array($tipo, ['image', 'document', 'audio', 'video', 'sticker'], true)) {
            $anotar('adjunto.id', $m[$tipo]['id'] ?? null, $d->adjuntoId);
            $anotar('adjunto.mime', $m[$tipo]['mime_type'] ?? null, $d->adjuntoMime);
            $anotar('adjunto.nombre', $m[$tipo]['filename'] ?? null, $d->adjuntoNombre);
        }
        $anotar('ubicacion.lat', $m['location']['latitude'] ?? null, $d->latitud);
        $anotar('ubicacion.lng', $m['location']['longitude'] ?? null, $d->longitud);
        $anotar('reaccion.mensaje', $m['reaction']['message_id'] ?? null, $d->reaccionAMensaje);
        $anotar('reaccion.emoji', $m['reaction']['emoji'] ?? null, $d->reaccionEmoji);
        $anotar('contacto.wa_id', is_array($c) ? ($c['wa_id'] ?? null) : null, $dc->waId);
        $anotar('contacto.nombre', is_array($c) ? ($c['profile']['name'] ?? null) : null, $dc->nombre);
    }

    foreach ($viejos['s'] as $i => $s) {
        $d = $nuevos['s'][$i] ?? null;
        if ($d === null) {
            $diferencias['(estado que falta)'] = ($diferencias['(estado que falta)'] ?? 0) + 1;
            continue;
        }
        $anotar('estado.id', $s['id'] ?? null, $d->id);
        $anotar('estado.estado', $s['status'] ?? null, $d->estado);
        $anotar('estado.timestamp', $s['timestamp'] ?? null, $d->timestamp);
        $error = $s['errors'][0] ?? [];
        // El persister: `(string) ($errorInfo['code'] ?? 'unknown')` y `$errorInfo['message'] ?? json_encode(...)`.
        $anotar('estado.error.codigo', (string) ($error['code'] ?? 'unknown'), $d->errorCodigo ?? 'unknown');
        $anotar('estado.error.motivo',
            $error['message'] ?? json_encode($s['errors'] ?? [], JSON_UNESCAPED_UNICODE),
            $d->errorMensaje ?? json_encode($d->errores, JSON_UNESCAPED_UNICODE));
    }

    foreach ($viejos['l'] as $i => $l) {
        $d = $nuevos['l'][$i] ?? null;
        if ($d === null) {
            $diferencias['(llamada que falta)'] = ($diferencias['(llamada que falta)'] ?? 0) + 1;
            continue;
        }
        $anotar('llamada.id', $l['id'] ?? null, $d->id);
        $anotar('llamada.timestamp', $l['timestamp'] ?? null, $d->timestamp);
    }
}

echo "Payloads de la auditoría: {$cuenta['payloads']}\n";
printf("Mensajes  viejo %5d · nuevo %5d\n", $cuenta['mensajes_viejo'], $cuenta['mensajes_nuevo']);
printf("Estados   viejo %5d · nuevo %5d\n", $cuenta['estados_viejo'], $cuenta['estados_nuevo']);
printf("Llamadas  viejo %5d · nuevo %5d\n", $cuenta['llamadas_viejo'], $cuenta['llamadas_nuevo']);

if ($diferencias === []) {
    echo "\n✅ Idénticos: cada campo que lee el persister sale igual por el DTO.\n";
    exit(0);
}

echo "\n❌ Campos que difieren:\n";
arsort($diferencias);
foreach ($diferencias as $campo => $n) {
    printf("  %-24s %5d\n", $campo, $n);
}
exit(1);
