<?php
require '/Users/jorgegomez/Sites/Newoweb/vendor/autoload.php';

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;

$estados = [
    PmsEventoEstado::CODIGO_PENDIENTE,
    PmsEventoEstado::CODIGO_CONFIRMADA,
    PmsEventoEstado::CODIGO_REQUERIMIENTO,
    PmsEventoEstado::CODIGO_ABIERTO,
    PmsEventoEstado::CODIGO_CANCELADA,
    PmsEventoEstado::CODIGO_BLOQUEO,
];

$corto = fn (string $e): string => substr($e, 0, 6);

echo "MATRIZ transicionOtaPermitida()  (fila = desde, columna = hasta)\n\n";
printf("%-14s", 'desde \ hasta');
foreach ($estados as $h) { printf("%-8s", $corto($h)); }
echo "\n";

foreach ($estados as $d) {
    printf("%-14s", $corto($d));
    foreach ($estados as $h) {
        printf("%-8s", PmsEventoEstado::transicionOtaPermitida($d, $h) ? ' ok' : ' --');
    }
    echo "\n";
}

// ---------------------------------------------------------------------------
// La regla debe coincidir con lo que el LISTENER de seguridad deja pasar y con
// lo que el desplegable de `util` ofrece. Aquí se replican sus condiciones y se
// comparan una a una: si divergen, el operador ve una opción que el listener
// rechazará con un 403.
// ---------------------------------------------------------------------------
$listenerPermite = static function (string $desde, string $hasta): bool {
    if ($desde === $hasta) return true;
    if ($desde === PmsEventoEstado::CODIGO_CANCELADA) return false;                 // Regla 1
    if ($desde === PmsEventoEstado::CODIGO_ABIERTO
        && $hasta === PmsEventoEstado::CODIGO_CANCELADA) return true;               // Regla 2
    if (in_array($hasta, PmsEventoCalendario::OTA_ESTADOS_NO_SELECCIONABLES, true)) return false; // Regla 3
    return PmsEventoEstado::transicionOtaPermitida($desde, $hasta);                 // Regla 4 (red final)
};

// Réplica FIEL de util/src/types/pmsReservaModel.ts::filtrarEstadosDisponibles().
$vueOfrece = static function (string $desde, string $hasta): bool {
    if ($desde === PmsEventoEstado::CODIGO_CANCELADA) return $hasta === PmsEventoEstado::CODIGO_CANCELADA;
    if ($desde === PmsEventoEstado::CODIGO_BLOQUEO)   return $hasta === PmsEventoEstado::CODIGO_BLOQUEO;
    if ($desde === PmsEventoEstado::CODIGO_ABIERTO) {
        return in_array($hasta, PmsEventoCalendario::OTA_ABIERTO_ESTADOS_SELECCIONABLES, true);
    }
    return !in_array($hasta, PmsEventoCalendario::OTA_ESTADOS_NO_SELECCIONABLES, true);
};

echo "\nDISCREPANCIAS regla vs listener vs desplegable:\n";
$fallos = 0;

foreach ($estados as $d) {
    foreach ($estados as $h) {
        $regla = PmsEventoEstado::transicionOtaPermitida($d, $h);
        $list  = $listenerPermite($d, $h);
        $vue   = $vueOfrece($d, $h);

        if ($regla !== $list || $regla !== $vue) {
            $fallos++;
            printf(
                "  %-14s -> %-14s  regla=%-3s listener=%-3s vue=%-3s\n",
                $d, $h,
                $regla ? 'ok' : '--',
                $list ? 'ok' : '--',
                $vue ? 'ok' : '--',
            );
        }
    }
}

echo $fallos === 0 ? "  ninguna: los tres coinciden\n" : "  $fallos discrepancias\n";

// ---------------------------------------------------------------------------
// El puente con Beds24: el mapper traduce el `status` crudo del canal.
// ---------------------------------------------------------------------------
echo "\nTRADUCCIÓN desdeCodigoBeds24():\n";
foreach (['new', 'confirmed', 'request', 'inquiry', 'cancelled', 'black', '', null] as $c) {
    printf("  %-12s -> %s\n", var_export($c, true), PmsEventoEstado::desdeCodigoBeds24($c) ?? 'null');
}

echo "\nCASOS DEL PUSH (desde = lo que dice el canal):\n";
$casos = [
    ['new', 'confirmed', 'la estancia cobrada: el caso que nos ocupa'],
    ['new', 'request', 'vivo -> vivo'],
    ['request', 'confirmed', 'vivo -> vivo'],
    ['confirmed', 'new', 'vivo -> vivo (degradar entre vivos)'],
    ['inquiry', 'cancelled', 'limpiar una consulta'],
    ['confirmed', 'cancelled', 'cancelar una reserva en firme'],
    ['cancelled', 'confirmed', 'RESUCITAR: el daño original'],
    ['cancelled', 'new', 'resucitar por otra vía'],
    ['inquiry', 'confirmed', 'ascender una consulta'],
    ['new', 'inquiry', 'degradar a consulta'],
    ['black', 'confirmed', 'bloqueo -> reserva'],
];

foreach ($casos as [$canal, $local, $desc]) {
    $ok = PmsEventoEstado::transicionOtaPermitida(
        PmsEventoEstado::desdeCodigoBeds24($canal),
        PmsEventoEstado::desdeCodigoBeds24($local),
    );
    printf("  %-10s -> %-10s  %-4s  %s\n", $canal, $local, $ok ? 'VIAJA' : 'no', $desc);
}
