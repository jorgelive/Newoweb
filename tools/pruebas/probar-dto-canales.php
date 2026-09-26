<?php

declare(strict_types=1);

/**
 * Los DTO de respuesta de los canales leen LO MISMO que las expresiones crudas a las que sustituyen.
 *
 * Recorre las respuestas guardadas en las colas del motor de intercambio (`last_response_raw`) y, por
 * cada una, calcula lo que leía el código de antes —copiado aquí tal cual— y lo que lee ahora por
 * `Beds24Respuesta`, `RespuestaGraphMeta` y `ResultadoDelCorreo`, campo a campo. Sólo LEE; no imprime
 * datos de huéspedes ni credenciales.
 *
 * ⚠️ **Qué hay guardado en cada cola, que no es lo mismo en todas.** El motor guarda la respuesta
 * entera, pero varios handlers la SOBRESCRIBEN después con su trozo:
 *
 * | Cola | `last_response_raw` guarda |
 * |---|---|
 * | push de reservas, tarifas | la pieza de su ítem (tarifas: su `modified`) |
 * | pull de reservas, mensajes y facturas recibidos | la lista ya repartida para ese ítem |
 * | envío por Beds24 | la lista entera de piezas del lote |
 * | envío por WhatsApp | los cuerpos crudos de Meta, uno por mensaje del lote |
 * | correo | `{enviados, fallos}` |
 *
 * Se compara lo que se puede comparar con cada forma. Lo que no queda guardado —la paginación del
 * GET, los sobres de error de lectura— lo cubren los tests unitarios.
 *
 * Uso (en el servidor, sin tocar el árbol de producción):
 *
 *   mkdir -p /tmp/dto-canales && cp -r src/Exchange/Dto tools/pruebas/probar-dto-canales.php /tmp/dto-canales/
 *   cd /var/www/openperu.pe && APP_DIR=$PWD DTO_DIR=/tmp/dto-canales/Dto php /tmp/dto-canales/probar-dto-canales.php
 *
 * `DTO_DIR` carga las clases nuevas desde la copia: el autoloader de producción aún no las conoce.
 * En local basta `php tools/pruebas/probar-dto-canales.php`.
 *
 * Ver `docs/TiposDeFrontera.md` §3.
 */

use App\Dto\Lee;
use App\Exchange\Dto\Beds24\Beds24Respuesta;
use App\Exchange\Dto\Correo\ResultadoDelCorreo;
use App\Exchange\Dto\Meta\RespuestaGraphMeta;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

$raiz = getenv('APP_DIR') ?: dirname(__DIR__, 2);
require $raiz . '/vendor/autoload.php';

$dtoDir = getenv('DTO_DIR');
if (is_string($dtoDir) && $dtoDir !== '') {
    foreach (['Beds24/Beds24Respuesta.php', 'Meta/RespuestaGraphMeta.php', 'Correo/ResultadoDelCorreo.php'] as $archivo) {
        require_once $dtoDir . '/' . $archivo;
    }
}

(new Dotenv())->bootEnv($raiz . '/.env');
$entorno = $_SERVER['APP_ENV'] ?? 'dev';
$kernel = new App\Kernel(is_string($entorno) ? $entorno : 'dev', false);
$kernel->boot();
/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
$db = $em->getConnection();

$difs = [];
$cuenta = [];
$contar = static function (string $que) use (&$cuenta): void {
    $cuenta[$que] = ($cuenta[$que] ?? 0) + 1;
};
// Igualdad ESTRICTA: un id que pasa de 123 a "123" es una diferencia, porque acaba en un JSON.
$comparar = static function (string $campo, mixed $viejo, mixed $nuevo) use (&$difs): void {
    if ($viejo !== $nuevo) {
        $difs[$campo] = ($difs[$campo] ?? 0) + 1;
    }
};

/** @return iterable<array<mixed>> Cada respuesta guardada, decodificada; lo que no es JSON se salta. */
$respuestas = static function (Connection $db, string $tabla): iterable {
    foreach ($db->iterateColumn("SELECT last_response_raw FROM {$tabla} WHERE last_response_raw IS NOT NULL") as $json) {
        $d = json_decode(is_string($json) ? $json : '', true);
        if (is_array($d)) {
            yield $d;
        }
    }
};

// Las piezas de una respuesta de escritura: una lista de objetos, o un objeto suelto.
$piezasDe = static fn (array $d): array => array_is_list($d) ? $d : [$d];

// ── 1. Push de reservas: estrategia y handler ────────────────────────────────
foreach ($respuestas($db, 'pms_bookings_push_queue') as $d) {
    foreach ($piezasDe($d) as $p) {
        $contar('push reservas: piezas');
        $n = Beds24Respuesta::dePieza($p);

        // BookingsPushMappingStrategy::parseResponse(), antes
        $exito = (bool) ($p['success'] ?? false);
        $comparar('push.estrategia.exito', $exito, $n->exito ?? false);
        if (!$exito) {
            $comparar('push.estrategia.error', $p['errors'][0]['message'] ?? $p['message'] ?? 'Error desconocido', $n->primerError ?? $n->mensaje ?? 'Error desconocido');
        }
        $rid = $p['new']['id'] ?? $p['id'] ?? $p['bookId'] ?? null;
        $ridNuevo = $n->idNuevo ?? $n->id ?? $n->bookId;
        $comparar('push.estrategia.remoteId', $rid ? (string) $rid : null, $ridNuevo ? (string) $ridNuevo : null);
        $comparar('push.estrategia.extraData', (array) $p, $n->crudo);

        // BookingsPushHandler::handleSuccess(), antes (sobre `extraData`, que es la pieza)
        $h = Beds24Respuesta::fromArray(is_array($p) ? $p : []);
        $comparar('push.handler.remoteId', $p['new']['id'] ?? $p['id'] ?? $p['new'][0]['id'] ?? null, $h->idNuevo ?? $h->id ?? $h->idNuevoEnLista);
        $comparar('push.handler.exito', (bool) ($p['success'] ?? true), $h->exito ?? true);
        $comparar('push.handler.msg', $p['message'] ?? 'Procesado correctamente', $h->mensaje ?? 'Procesado correctamente');
    }
}

// ── 2. Tarifas: lo guardado es el `modified` de cada pieza (o la pieza si no lo trae) ──
foreach ($respuestas($db, 'pms_rates_push_queue') as $p) {
    $contar('tarifas: piezas');
    $n = Beds24Respuesta::dePieza($p);

    // RatesNestedMappingStrategy::parseResponse(), antes — error global y pieza
    $global = isset($p['success']) && $p['success'] === false && !isset($p[0]);
    $comparar('tarifas.errorGlobal', $global, $n->declaraFallo && !isset($p[0]));
    if ($global) {
        $comparar('tarifas.errorGlobal.msg', $p['message'] ?? 'Error global en Batch Rates', $n->mensaje ?? 'Error global en Batch Rates');
    }
    $exito = (bool) ($p['success'] ?? false);
    $comparar('tarifas.exito', $exito, $n->exito ?? false);
    if (!$exito) {
        $msg = $p['message'] ?? 'Error desconocido en habitación';
        if (isset($p['errors'][0]['message'])) {
            $msg = $p['errors'][0]['message'];
        }
        $comparar('tarifas.error', $msg, $n->primerError ?? $n->mensaje ?? 'Error desconocido en habitación');
    }
    $comparar('tarifas.extraData', (array) ($p['modified'] ?? $p), $n->modificadoOCrudo);
}

// ── 3. Envío de mensajes por Beds24: la lista entera del lote ──
foreach ($respuestas($db, 'msg_beds24_send_queue') as $d) {
    foreach ($piezasDe($d) as $p) {
        $contar('envío Beds24: piezas');
        $n = Beds24Respuesta::dePieza($p);

        // Beds24SendMappingStrategy::parseResponse(), antes
        $exito = (bool) ($p['success'] ?? false);
        $comparar('beds24send.exito', $exito, $n->exito ?? false);
        $rid = $p['id'] ?? $p['new']['id'] ?? null;
        $ridNuevo = $n->id ?? $n->idNuevo;
        $comparar('beds24send.remoteId', $rid ? (string) $rid : null, $ridNuevo ? (string) $ridNuevo : null);
        if (!$exito) {
            $comparar('beds24send.error', $p['message'] ?? 'Error desconocido al enviar mensaje', $n->mensaje ?? 'Error desconocido al enviar mensaje');
        }
        $comparar('beds24send.extraData', (array) $p, $n->crudo);

        // Beds24SendHandler::handleSuccess(), antes
        $comparar('beds24send.handler.remoteId', $p['new']['id'] ?? $p['id'] ?? null, $n->idNuevo ?? $n->id);
    }
}

// ── 4. Pull de reservas: la lista guardada, pasada otra vez por la normalización del sobre ──
foreach ($respuestas($db, 'pms_bookings_pull_queue') as $d) {
    $contar('pull reservas: respuestas');
    $n = Beds24Respuesta::fromArray($d);

    // BookingsPullMappingStrategy::parseResponse(), antes
    $fallo = isset($d['success']) && $d['success'] === false;
    $comparar('pull.fallo', $fallo, $n->declaraFallo);
    $filas = $d;
    if (isset($d['data']) && is_array($d['data'])) {
        $filas = $d['data'];
    } elseif (isset($d['id']) && !isset($d[0])) {
        $filas = [$d];
    }
    $comparar('pull.filas', $filas, $n->filas);

    // Beds24ExchangeClient::send(), antes: fusión y paginación
    $comparar('pull.cliente.data', isset($d['data']) && is_array($d['data']) ? $d['data'] : null, $n->datos);
    $siguiente = isset($d['pages']['nextPageExists']) && $d['pages']['nextPageExists'] === true && !empty($d['pages']['nextPageLink'])
        ? $d['pages']['nextPageLink'] : null;
    $comparar('pull.cliente.siguientePagina', $siguiente, $n->siguientePagina);

    // BookingsPullHandler::handleSuccess(), antes: la etiqueta de la fila en los errores
    foreach ($filas as $fila) {
        $contar('pull reservas: filas');
        $viejo = sprintf('%s', is_array($fila) ? ($fila['id'] ?? $fila['bookId'] ?? 'unknown') : 'unknown');
        $bookId = is_array($fila) ? ($fila['id'] ?? $fila['bookId'] ?? null) : null;
        $comparar('pull.handler.bookId', $viejo, is_scalar($bookId) ? (string) $bookId : 'unknown');
    }
}

// ── 5 y 6. Mensajes y facturas recibidos: las listas ya repartidas, y la clave de reparto ──
foreach (['msg_beds24_receive_queue' => 'mensajes', 'pms_beds24_invoice_receive_queue' => 'facturas'] as $tabla => $que) {
    foreach ($respuestas($db, $tabla) as $d) {
        $contar("{$que} recibidos: respuestas");
        $n = Beds24Respuesta::fromArray($d);
        $comparar("{$que}.fallo", isset($d['success']) && $d['success'] === false, $n->declaraFallo);
        $comparar("{$que}.data", isset($d['data']) && is_array($d['data']) ? $d['data'] : null, $n->datos);

        // Lo guardado es la lista de elementos (o, si el handler no llegó a escribir, el sobre).
        $elementos = array_is_list($d) ? $d : (is_array($d['data'] ?? null) ? $d['data'] : []);
        foreach ($elementos as $e) {
            if (!is_array($e)) {
                continue;
            }
            $contar("{$que} recibidos: elementos");
            $comparar("{$que}.bookingId", (string) ($e['bookingId'] ?? ''), Beds24Respuesta::bookingIdDe($e));
        }
    }
}

// ── 7. WhatsApp: los cuerpos crudos de Meta, por el cliente, la estrategia y el handler ──
$errorComoExito = 0;
foreach ($respuestas($db, 'msg_whatsapp_meta_send_queue') as $d) {
    foreach (array_is_list($d) ? $d : [$d] as $cuerpo) {
        $contar('WhatsApp: cuerpos de Meta');

        // WhatsappMetaClient::send(), antes: la fila normalizada
        $decoded = is_array($cuerpo) ? $cuerpo : [];
        if (isset($decoded['error'])) {
            $filaVieja = ['status' => 'error', 'message' => $decoded['error']['message'] ?? 'Error de Meta API', 'error_code' => $decoded['error']['code'] ?? null];
        } else {
            $filaVieja = ['status' => 'success', 'messageId' => $decoded['messages'][0]['id'] ?? null, 'raw' => $decoded];
        }
        $r = RespuestaGraphMeta::fromArray($decoded);
        $filaNueva = $r->hayError
            ? ['status' => 'error', 'message' => $r->errorMensaje ?? 'Error de Meta API', 'error_code' => $r->errorCodigo]
            : ['status' => 'success', 'messageId' => $r->idMensaje, 'raw' => $decoded];
        $comparar('meta.cliente.fila', $filaVieja, $filaNueva);

        // WhatsappMetaSendMappingStrategy::parseResponse(), antes, sobre la fila
        $esError = isset($filaVieja['error']);
        $ridViejo = !$esError && isset($filaVieja['messages'][0]['id']) ? $filaVieja['messages'][0]['id'] : null;
        $e = RespuestaGraphMeta::fromArray($filaNueva);
        $comparar('meta.estrategia.exito', !$esError, !$e->hayError);
        $comparar('meta.estrategia.remoteId', $ridViejo, $e->hayError ? null : $e->idMensaje);
        if ($filaVieja['status'] === 'error' && !$esError) {
            ++$errorComoExito;
        }

        // WhatsappMetaSendHandler::handleSuccess(), antes
        $idViejo = $filaVieja['messageId'] ?? null;
        $idNuevo = $filaNueva['messageId'] ?? null;
        $comparar('meta.handler.remoteId', $idViejo ? (string) $idViejo : null, is_string($idNuevo) && $idNuevo !== '' ? $idNuevo : null);
    }
}

// ── 8. Correo ──
foreach ($respuestas($db, 'msg_email_send_queue') as $d) {
    $contar('correo: respuestas');
    $n = ResultadoDelCorreo::fromArray($d);
    $enviados = is_array($d['enviados'] ?? null) ? $d['enviados'] : [];
    $fallos = is_array($d['fallos'] ?? null) ? $d['fallos'] : [];
    foreach (array_unique(array_merge(array_keys($enviados), array_keys($fallos))) as $id) {
        $id = (string) $id;
        $comparar('correo.fallo', $fallos[$id] ?? null, $n->fallos[$id] ?? null);
        $comparar('correo.remoteId', $enviados[$id]['messageId'] ?? null, $n->enviados[$id] ?? null);
        $comparar('correo.extraData', $enviados[$id] ?? [], array_key_exists($id, $n->enviados) ? ['messageId' => $n->enviados[$id]] : []);
    }
}

// ── 9. Credenciales de Meta: el TIPO de lo guardado (sin imprimir un solo valor) ──
foreach ($db->iterateColumn('SELECT credentials FROM exchange_meta_config') as $json) {
    $c = json_decode(is_string($json) ? $json : '', true);
    if (!is_array($c)) {
        continue;
    }
    foreach (['apiKey', 'wabaId', 'phoneId', 'verifyToken', 'appSecret'] as $clave) {
        $contar('credenciales de Meta');
        $v = $c[$clave] ?? null;
        $comparar("credencial.{$clave}", $v === null || is_string($v) ? $v : '«' . get_debug_type($v) . '»', Lee::texto($v));
    }
}

foreach ($cuenta as $que => $cuantos) {
    printf("%-36s %7d\n", $que, $cuantos);
}
printf("\nWhatsApp: errores de Meta que la estrategia da por enviados (sin cambio, ver docs/Mensajeria.md): %d\n", $errorComoExito);

if ($difs === []) {
    echo "\n✅ Idénticos: cada campo que leía el motor de intercambio sale igual por los DTO.\n";
    exit(0);
}
echo "\n❌ Campos que difieren:\n";
foreach ($difs as $campo => $cuantos) {
    printf("  %-36s %7d\n", $campo, $cuantos);
}
exit(1);
