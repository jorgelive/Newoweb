<?php

declare(strict_types=1);

namespace App\Pms\DispatchHandler;

use App\Pms\Dispatch\AnunciarConfirmacionAlCanalDispatch;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Lleva a Beds24 la confirmación que hizo un pago, para que el pull no la deshaga.
 *
 * ── El fallo que cierra ─────────────────────────────────────────────────────
 * «Registrar un pago confirma la estancia» tiene dos caminos. Desde el panel el cambio va por
 * el ORM: `Beds24BookingsPushQueueListener` marca `estadoPushSolicitado`, encola el push y el
 * `confirmed` viaja al canal. Pero cuando el pago deja la estancia en `pago-total` o
 * `pago-parcial` por el recálculo de finanzas, quien la confirma es
 * `PmsEstadoPagoEventosService::confirmarPorPago()`, **en SQL** —corre en un `postFlush`, donde
 * el ORM obligaría a un flush anidado—. Un UPDATE en SQL no pasa por el UnitOfWork: nadie marca
 * la intención, nadie encola nada, Beds24 sigue diciendo `new`, y el siguiente ciclo del pull
 * la devuelve a `pendiente` porque para Booking `new` → `pendiente` (§5.4).
 *
 * Medido el 26/09/2026: José (TA3WSE, Booking) pagó el total el 25/09 a las 15:48 y a la mañana
 * siguiente figuraba `pendiente · pago total`. Su último push a Beds24 no llevaba `status`.
 * Camille (RXY9QC) estaba igual con un pago parcial. Es la invariante de
 * `docs/PmsBeds24ReservasSync.md` §12.9.a, que se había cerrado para el camino del panel y
 * seguía abierta en éste.
 *
 * ── Qué hace ────────────────────────────────────────────────────────────────
 * Aquí, fuera del flush y con el ORM, cada estancia se deja como la dejaría el panel:
 *
 * - si sigue confirmada, se marca `estadoPushSolicitado`. El cambio ensucia la entidad, el
 *   listener la recoge y encola el push de sus links; la estrategia manda `status` porque la
 *   marca está puesta, y el handler del push la consume al salir;
 * - si entre medias un pull la devolvió a `pendiente`, se vuelve a confirmar por el ORM, y el
 *   listener pone la marca él solo;
 * - si ya no le toca —la cancelaron, bajó el pago—, no se toca.
 *
 * Asíncrono porque lo lanza un `postFlush`: aquí dentro sí se puede hacer flush.
 */
#[AsMessageHandler]
final readonly class AnunciarConfirmacionAlCanalDispatchHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(AnunciarConfirmacionAlCanalDispatch $dispatch): void
    {
        $repositorio = $this->em->getRepository(PmsEventoCalendario::class);
        $anunciadas = [];

        foreach ($dispatch->eventoIds as $id) {
            if (!Uuid::isValid($id)) {
                continue;
            }

            $evento = $repositorio->find(Uuid::fromString($id));

            if (!$evento instanceof PmsEventoCalendario) {
                continue;
            }

            // El worker vive mucho: lo que tenga en memoria puede ser de antes del UPDATE en SQL.
            $this->em->refresh($evento);

            if ($evento->requiereAutoConfirmacionPorPago()) {
                // Un pull la devolvió a `pendiente` entre medias. Por el ORM: el listener marca
                // la intención de push por haber cambiado `estado`.
                $evento->setEstado($this->em->getReference(PmsEventoEstado::class, PmsEventoEstado::CODIGO_CONFIRMADA));
            } elseif ($evento->getEstado()?->getId() === PmsEventoEstado::CODIGO_CONFIRMADA) {
                $evento->setEstadoPushSolicitado(true);
            } else {
                continue;
            }

            $anunciadas[] = $id;
        }

        if ($anunciadas === []) {
            return;
        }

        $this->em->flush();

        $this->logger->info('Confirmación por pago anunciada al canal: push encolado con su status.', [
            'eventos' => $anunciadas,
        ]);
    }
}
