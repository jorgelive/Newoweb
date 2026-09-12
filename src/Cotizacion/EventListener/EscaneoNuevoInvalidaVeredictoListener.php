<?php

declare(strict_types=1);

namespace App\Cotizacion\EventListener;

use App\Cotizacion\Entity\CotizacionFilearchivo;
use App\Cotizacion\Entity\CotizacionPasajeroIdentificacion;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Un escaneo nuevo devuelve a la cola el veredicto de ese documento.
 *
 * 🔥 **Sin esto, la ficha afirmaba algo que había dejado de ser cierto.** Caso real: la tanda
 * escribió «no hay escaneo de este documento en la bóveda» a las 20:56 —era verdad— y a las 23:34
 * se subieron los tres documentos. La ficha siguió diciendo que no había ninguno **con los
 * documentos delante en el visor**, porque un veredicto es la foto de un momento y una foto de una
 * AUSENCIA caduca en cuanto aparece lo que faltaba.
 *
 * ⚠️ Y cubre un segundo caso que no se ve: un escaneo **mejor** de un documento ya `validado_mrz`
 * no se miraba nunca, porque la tanda salta lo resuelto. Ahora vuelve a mirarse.
 *
 * ⚠️ **`onFlush` y no `postPersist`.** Hay que modificar OTRA entidad dentro de la misma
 * transacción, y en `postPersist` el `UnitOfWork` ya cerró los cambios: haría falta un `flush()`
 * anidado. `recomputeSingleEntityChangeSet()` es el mecanismo previsto para esto y ya se usa así en
 * este proyecto.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final readonly class EscaneoNuevoInvalidaVeredictoListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $metadatos = $em->getClassMetadata(CotizacionPasajeroIdentificacion::class);

        // Los insertados —un escaneo que llega— y los actualizados —uno que se reasigna a otra
        // persona, que deja sin respaldo al anterior y se lo da al nuevo.
        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates()] as $entidad) {
            if (!$entidad instanceof CotizacionFilearchivo) {
                continue;
            }

            foreach ($this->veredictosQueCaducan($entidad, $uow) as $identificacion) {
                $identificacion->hayEscaneoNuevo();
                $uow->recomputeSingleEntityChangeSet($metadatos, $identificacion);
            }
        }
    }

    /**
     * Los veredictos que este escaneo deja sin valor: el de su dueño actual y —si acaba de
     * cambiar de manos— el del anterior, que se quedó sin el documento que lo respaldaba.
     *
     * @return list<CotizacionPasajeroIdentificacion>
     */
    private function veredictosQueCaducan(CotizacionFilearchivo $archivo, \Doctrine\ORM\UnitOfWork $uow): array
    {
        // 🔥 **El reverso del DNI cuenta, y antes no.** No lleva el número impreso —`respaldaA()`
        // es `null`— pero lleva la MRZ que lo verifica, así que subirlo puede llevar ese DNI de
        // «observado» a «validado por MRZ». Mirando sólo `respaldaA()`, subir el reverso no
        // caducaba nada: el veredicto viejo se quedaba puesto y el escaneo nuevo no servía de nada
        // hasta que alguien pulsara reprocesar sin saber por qué.
        $tipo = $archivo->getTipoArchivo();
        $tipoNumero = $tipo?->respaldaA() ?? $tipo?->verificaA();
        if ($tipoNumero === null) {
            return [];   // un boleto o una autorización no dicen nada de ningún número
        }

        $duenos = [$archivo->getPasajero()];

        // El changeset dice de quién ERA. Sin esto, reasignar un documento dejaría al anterior con
        // un sello verde apoyado en un escaneo que ya no es suyo.
        $cambios = $uow->getEntityChangeSet($archivo);
        if (isset($cambios['pasajero'][0])) {
            $duenos[] = $cambios['pasajero'][0];
        }

        $afectados = [];
        foreach (array_filter($duenos) as $dueno) {
            foreach ($dueno->getIdentificaciones() as $identificacion) {
                if ($identificacion->getTipo() === $tipoNumero) {
                    $afectados[] = $identificacion;
                }
            }
        }

        return $afectados;
    }
}
