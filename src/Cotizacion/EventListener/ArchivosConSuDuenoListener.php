<?php

declare(strict_types=1);

namespace App\Cotizacion\EventListener;

use App\Cotizacion\Entity\CotizacionFileGrupo;
use App\Cotizacion\Entity\CotizacionFilearchivo;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Entity\CotizacionVuelo;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;

/**
 * Al borrar un pasajero, un vuelo o un grupo, sus adjuntos se borran **por Doctrine**, no por la
 * base de datos.
 *
 * 🔥 **Los ficheros se quedaban en disco.** `CotizacionFilearchivo` cuelga de los tres con
 * `onDelete: 'CASCADE'` y ninguno tiene la relación inversa en el ORM, así que borrar un pasajero
 * —se puede desde el panel— hacía que MySQL borrase la FILA del adjunto por su cuenta. Vich no se
 * enteraba: el escaneo del pasaporte, su `.original` y su `.vista.webp` seguían en
 * `var/documentos/archivos/` sin fila que los nombrase, **fuera de la política de retención** y sin
 * nada que volviera a mirarlos. Visto el 19/10/2026 leyendo los `JoinColumn`; ese día aún no había
 * ni un huérfano (681 filas, 681 ficheros).
 *
 * El expediente no necesita esto: `CotizacionFile::$filearchivos` ya lleva `cascade: ['remove']`.
 *
 * En `preRemove` y no en `onFlush`: salta al llamar a `remove()` —también en cascada y en el
 * `orphanRemoval`—, antes de calcular el orden de borrado, que es cuando añadir borrados es seguro.
 * El `CASCADE` de la base se queda como red: si algo borra por SQL, al menos no queda la fila.
 *
 * Ver docs/Cotizaciones.md («Los derivados se borran con el documento»).
 */
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: CotizacionFilepasajero::class)]
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: CotizacionVuelo::class)]
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: CotizacionFileGrupo::class)]
final readonly class ArchivosConSuDuenoListener
{
    public function preRemove(CotizacionFilepasajero|CotizacionVuelo|CotizacionFileGrupo $dueno, PreRemoveEventArgs $args): void
    {
        $campo = match (true) {
            $dueno instanceof CotizacionFilepasajero => 'pasajero',
            $dueno instanceof CotizacionVuelo => 'vuelo',
            default => 'grupo',
        };

        $em = $args->getObjectManager();

        foreach ($em->getRepository(CotizacionFilearchivo::class)->findBy([$campo => $dueno]) as $archivo) {
            $em->remove($archivo);
        }
    }
}
