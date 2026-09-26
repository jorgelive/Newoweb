<?php

declare(strict_types=1);

/**
 * El cierre automático de `CotizacionPedido` al vincular el primer expediente a una conversación.
 *
 * Es lo que ni `php -l` ni un test unitario pueden ver: el camino real completo —guardar un
 * `CotizacionFile` con identificadores dispara `CotizacionFileConversacionListener`, que llama a
 * `MessageConversationFactory::upsertFromContext()`, que reparte a los
 * `SincronizadorDeEnlaceInterface` registrados, entre ellos `CotizacionSincronizadorDeEnlace`—
 * necesita el EntityManager y el contenedor real. En transacción con rollback: no queda nada
 * escrito.
 *
 * ⚠️ El expediente nace SIN correo y se le añade después, aposta: con correo desde el alta, el
 * propio flush que lo crea dispararía el enlace automático ANTES de que exista el pedido —
 * exactamente al revés del caso real, donde el pedido siempre es anterior al expediente.
 *
 * Comprueba dos cosas:
 *   1. Un pedido pendiente de la conversación se cierra solo, con el expediente enlazado y sin
 *      autor humano.
 *   2. Un pedido YA cerrado a mano no se toca — el cierre automático no debe pisar una firma que
 *      ya existía.
 *
 * Uso: php tools/pruebas/probar-pedido-cotizacion.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionPedido;
use App\Cotizacion\Enum\FileModoEnum;
use App\Entity\Maestro\MaestroIdioma;
use App\Entity\User;
use App\Message\Entity\MessageConversation;
use Doctrine\ORM\EntityManagerInterface;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$_SERVER['APP_ENV'] = 'dev';
$kernel = new App\Kernel('dev', true);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();

$conn = $em->getConnection();
$conn->beginTransaction();

$fallos = [];

try {
    // 1. El expediente nace SIN correo: así el listener automático (`getIdentificadores()`
    // vacío) lo ignora en este primer flush, y la conversación queda sin enlace todavía — el
    // estado real de Eduardo cuando pidió los tours.
    $file = (new CotizacionFile())
        ->setNombreGrupo('Prueba de rollback — Eduardo')
        ->setModo(FileModoEnum::ESTANDAR);
    $em->persist($file);

    $idioma = $em->getRepository(MaestroIdioma::class)->find(MaestroIdioma::DEFAULT_IDIOMA)
        ?? $em->getRepository(MaestroIdioma::class)->findOneBy([]);

    $conversacion = (new MessageConversation('cotizacion_file', (string) $file->getId()))
        ->setIdioma($idioma);
    $em->persist($conversacion);
    $em->flush();

    // 2. El pedido, colgado de esa conversación — todavía sin expediente vinculado.
    $pendiente = new CotizacionPedido((string) $conversacion->getId(), 'Valle Sagrado lunes 5 para 4');
    $em->persist($pendiente);

    // El de control: ya cerrado a mano, con firma humana. El cierre automático no puede pisarlo.
    $usuario = $em->getRepository(User::class)->findOneBy([]);
    $cerradoAMano = new CotizacionPedido((string) $conversacion->getId(), 'Pedido ya resuelto antes');
    if ($usuario !== null) {
        $cerradoAMano->setEfectuadaPor($usuario);
    }
    $cerradoAMano->setEfectuadaAt(new DateTimeImmutable('2026-01-01 10:00:00'));
    $em->persist($cerradoAMano);
    $em->flush();

    // 3. El acto que en producción dispara el cierre: alguien completa el expediente con un
    // correo. Este flush es el que hace ONFLUSH/POSTFLUSH → listener → factory → sincronizador.
    $file->setEmail('prueba-rollback@example.test');
    $em->flush();

    $em->refresh($pendiente);
    $em->refresh($cerradoAMano);

    if ($pendiente->isPendiente()) {
        $fallos[] = 'El pedido pendiente NO se cerró al vincular el expediente.';
    }
    if ($pendiente->getFile()?->getId()?->equals($file->getId()) !== true) {
        $fallos[] = 'El pedido se cerró pero no quedó apuntando al expediente correcto.';
    }
    if (!$pendiente->isCerradoAutomaticamente()) {
        $fallos[] = 'El pedido se cerró pero con autor humano: el cierre automático no debe firmarlo.';
    }

    $cerradoAntes = $cerradoAMano->getEfectuadaAt()?->format('Y-m-d H:i:s');
    if ($cerradoAntes !== '2026-01-01 10:00:00') {
        $fallos[] = sprintf('El pedido ya cerrado cambió su fecha: ahora dice %s.', $cerradoAntes ?? '(null)');
    }
    if ($cerradoAMano->getFile() !== null) {
        $fallos[] = 'El pedido ya cerrado a mano quedó enlazado al expediente nuevo: no debía tocarse.';
    }

    if ($fallos === []) {
        echo "✅ Idénticos al esperado: el pendiente se cerró solo, el cerrado a mano no se tocó.\n";
    } else {
        echo "❌ Falló:\n";
        foreach ($fallos as $f) {
            echo "  - $f\n";
        }
    }
} finally {
    $conn->rollBack();
    echo "(rollback hecho: nada de esto quedó escrito)\n";
}

exit($fallos === [] ? 0 : 1);
