<?php

declare(strict_types=1);

/**
 * ¿Qué dice `consultar_codigos` el DÍA DE LA LLEGADA?
 *
 * Lo que se comprueba, sobre una reserva real y EN TRANSACCIÓN CON ROLLBACK:
 *   1. Llegando hoy y con acceso abierto → `avisale_de_esto` trae la frase de PASOS
 *      («entrada autónoma»), viene `y_luego` y vienen los teléfonos en `contacto`.
 *   2. Ya dentro (día 2) → vuelve la frase de AVISO y NO hay `y_luego`. Los teléfonos
 *      siguen saliendo: eso es lo que sostiene el puntero que quedó en la guía.
 *   3. Llegando hoy y SIN adelanto → el `motivo` manda al teléfono y prohíbe el enlace,
 *      y `escalar_en_silencio` es true.
 *   4. Sin adelanto pero faltando días → el `motivo` vuelve a ser el del enlace, y el
 *      escalado se anuncia.
 *   6. De madrugada, ya pasada la medianoche de su llegada → sigue tratándosele como quien
 *      está llegando. Es el incidente original desplazado 15 minutos.
 *   7. Sin teléfonos configurados → NO se le prohíbe el enlace: sin teléfono esa rama
 *      dejaría al modelo sin ninguna salida, y sin salida se inventa una.
 *
 * Uso: php var/probar-llegada-hoy.php [localizador]
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Agent\Skill\Pms\ConsultarCodigosSkill;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsReserva;
use App\Agent\Access\AgentActor;
use Doctrine\ORM\EntityManagerInterface;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$_SERVER['APP_ENV'] = 'dev';
$kernel = new App\Kernel('dev', true);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
// La skill no es un servicio público: se arma a mano con sus tres dependencias reales.
$reloj = new Symfony\Component\Clock\MockClock('now', new DateTimeZone('America/Lima'));
$skill = new ConsultarCodigosSkill(
    $em,
    new App\Pms\Guia\PmsGuiaEstanciaResolver(),
    $kernel->getContainer()->getParameter('pax_book_guide_url'),
    $reloj,
);
$reloj->modify('today 15:00');

$localizador = $argv[1] ?? null;

$qb = $em->createQueryBuilder()
    ->select('r')
    ->from(PmsReserva::class, 'r')
    ->join(PmsEventoCalendario::class, 'e', 'WITH', 'e.reserva = r')
    ->join('e.pmsUnidad', 'u')
    ->where('u.codigoPuerta IS NOT NULL')
    ->setMaxResults(1);

if ($localizador !== null) {
    $qb->andWhere('r.localizador = :l')->setParameter('l', $localizador);
}

$reserva = $qb->getQuery()->getOneOrNullResult();

if (!$reserva instanceof PmsReserva) {
    exit("No hay ninguna reserva con casita y código de puerta.\n");
}

$evento = $reserva->getEventosActivosGuia()[0] ?? null;

if (!$evento instanceof PmsEventoCalendario) {
    exit("Esa reserva no tiene estancia activa.\n");
}

printf("Reserva %s — %s\n\n", (string) $reserva->getLocalizador(), (string) $reserva->getNombreApellido());

// El mismo actor que arma `AgentActorFactory` para un huésped que escribe por su chat.
$actor = App\Agent\Access\AgentActor::huesped('prueba', 'pms_reserva', (string) $reserva->getId());

$em->getConnection()->beginTransaction();

$fallos = 0;
$comprobar = static function (string $caso, bool $ok, string $detalle = '') use (&$fallos): void {
    printf("  %s %s%s\n", $ok ? '✅' : '❌', $caso, $detalle !== '' ? " — {$detalle}" : '');
    if (!$ok) {
        ++$fallos;
    }
};

$mover = static function (string $cuando) use ($em, $evento): void {
    $inicio = new DateTimeImmutable($cuando . ' 14:00');
    $em->getConnection()->executeStatement(
        'UPDATE pms_evento_calendario SET inicio = ?, fin = ? WHERE id = ?',
        [$inicio->format('Y-m-d H:i:s'), $inicio->modify('+3 days')->format('Y-m-d H:i:s'), $evento->getId()->toBinary()]
    );
    $em->clear();
};

$pagar = static function (string $estado) use ($em, $evento): void {
    $em->getConnection()->executeStatement(
        'UPDATE pms_evento_calendario SET estado_pago_id = ? WHERE id = ?',
        [$estado, $evento->getId()->toBinary()]
    );
    $em->clear();
};

$correr = static fn (): array => $skill->ejecutar([], $actor)->datos ?? [];

try {
    echo "1) Llega HOY, con acceso abierto\n";
    $mover('today');
    $pagar('pago-total');
    $r = $correr();
    $comprobar('avisale_de_esto trae la frase de llegada', str_contains((string) ($r['avisale_de_esto'] ?? ''), 'autónoma'), substr((string) ($r['avisale_de_esto'] ?? '—'), 0, 60));
    $comprobar('viene y_luego', isset($r['y_luego']));
    $comprobar('vienen los teléfonos', isset($r['contacto']['telefono']), (string) ($r['contacto']['telefono'] ?? '—'));

    // Ojo al elegir el caso: a 5 días la ventana está CERRADA y no hay código que acompañar.
    // El dominio real de AVISO es la víspera y los días ya dentro, que es lo que se prueba.
    echo "\n2) Ya lleva un día dentro\n";
    $mover('yesterday');
    $r = $correr();
    $frase = (string) ($r['avisale_de_esto'] ?? '');
    $comprobar('avisale_de_esto vuelve al aviso', $frase !== '' && !str_contains($frase, 'autónoma'), substr($frase !== '' ? $frase : '—', 0, 60));
    $comprobar('NO viene y_luego', !isset($r['y_luego']));
    $comprobar('los teléfonos siguen saliendo', isset($r['contacto']['telefono']));

    echo "\n3) Llega HOY y sin adelanto\n";
    $mover('today');
    $pagar('no-pagado');
    $r = $correr();
    $motivo = (string) ($r['motivo'] ?? '');
    $comprobar('el motivo manda al teléfono', str_contains($motivo, 'escriba o llame'));
    $comprobar('el motivo PROHÍBE el enlace', str_contains($motivo, 'ni le pases el enlace'));
    $comprobar('escalar_en_silencio', ($r['escalar_en_silencio'] ?? false) === true);

    echo "\n4) Sin adelanto, faltando 5 días\n";
    $mover('+5 days');
    $r = $correr();
    $motivo = (string) ($r['motivo'] ?? '');
    $comprobar('vuelve el motivo del enlace', str_contains($motivo, 'consultar_cuenta'));
    $comprobar('el escalado se anuncia', !isset($r['escalar_en_silencio']));

    // La excepción que el escalado del caso 3 existe para pedir: un operador evalúa y lo pone,
    // y los códigos se abren solos. Si esto dejara de ser cierto, el teléfono no resolvería nada.
    echo "\n5) Llega HOY con «pago-alojamiento» (la excepción manual)\n";
    $mover('today');
    $pagar('pago-alojamiento');
    $r = $correr();
    $comprobar('los códigos se abren', ($r['disponible'] ?? false) === true);
    $comprobar('y con la frase de llegada', str_contains((string) ($r['avisale_de_esto'] ?? ''), 'autónoma'));

    // 🌙 El que llega de madrugada. Con el corte en el día natural perdía la frase de llegada,
    // el silencio del escalado y la prohibición de cobrarle: el incidente original, a las 00:15.
    echo "\n6) Llegó AYER y escribe a las 00:15, ya pasada la medianoche\n";
    $mover('yesterday');
    $pagar('no-pagado');
    $reloj->modify('today 00:15');
    $r = $correr();
    $motivo = (string) ($r['motivo'] ?? '');
    $comprobar('sigue mandando al teléfono', str_contains($motivo, 'escriba o llame'));
    $comprobar('sigue prohibiendo el enlace', str_contains($motivo, 'ni le pases el enlace'));
    $comprobar('sigue en silencio', ($r['escalar_en_silencio'] ?? false) === true);

    // ☎️ Sin teléfono no se puede prohibir el enlace: sería dejarlo sin ninguna salida.
    echo "\n7) Llega HOY, sin adelanto y SIN teléfonos configurados\n";
    $reloj->modify('today 15:00');
    $mover('today');
    $em->getConnection()->executeStatement('UPDATE pms_establecimiento SET telefono_atencion = NULL, telefono_yape = NULL');
    $em->clear();
    $r = $correr();
    $motivo = (string) ($r['motivo'] ?? '');
    $comprobar('NO viene contacto', !isset($r['contacto']));
    $comprobar('cae al motivo del enlace', str_contains($motivo, 'consultar_cuenta'));
    $comprobar('no manda a un teléfono que no existe', !str_contains($motivo, 'escriba o llame'));

    // Y el otro lado: pasada la madrugada ya no está llegando, está instalado.
    echo "\n8) Llegó AYER y escribe a las 10:00 de la mañana\n";
    $mover('yesterday');
    $reloj->modify('today 10:00');
    $r = $correr();
    $comprobar('vuelve el motivo del enlace', str_contains((string) ($r['motivo'] ?? ''), 'consultar_cuenta'));
    $comprobar('el escalado se anuncia', !isset($r['escalar_en_silencio']));
} finally {
    $em->getConnection()->rollBack();
    echo "\n↩️  Rollback hecho: no se ha tocado nada.\n";
}

exit($fallos === 0 ? 0 : 1);
