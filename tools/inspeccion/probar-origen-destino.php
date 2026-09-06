<?php

declare(strict_types=1);

/**
 * ¿Se puede deducir DÓNDE se recoge y DÓNDE se deja a un pasajero, sin escribirlo a mano?
 *
 * Sólo lectura. Aplica el algoritmo al itinerario real y enseña dónde acierta solo y dónde
 * necesita una heurística.
 *
 * ── La idea ─────────────────────────────────────────────────────────────────
 * La columna vertebral son los ALOJAMIENTOS: con `fecha_servicio` + `cantidad_componente`
 * (noches) se sabe dónde duerme el pasajero CADA noche. Y de ahí sale todo lo demás:
 *
 *     origen  del día D = donde durmió la noche D−1
 *     destino del día D = donde dormirá la noche D
 *
 * Un vuelo o un tren PARTE el día en dos: lo de antes termina en el punto de salida, lo de
 * después empieza en el de llegada.
 */

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

$entorno = (string) ($_SERVER['APP_ENV'] ?? 'dev');
$kernel = new App\Kernel($entorno, $entorno !== 'prod');
$kernel->boot();
$c = $kernel->getContainer()->get('doctrine')->getConnection();

$nombre = $argv[1] ?? 'Nune';
$f = $c->fetchAssociative('SELECT id, nombre_grupo FROM cotizacion_file WHERE nombre_grupo LIKE ?', ["%$nombre%"]);

if (!$f) { echo "No hay expediente que case con «$nombre».\n"; exit(1); }

$servicios = $c->fetchAllAssociative(
    "SELECT fecha_servicio f, hora_componente h, tipo_componente t, descripcion_servicio d,
            cantidad_componente n, COALESCE(prestador_override_nombre, prestador_nombre) p
     FROM operacion_servicio WHERE file_id = ?
     ORDER BY fecha_servicio, COALESCE(hora_componente, '00:00')", [$f['id']]);

// ── 1 · Dónde duerme cada noche ─────────────────────────────────────────────
$noches = [];
foreach ($servicios as $s) {
    if ($s['t'] !== 'alojamiento' || $s['f'] === null) { continue; }

    $n = max(1, (int) ($s['n'] ?: 1));
    for ($i = 0; $i < $n; $i++) {
        $noches[(new DateTimeImmutable($s['f']))->modify("+$i day")->format('Y-m-d')] = (string) $s['p'];
    }
}

echo $f['nombre_grupo'], " — dónde duerme cada noche\n";
ksort($noches);
$huecos = 0;
$anterior = null;
foreach ($noches as $dia => $hotel) {
    if ($anterior !== null) {
        $esperado = (new DateTimeImmutable($anterior))->modify('+1 day')->format('Y-m-d');
        if ($dia !== $esperado) { $huecos++; printf("  ⚠️  HUECO entre %s y %s\n", $anterior, $dia); }
    }
    printf("  %s  %s\n", $dia, $hotel);
    $anterior = $dia;
}
printf("\n  cadena continua: %s\n\n", $huecos === 0 ? 'SÍ, sin huecos' : "NO, $huecos hueco(s)");

// ── 2 · Origen y destino de cada servicio ───────────────────────────────────
$saltos = ['vuelo', 'tren'];

echo "── Origen y destino deducidos\n";
printf("%-11s %-6s %-13s %-34s %-26s %s\n", 'FECHA', 'HORA', 'TIPO', 'SERVICIO', 'RECOGE EN', 'DEJA EN');
echo str_repeat('─', 132), "\n";

foreach ($servicios as $s) {
    if ($s['t'] === 'alojamiento' || $s['f'] === null) { continue; }

    $dia = $s['f'];
    $ayer = (new DateTimeImmutable($dia))->modify('-1 day')->format('Y-m-d');

    $origen  = $noches[$ayer] ?? '⟨llegada al país⟩';
    $destino = $noches[$dia]  ?? '⟨salida del país⟩';

    // El salto del día parte la jornada: lo anterior acaba en el punto de salida, lo posterior
    // empieza en el de llegada. El punto exacto —qué aeropuerto, qué estación— es lo único que
    // no es determinista: ver el informe.
    foreach ($servicios as $otro) {
        if ($otro['f'] !== $dia || !in_array($otro['t'], $saltos, true) || $otro['h'] === null || $s['h'] === null) { continue; }

        if ($s['h'] < $otro['h'] && $s['t'] !== $otro['t']) {
            $destino = sprintf('⟨punto de salida del %s %s⟩', $otro['t'], $otro['h']);
        } elseif ($s['h'] > $otro['h'] && $s['t'] !== $otro['t']) {
            $origen = sprintf('⟨punto de llegada del %s %s⟩', $otro['t'], $otro['h']);
        }
    }

    printf("%-11s %-6s %-13s %-34s %-26s %s\n",
        $dia, $s['h'] ?? '—', $s['t'], mb_substr((string) $s['d'], 0, 33),
        mb_substr($origen, 0, 25), $destino);
}
