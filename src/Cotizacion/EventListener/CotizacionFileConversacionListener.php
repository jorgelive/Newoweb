<?php

declare(strict_types=1);

namespace App\Cotizacion\EventListener;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Service\Message\CotizacionFileMessageContext;
use App\Message\Factory\MessageConversationFactory;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Dispara la conversación —y con ella la IDENTIDAD— de la persona de un expediente.
 *
 * ── Qué faltaba ─────────────────────────────────────────────────────────────
 * El teléfono y el correo de un expediente eran **dos columnas y nada más**: sin hilo, sin
 * identidad y sin asunto, porque nadie llamaba a `upsertFromContext()` desde Turismo. Si esa
 * persona escribía por WhatsApp no había con qué reconocerla —nacía un walk-in— y no se le podía
 * escribir desde el panel porque su conversación no existía.
 *
 * ── Por qué `onFlush` + `postFlush` y no `postPersist` ──────────────────────
 * Es el mismo patrón que `PmsReservaRecalculoListener`, y no es preferencia de estilo:
 * `upsertFromContext()` **escribe** —crea la conversación, las identidades y el enlace— y hacerlo
 * dentro del flush en curso significa tocar el `UnitOfWork` mientras Doctrine lo está recorriendo.
 * Se ANOTA en `onFlush` y se EJECUTA en `postFlush`, con la transacción ya cerrada.
 *
 * La bandera `$enCurso` corta la recursión: el propio `upsertFromContext()` acaba en un flush
 * que volvería a disparar este listener.
 *
 * ⚠️ **Los fallos no tumban el guardado.** Que un expediente no consiga su hilo es un problema
 * de mensajería; perder el expediente que el operador acaba de guardar es otra cosa. Se registra
 * y se sigue.
 */
#[AsDoctrineListener(event: Events::onFlush, priority: -900)]
#[AsDoctrineListener(event: Events::postFlush, priority: -900)]
final class CotizacionFileConversacionListener
{
    /** @var array<string, true> Expedientes tocados en este flush, sin repetir. */
    private array $pendientes = [];

    private bool $enCurso = false;

    public function __construct(
        private readonly MessageConversationFactory $factory,
        private readonly LoggerInterface $logger,
    ) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->enCurso) {
            return;
        }

        $uow = $args->getObjectManager()->getUnitOfWork();

        // Altas y cambios. El UUID v7 ya está puesto en las inserciones, así que no hace falta
        // esperar a que la base asigne nada.
        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates()] as $entidad) {
            if ($entidad instanceof CotizacionFile && $entidad->getId() !== null) {
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
            $repo = $em->getRepository(CotizacionFile::class);

            foreach ($ids as $id) {
                $file = Uuid::isValid($id) ? $repo->find(Uuid::fromString($id)) : null;

                if ($file === null) {
                    continue;
                }

                // Sin ningún identificador no hay a quién reconocer: se salta en vez de crear un
                // hilo vacío que nadie podría resolver después.
                $contexto = new CotizacionFileMessageContext($file);

                if ($contexto->getIdentificadores() === []) {
                    continue;
                }

                $this->factory->upsertFromContext($contexto, flush: true);
            }
        } catch (Throwable $e) {
            $this->logger->error('No se pudo crear la conversación de un expediente: se guardó igual.', [
                'expedientes' => $ids,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->enCurso = false;
        }
    }
}
