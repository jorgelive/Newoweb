<?php

declare(strict_types=1);

namespace App\Cotizacion\EventListener;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Finanzas\CotizacionEnlaceService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Uid\Uuid;

/**
 * Al PUBLICAR una propuesta, su enlace de pago del adelanto queda listo (16/10/2026).
 *
 * Publicar es el momento en que el cliente puede verla y querer reservar: «Cómo reservar» le
 * enseña entonces el botón de pagar con tarjeta sin que nadie tenga que emitir nada. Las reglas de
 * si procede —que se ofrezca, que pida adelanto, que no haya nada pagado— son de
 * {@see CotizacionEnlaceService::emitirAdelanto()}; aquí sólo se detecta el momento.
 *
 * ── `onFlush` + `postFlush`, como `CotizacionFileConversacionListener` ──────
 * Emitir un enlace ESCRIBE (y hace su propio flush): dentro del flush en curso se tocaría el
 * `UnitOfWork` mientras Doctrine lo recorre. Se anota en `onFlush` —donde se ve el cambio de
 * `publicado`— y se ejecuta en `postFlush`, con la publicación ya guardada. `$enCurso` corta la
 * recursión del flush del propio enlace.
 *
 * Sólo el paso de NO publicada a publicada: guardar otra vez una propuesta ya publicada no emite
 * nada, y despublicar no anula —el enlace que se mandó existió—.
 */
#[AsDoctrineListener(event: Events::onFlush, priority: -950)]
#[AsDoctrineListener(event: Events::postFlush, priority: -950)]
final class CotizacionPublicadaEnlaceListener
{
    /** @var array<string, true> */
    private array $pendientes = [];

    private bool $enCurso = false;

    public function __construct(private readonly CotizacionEnlaceService $enlaces)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->enCurso) {
            return;
        }

        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entidad) {
            if (!$entidad instanceof Cotizacion || $entidad->getId() === null) {
                continue;
            }

            $cambio = $uow->getEntityChangeSet($entidad)['publicado'] ?? null;

            if (\is_array($cambio) && $cambio[0] === false && $cambio[1] === true) {
                $this->pendientes[(string) $entidad->getId()] = true;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->enCurso || $this->pendientes === []) {
            return;
        }

        $ids = array_keys($this->pendientes);
        $this->pendientes = [];
        $this->enCurso = true;

        try {
            $em = $args->getObjectManager();

            foreach ($ids as $id) {
                $cotizacion = Uuid::isValid($id) ? $em->find(Cotizacion::class, Uuid::fromString($id)) : null;

                // Nunca lanza: ver `emitirAdelanto()`.
                if ($cotizacion instanceof Cotizacion) {
                    $this->enlaces->emitirAdelanto($cotizacion);
                }
            }
        } finally {
            $this->enCurso = false;
        }
    }
}
