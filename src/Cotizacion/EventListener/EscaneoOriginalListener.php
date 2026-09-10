<?php

declare(strict_types=1);

namespace App\Cotizacion\EventListener;

use App\Cotizacion\Documento\GiradorDeEscaneo;
use App\Cotizacion\Entity\CotizacionFilearchivo;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * Borra la copia intacta cuando se borra el archivo.
 *
 * 🔥 **Sin esto, girar un pasaporte dejaba una copia que sobrevivía al borrado.** Vich borra el
 * fichero que conoce —`delete_on_remove: true`— y no sabe nada del `.original` que deja
 * {@see GiradorDeEscaneo}. El resultado sería un escaneo de identidad **fuera de la política de
 * retención**: el sistema diría que lo borró y en disco seguiría, sin fila que lo nombre y sin
 * nada que vuelva a mirarlo nunca.
 *
 * ⚠️ Va en `preRemove` y no en `postRemove` **porque necesita la ruta**, y para calcularla hace
 * falta la entidad todavía viva: después del borrado, `resolvePath()` no tiene de dónde sacarla.
 */
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: CotizacionFilearchivo::class)]
final readonly class EscaneoOriginalListener
{
    public function __construct(private StorageInterface $almacen) {}

    public function preRemove(CotizacionFilearchivo $archivo): void
    {
        $ruta = $this->almacen->resolvePath($archivo, 'imageFile');
        if (!is_string($ruta)) {
            return;
        }

        $original = $ruta . GiradorDeEscaneo::SUFIJO_ORIGINAL;

        // Sin `@`: si existe y no se puede borrar, es un problema de permisos que hay que ver, no
        // algo que se traga. Lo normal es que no exista —sólo la tienen los girados.
        if (is_file($original)) {
            unlink($original);
        }
    }
}
