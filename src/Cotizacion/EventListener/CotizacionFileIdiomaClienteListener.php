<?php

declare(strict_types=1);

namespace App\Cotizacion\EventListener;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionFile;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Propaga el idioma del cliente del expediente a TODAS sus cotizaciones.
 *
 * `idiomaCliente` es lo que decide en qué idioma ve el cliente la propuesta: la
 * app pax lo lee del expediente para la portada y de cada cotización para la
 * guía (ver PaxCotizacionStore). Al crear una cotización se hereda el del
 * expediente, pero sin esto un cambio posterior dejaba la portada en el idioma
 * nuevo y las propuestas ya creadas en el viejo.
 *
 * Se propaga a todas las versiones, incluidas las enviadas o confirmadas: manda
 * el idioma del expediente, así que un cliente con un enlace ya abierto verá la
 * propuesta en el idioma nuevo. Es la decisión explícita del negocio.
 *
 * Vive en el backend y no en la SPA porque la regla debe cumplirse venga el
 * cambio de donde venga (app util, EasyAdmin o una importación).
 */
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: CotizacionFile::class)]
final class CotizacionFileIdiomaClienteListener
{
    public function preUpdate(CotizacionFile $file, PreUpdateEventArgs $args): void
    {
        if (!$args->hasChangedField('idiomaCliente')) {
            return;
        }

        $nuevoIdioma = (string) $args->getNewValue('idiomaCliente');
        if ($nuevoIdioma === '') {
            return;
        }

        $em = $args->getObjectManager();
        $metadata = $em->getClassMetadata(Cotizacion::class);

        foreach ($file->getCotizaciones() as $cotizacion) {
            if ($cotizacion->getIdiomaCliente() === $nuevoIdioma) {
                continue;
            }

            $cotizacion->setIdiomaCliente($nuevoIdioma);

            // Estamos dentro de un preUpdate: el UnitOfWork ya calculó los
            // cambios, así que hay que recomputarlos a mano para que estos
            // también se persistan en este mismo flush.
            $em->getUnitOfWork()->recomputeSingleEntityChangeSet($metadata, $cotizacion);
        }
    }
}
