<?php

declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Pms\Entity\PmsEstablecimiento;
use App\Pms\Entity\PmsUnidad;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Garantiza que establecimientos y unidades tengan siempre un slug utilizable
 * en la URL pública del catálogo (`/casita/casita-1`).
 *
 * Por qué un listener y no un setter en la entidad: el slug tiene que ser
 * único, y comprobar la unicidad exige consultar la base de datos. Ponerlo en
 * la entidad la ataría al EntityManager. Aquí además cubre CUALQUIER entrada
 * —panel EasyAdmin, API, consola, fixtures—, que es donde fallan las
 * soluciones puestas solo en el formulario.
 *
 * El slug NO se regenera al renombrar: una vez publicada, la URL de un
 * alojamiento es una dirección que la gente comparte y que indexan los
 * buscadores. Cambiar el nombre comercial no debe romper enlaces vivos; si de
 * verdad se quiere otra URL, se edita el slug a mano.
 */
#[AsEntityListener(event: Events::prePersist, method: 'prePersistUnidad', entity: PmsUnidad::class)]
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdateUnidad', entity: PmsUnidad::class)]
#[AsEntityListener(event: Events::prePersist, method: 'prePersistEstablecimiento', entity: PmsEstablecimiento::class)]
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdateEstablecimiento', entity: PmsEstablecimiento::class)]
final class PmsSlugListener
{
    /** Reserva de sufijos al resolver colisiones antes de rendirse. */
    private const MAX_INTENTOS = 50;

    public function prePersistUnidad(PmsUnidad $unidad, PrePersistEventArgs $args): void
    {
        $this->asegurarUnidad($unidad, $args->getObjectManager());
    }

    public function preUpdateUnidad(PmsUnidad $unidad, PreUpdateEventArgs $args): void
    {
        $this->asegurarUnidad($unidad, $args->getObjectManager());
        $this->recalcularChangeSet($unidad, $args);
    }

    public function prePersistEstablecimiento(PmsEstablecimiento $est, PrePersistEventArgs $args): void
    {
        $this->asegurarEstablecimiento($est, $args->getObjectManager());
    }

    public function preUpdateEstablecimiento(PmsEstablecimiento $est, PreUpdateEventArgs $args): void
    {
        $this->asegurarEstablecimiento($est, $args->getObjectManager());
        $this->recalcularChangeSet($est, $args);
    }

    private function asegurarUnidad(PmsUnidad $unidad, ObjectManager $om): void
    {
        if ($this->yaTieneSlug($unidad->getSlug())) {
            $unidad->setSlug($this->normalizar($unidad->getSlug()));

            return;
        }

        // Se prefija con el establecimiento para que dos unidades llamadas
        // "Habitación 1" en hoteles distintos no compitan por el mismo slug
        // global. Si el nombre ya lo incluye, el sluggado no lo duplica porque
        // el resultado se compara entero contra lo que hay en base de datos.
        $base = trim(sprintf(
            '%s %s',
            $unidad->getEstablecimiento()?->getSlug() ?? '',
            $unidad->getNombre() ?? 'unidad'
        ));

        $unidad->setSlug($this->generarUnico($base, PmsUnidad::class, $om));
    }

    private function asegurarEstablecimiento(PmsEstablecimiento $est, ObjectManager $om): void
    {
        if ($this->yaTieneSlug($est->getSlug())) {
            $est->setSlug($this->normalizar($est->getSlug()));

            return;
        }

        $est->setSlug($this->generarUnico($est->getNombreComercial() ?? 'alojamiento', PmsEstablecimiento::class, $om));
    }

    private function yaTieneSlug(?string $slug): bool
    {
        return null !== $slug && '' !== trim($slug);
    }

    private function normalizar(?string $valor): string
    {
        $slug = (new AsciiSlugger())->slug((string) $valor)->lower()->toString();

        return trim($slug, '-') ?: 'sin-nombre';
    }

    /**
     * @param class-string $entidad
     */
    private function generarUnico(string $base, string $entidad, ObjectManager $om): string
    {
        $raiz = $this->normalizar($base);
        $repo = $om->getRepository($entidad);

        $candidato = $raiz;
        for ($i = 2; $i <= self::MAX_INTENTOS; ++$i) {
            if (null === $repo->findOneBy(['slug' => $candidato])) {
                return $candidato;
            }
            $candidato = $raiz . '-' . $i;
        }

        // Salida de emergencia: en vez de dejar el slug nulo —que rompería la
        // ruta pública— se cierra con un sufijo aleatorio corto.
        return $raiz . '-' . bin2hex(random_bytes(3));
    }

    /**
     * Doctrine congela el changeset ANTES de preUpdate: un setter llamado aquí
     * no llega al UPDATE si el campo no estaba ya marcado como sucio. Hay que
     * recalcularlo a mano. Mismo motivo por el que lo hace
     * PmsEventoCalendarioIntegrityListener.
     */
    private function recalcularChangeSet(object $entidad, PreUpdateEventArgs $args): void
    {
        $om = $args->getObjectManager();
        $om->getUnitOfWork()->recomputeSingleEntityChangeSet(
            $om->getClassMetadata($entidad::class),
            $entidad
        );
    }
}
