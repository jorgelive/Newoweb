<?php

declare(strict_types=1);

namespace App\Cotizacion\EventListener;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Finanzas\CotizacionEnlaceService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Uid\Uuid;

/**
 * Mantiene al día el enlace de pago del adelanto de una propuesta publicada (16/10/2026).
 *
 * Publicar es el momento en que el cliente puede verla y querer reservar: «Cómo reservar» le
 * enseña entonces el botón de pagar con tarjeta sin que nadie emita nada. Y como ese botón cobra
 * una CIFRA, se vuelve a mirar cada vez que algo la mueve o cambia si la propuesta se vende:
 *
 * - se crea ya publicada (un `POST` con `publicado`), o pasa a publicada;
 * - estando publicada cambian `totalVenta` (precio, pasajeros…), `adelanto` (el %), `estado` o
 *   `presentacionPrecio`.
 *
 * 🔥 Antes sólo se miraba el paso a publicada: editar después el precio o el % dejaba el texto de
 * «Cómo reservar» con la cifra nueva y el botón cobrando la vieja (revisión del 16/10/2026).
 *
 * Si cambia el ESTADO se repasan también las hermanas publicadas del expediente: confirmar la v2
 * debe retirar el enlace del adelanto de la v1, que si no seguía cobrándose con la URL que el
 * cliente ya tenía. Las reglas —emitir, reutilizar o retirar— son de
 * {@see CotizacionEnlaceService::emitirAdelanto()}, idempotente; aquí sólo se detecta el momento.
 *
 * ── `onFlush` + `postFlush`, como `CotizacionFileConversacionListener` ──────
 * Emitir un enlace ESCRIBE (y hace su propio flush): dentro del flush en curso se tocaría el
 * `UnitOfWork` mientras Doctrine lo recorre. Se anota en `onFlush` y se ejecuta en `postFlush`, con
 * la cotización ya guardada. `$enCurso` corta la recursión del flush del propio enlace.
 *
 * Despublicar no anula: el enlace que se mandó existió (decisión del 16/10/2026).
 */
#[AsDoctrineListener(event: Events::onFlush, priority: -950)]
#[AsDoctrineListener(event: Events::postFlush, priority: -950)]
final class CotizacionPublicadaEnlaceListener
{
    /** Los campos que mueven la cifra del adelanto o si la propuesta se vende. */
    private const array CAMPOS = ['publicado', 'totalVenta', 'adelanto', 'estado', 'presentacionPrecio'];

    /** @var array<string, bool> id de cotización → ¿cambió su estado? (entonces, también sus hermanas) */
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

        foreach ($uow->getScheduledEntityInsertions() as $entidad) {
            if ($entidad instanceof Cotizacion && $entidad->getId() !== null && $entidad->isPublicado()) {
                $this->pendientes[(string) $entidad->getId()] = false;
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entidad) {
            if (!$entidad instanceof Cotizacion || $entidad->getId() === null) {
                continue;
            }

            $cambios = array_intersect_key($uow->getEntityChangeSet($entidad), array_flip(self::CAMPOS));

            // Publicada ahora, o lo era y cambió algo que importa. Un cambio de estado cuenta aunque
            // no esté publicada: puede ser la confirmación que deja sin venta a sus hermanas.
            if ($cambios !== [] && ($entidad->isPublicado() || isset($cambios['estado']))) {
                $this->pendientes[(string) $entidad->getId()] = ($this->pendientes[(string) $entidad->getId()] ?? false) || isset($cambios['estado']);
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->enCurso || $this->pendientes === []) {
            return;
        }

        $ids = $this->pendientes;
        $this->pendientes = [];
        $this->enCurso = true;

        try {
            $em = $args->getObjectManager();

            foreach ($ids as $id => $conHermanas) {
                $cotizacion = Uuid::isValid($id) ? $em->find(Cotizacion::class, Uuid::fromString($id)) : null;

                if (!$cotizacion instanceof Cotizacion) {
                    continue;
                }

                $repasar = [$cotizacion];
                $file = $cotizacion->getFile();

                if ($conHermanas && $file !== null) {
                    foreach ($file->cotizacionesQue(Criteria::create()->where(Criteria::expr()->eq('publicado', true))) as $hermana) {
                        if ($hermana !== $cotizacion) {
                            $repasar[] = $hermana;
                        }
                    }
                }

                // Nunca lanza: ver `emitirAdelanto()`.
                foreach ($repasar as $una) {
                    if ($una->isPublicado() || $una === $cotizacion) {
                        $this->enlaces->emitirAdelanto($una);
                    }
                }
            }
        } finally {
            $this->enCurso = false;
        }
    }
}
