<?php

declare(strict_types=1);

/**
 * ¿Detecta el sincronizador que una reserva perdió una casita, y lo anota UNA sola vez?
 *
 * Es la comprobación que los tests unitarios no pueden dar: allí se llama a `anotarCancelacionParcial()`
 * a mano, y aquí se ejercita el ciclo real —cancelar un evento, recalcular, guardar— con el
 * sincronizador de producción y entidades de la base.
 *
 * Lo que más se vigila es la **idempotencia**: el sincronizador corre en CADA cambio de la
 * reserva, así que si la casita perdida siguiera contando como cubierta, cada guardado anotaría
 * otra cancelación parcial y el huésped recibiría el mismo aviso una y otra vez.
 *
 * Todo dentro de una transacción que se deshace. Uso: php var/probar-parcial.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Contract\ConversationMilestoneInterface as Hito;
use App\Message\Contract\HitoDeAsunto;
use App\Pms\Entity\PmsConversacionEnlace;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Message\PmsReservaMessageContext;
use App\Pms\Service\Message\PmsSincronizadorDeEnlace;
use Doctrine\ORM\EntityManagerInterface;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$_SERVER['APP_ENV'] = 'dev';
$kernel = new App\Kernel('dev', true);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();

// ⚠️ Tres dependencias desde que se le añadió `PmsProveedorDeEnlaces`; con dos reventaba con
// `Too few arguments` y la prueba llevaba tiempo sin correr.
$sincronizador = new PmsSincronizadorDeEnlace(
    $em,
    new App\Pms\Service\Message\PmsHitosDeEstancia(),
    new App\Pms\Service\Message\PmsProveedorDeEnlaces($em),
);

// Una reserva con DOS casitas a la vez: el caso del grupo, que es donde perder una tiene sentido.
$id = $em->getConnection()->fetchOne(
    "SELECT BIN_TO_UUID(e.reserva_id)
       FROM pms_evento_calendario e
      WHERE e.reserva_id IS NOT NULL AND e.evento_origen_id IS NULL
        AND e.estado_id IN ('pendiente','confirmada','requerimiento')
        -- Sólo DIRECTAS: `PmsEventoCalendarioSecurityListener` prohíbe cancelar a mano una
        -- reserva de OTA, y con razón —eso se hace en el canal—. Intentarlo aquí no probaba
        -- nada del sincronizador, sólo que esa guarda funciona.
        AND e.is_ota = 0
      GROUP BY e.reserva_id
     HAVING COUNT(DISTINCT e.pms_unidad_id) > 1
      LIMIT 1"
);

if ($id === false) {
    exit("\n⚠️  No hay ninguna reserva con dos casitas en esta base.\n");
}

$reserva = $em->getRepository(PmsReserva::class)->find($id);
$conversacion = $em->getRepository(App\Message\Entity\MessageConversation::class)
    ->findOneBy(['contextType' => PmsConversacionEnlace::CONTEXT_TYPE, 'contextId' => $id]);

if ($conversacion === null) {
    exit("\n⚠️  Esa reserva no tiene conversación; sin ella no hay enlace que sincronizar.\n");
}

$contexto = new PmsReservaMessageContext($reserva);

printf("\n=== Reserva de pruebas: %s ===\n", $reserva->getNombreCliente());

$em->getConnection()->beginTransaction();

try {
    $sincronizador->sincronizar($conversacion, $contexto);
    $em->flush();

    // ⚠️ Los asuntos ya NO cuelgan de la conversación: desde el 20/08/2026 cada dominio los
// aporta por `ProveedorDeEnlacesInterface`, para que el núcleo de mensajería no conozca al PMS.
$enlaces = (new App\Pms\Service\Message\PmsProveedorDeEnlaces($em))->paraConversacion($conversacion);
$enlace = $enlaces[0] ?? null;
    printf("  casitas cubiertas: %s\n", implode(', ', $enlace->unidadesDerivadas()));

    // Se cancela UNA de las casitas, como haría el operador.
    $cancelada = null;

    foreach ($reserva->getEventosCalendario() as $evento) {
        // Se salta lo de OTA: cancelarlo a mano lo prohíbe `PmsEventoCalendarioSecurityListener`
        // -eso se hace en el canal-, y una reserva puede mezclar tramos de los dos tipos.
        if ($evento->getEventoOrigen() === null
            && !$evento->isOta()
            && in_array($evento->getEstado()?->getId(), PmsEventoEstado::IDENTIFICAN_HUESPED, true)
        ) {
            $cancelada = $evento->getPmsUnidad()?->getNombre();
            $evento->setEstado($em->getReference(PmsEventoEstado::class, PmsEventoEstado::CODIGO_CANCELADA));
            break;
        }
    }

    printf("\n  se cancela: %s\n", $cancelada);

    $sincronizador->sincronizar($conversacion, new PmsReservaMessageContext($reserva));
    $em->flush();

    $parciales = array_values(array_filter(
        $enlace->getHitos(),
        static fn (HitoDeAsunto $h): bool => $h->tipo === Hito::PARTIAL_CANCELLATION
    ));

    printf("\n=== TRAS LA CANCELACIÓN PARCIAL ===\n");
    printf("  casitas cubiertas   : %s\n", implode(', ', $enlace->unidadesDerivadas()));
    printf("  cancelaciones anotadas: %d %s\n", count($parciales), $parciales === [] ? '' : '→ ' . $parciales[0]->detalle);
    printf("  en el mapa plano    : %s\n", $enlace->getMilestones()[Hito::PARTIAL_CANCELLATION] ?? '(no)');

    // ── LO QUE MÁS IMPORTA: reguardar tres veces no puede anotar tres avisos ──
    for ($i = 0; $i < 3; $i++) {
        $sincronizador->sincronizar($conversacion, new PmsReservaMessageContext($reserva));
        $em->flush();
    }

    $trasReguardar = count(array_filter(
        $enlace->getHitos(),
        static fn (HitoDeAsunto $h): bool => $h->tipo === Hito::PARTIAL_CANCELLATION
    ));

    printf("\n=== TRAS TRES RECÁLCULOS MÁS ===\n  cancelaciones anotadas: %d\n", $trasReguardar);

    printf(
        "\n%s\n",
        (count($parciales) === 1 && $trasReguardar === 1)
            ? '✅ Se detecta la pérdida y se anota UNA sola vez, por muchas veces que se reguarde.'
            : '❌ Mal: se anota ' . $trasReguardar . ' veces. El huésped recibiría el aviso repetido.'
    );
} finally {
    $em->getConnection()->rollBack();
    printf("\n(transacción deshecha: la base queda como estaba)\n");
}
