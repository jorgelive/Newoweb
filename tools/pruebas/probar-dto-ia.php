<?php

declare(strict_types=1);

/**
 * Las lecturas tipadas del agente leen LO MISMO que las expresiones crudas a las que sustituyen, sobre
 * los datos reales que hay guardados.
 *
 * ── Qué hay guardado, y qué no ──────────────────────────────────────────────
 * - **Respuestas de los modelos (DeepSeek, Gemini): NADA.** Ni en la base ni en `info.log`, que sólo
 *   guarda las cifras ya calculadas. Esa frontera se compara en `RespuestaDelModeloTest` (payloads con
 *   la forma de cada API, contra una copia literal de la lectura vieja) y en `LineasDeConsumoTest`
 *   (el motor entero, con las líneas de consumo exactas; pasa igual con el código viejo y el nuevo).
 * - **Huellas de la escalera de temas** (`msg_message.metadata.temas_peldano`): sí. Se comparan aquí,
 *   `EscaleraDeTemas::huellasDe()` contra la lectura de antes.
 * - **Sobres de Alexa**: sólo si `DiagnosticoAlexa` llegó a registrar alguno en `info.log` (contexto
 *   `sobre`). Se comparan aquí, `PeticionAlexa::fromArray()` contra el `desde()` de antes.
 *
 * Sólo LEE: no arranca el kernel (que podría escribir caché o log), va a la base por PDO con un
 * SELECT, y no imprime datos personales —sólo campos y cuentas—.
 *
 * Uso, en el servidor y ANTES de desplegar, sin tocar el árbol de producción:
 *   mkdir -p /tmp/dto-ia && cp -r <worktree>/{src,tools} /tmp/dto-ia/
 *   APP_RAIZ=/var/www/openperu.pe CLASES_NUEVAS=/tmp/dto-ia/src php /tmp/dto-ia/tools/pruebas/probar-dto-ia.php
 *   rm -rf /tmp/dto-ia
 *
 * Después de desplegar basta `php tools/pruebas/probar-dto-ia.php`.
 *
 * Ver `docs/TiposDeFrontera.md` §3 y `docs/Agent.md` §3.4.
 */

use App\Agent\Alexa\PeticionAlexa;
use App\Agent\Service\EscaleraDeTemas;
use App\Message\Entity\Message;
use Symfony\Component\Dotenv\Dotenv;

$raiz = getenv('APP_RAIZ') ?: dirname(__DIR__, 2);
$nuevas = getenv('CLASES_NUEVAS') ?: null;

require $raiz . '/vendor/autoload.php';
(new Dotenv())->bootEnv($raiz . '/.env');

// Las clases nuevas por DELANTE de composer —que se registra con `prepend`, así que el nuestro va
// después de él—: con el mismo nombre, gana la primera que se carga.
if ($nuevas !== null) {
    spl_autoload_register(static function (string $clase) use ($nuevas): void {
        if (str_starts_with($clase, 'App\\')) {
            $archivo = $nuevas . '/' . str_replace('\\', '/', substr($clase, 4)) . '.php';
            if (is_file($archivo)) {
                require $archivo;
            }
        }
    }, true, true);
}

$url = parse_url(is_string($_SERVER['DATABASE_URL'] ?? null) ? $_SERVER['DATABASE_URL'] : '');
if (!is_array($url) || !isset($url['host'], $url['path'])) {
    fwrite(STDERR, "Sin DATABASE_URL legible.\n");
    exit(1);
}
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $url['host'], $url['port'] ?? 3306, ltrim($url['path'], '/')),
    urldecode($url['user'] ?? ''),
    urldecode($url['pass'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$difs = [];
$anotar = static function (string $campo, mixed $viejo, mixed $nuevo) use (&$difs): void {
    if ($viejo !== $nuevo) {
        $difs[$campo] = ($difs[$campo] ?? 0) + 1;
    }
};

echo 'Clases: ', (new ReflectionClass(EscaleraDeTemas::class))->getFileName(), "\n\n";

// ── 1. Huellas de la escalera ──────────────────────────────────────────────
$huellasDe = new ReflectionMethod(EscaleraDeTemas::class, 'huellasDe');
$escalera = (new ReflectionClass(EscaleraDeTemas::class))->newInstanceWithoutConstructor();
$cuenta = ['mensajes' => 0, 'huellas_viejo' => 0, 'huellas_nuevo' => 0, 'tema_no_texto' => 0];

$filas = $pdo->query(
    "SELECT metadata FROM msg_message
      WHERE JSON_CONTAINS_PATH(metadata, 'one', '$.temas_peldano', '$.tema_peldano')"
);
foreach ($filas ?: [] as $fila) {
    $metadata = json_decode(is_string($fila['metadata'] ?? null) ? $fila['metadata'] : '', true);
    if (!is_array($metadata)) {
        continue;
    }
    ++$cuenta['mensajes'];

    // La lectura de ANTES, tal cual.
    $viejas = is_array($metadata['temas_peldano'] ?? null)
        ? array_values(array_filter($metadata['temas_peldano'], 'is_array'))
        : (is_array($metadata['tema_peldano'] ?? null) ? [$metadata['tema_peldano']] : []);

    $mensaje = (new Message())->setMetadata($metadata);
    /** @var list<array{tema: ?string, peldano: int}> $nuevas */
    $nuevas = $huellasDe->invoke($escalera, $mensaje);

    $cuenta['huellas_viejo'] += count($viejas);
    $cuenta['huellas_nuevo'] += count($nuevas);

    foreach ($viejas as $i => $h) {
        $n = $nuevas[$i] ?? null;
        if ($n === null) {
            $anotar('huella (falta)', 1, 0);
            continue;
        }
        // El tema sólo se usa comparándolo con un uuid en texto: lo que no es texto no casaba nunca.
        $tema = $h['tema'] ?? null;
        $anotar('huella.tema', is_string($tema) ? $tema : null, $n['tema']);
        $anotar('huella.peldano', (int) ($h['peldano'] ?? 0), $n['peldano']);
        if ($tema !== null && !is_string($tema)) {
            ++$cuenta['tema_no_texto'];
        }
    }
}

// ── 2. Sobres de Alexa registrados por DiagnosticoAlexa ────────────────────
$cuenta['sobres_alexa'] = 0;
$logs = array_merge(glob($raiz . '/var/log/info.log-*.gz') ?: [], glob($raiz . '/var/log/info.log*') ?: []);
foreach (array_unique($logs) as $log) {
    $f = str_ends_with($log, '.gz') ? gzopen($log, 'r') : fopen($log, 'r');
    if ($f === false) {
        continue;
    }
    while (($linea = str_ends_with($log, '.gz') ? gzgets($f) : fgets($f)) !== false) {
        if (!str_contains($linea, '"sobre":')) {
            continue;
        }
        $sobre = json_decode($linea, true)['context']['sobre'] ?? null;
        if (!is_array($sobre)) {
            continue;
        }
        ++$cuenta['sobres_alexa'];

        $p = PeticionAlexa::fromArray($sobre);
        $pet = is_array($sobre['request'] ?? null) ? $sobre['request'] : [];
        $int = is_array($pet['intent'] ?? null) ? $pet['intent'] : [];
        $anotar('alexa.tipo', (string) ($pet['type'] ?? ''), $p->tipo);
        $anotar('alexa.intent', isset($int['name']) ? (string) $int['name'] : null, $p->intent);
        $anotar('alexa.timestamp', isset($pet['timestamp']) ? (string) $pet['timestamp'] : null, $p->timestamp);
        $anotar('alexa.idioma', isset($pet['locale']) ? (string) $pet['locale'] : null, $p->idioma);
    }
    str_ends_with($log, '.gz') ? gzclose($f) : fclose($f);
}

foreach ($cuenta as $k => $v) {
    printf("%-16s %d\n", $k, $v);
}
echo "\n", $difs === [] ? "✅ Idénticos campo a campo.\n" : "❌ Diferencias:\n";
foreach ($difs as $campo => $n) {
    printf("   %-24s %d\n", $campo, $n);
}

exit($difs === [] ? 0 : 1);
