<?php

declare(strict_types=1);

namespace App\Cotizacion\EventListener;

use App\Cotizacion\Documento\GiradorDeEscaneo;
use App\Cotizacion\Documento\PaginasDePdf;
use App\Cotizacion\Entity\CotizacionFilearchivo;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Vich\UploaderBundle\Event\Event;
use Vich\UploaderBundle\Event\Events;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * Borra lo que vive AL LADO de un documento cuando se borra el documento: la copia intacta de un
 * escaneo girado (`.original`, {@see GiradorDeEscaneo}) y la imagen de un PDF (`.vista.webp`,
 * {@see PaginasDePdf}).
 *
 * 🔥 **Sin esto, sobreviven al borrado.** Vich borra el fichero que conoce y no sabe nada de lo
 * que hay a su lado. El resultado sería un escaneo de identidad —o la foto de uno— **fuera de la
 * política de retención**: el sistema diría que lo borró y en disco seguiría, sin fila que lo nombre.
 *
 * ⚠️ **El evento de Vich (`PRE_REMOVE`), no el `preRemove` de Doctrine** (19/10/2026). Vich borra
 * el fichero en DOS casos: al borrar la entidad y al REEMPLAZARLO por otro (`delete_on_update`).
 * El listener de Doctrine sólo veía el primero, así que subir un pasaporte nuevo encima de uno
 * girado dejaba el `.original` del viejo huérfano para siempre. En `PRE_REMOVE` el objeto todavía
 * lleva el nombre del fichero VIEJO, que es justo el que hay que limpiar.
 */
#[AsEventListener(event: Events::PRE_REMOVE, method: 'antesDeBorrar')]
final readonly class EscaneoDerivadosListener
{
    public function __construct(private StorageInterface $almacen) {}

    public function antesDeBorrar(Event $evento): void
    {
        $archivo = $evento->getObject();
        if (!$archivo instanceof CotizacionFilearchivo) {
            return;
        }

        $ruta = $this->almacen->resolvePath($archivo, $evento->getMapping()->getFilePropertyName());
        if (!is_string($ruta)) {
            return;
        }

        foreach ([$ruta . GiradorDeEscaneo::SUFIJO_ORIGINAL, PaginasDePdf::rutaDeVista($ruta)] as $derivado) {
            // Sin `@`: si existe y no se puede borrar, es un problema de permisos que hay que ver,
            // no algo que se traga. Lo normal es que no exista.
            if (is_file($derivado)) {
                unlink($derivado);
            }
        }
    }
}
