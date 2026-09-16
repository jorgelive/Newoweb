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
 * ── 🔥 Y desde el 16/09/2026, también el veredicto DEL PROPIO ARCHIVO ───────
 * Los documentos que no son de identidad —el E-Ticket— guardan su veredicto en el archivo, y había
 * dos formas de que dijera algo falso sin que nada lo tocara:
 *
 * | Qué pasa | Qué se leía |
 * |---|---|
 * | El archivo se **reasigna** a otra persona | el veredicto viaja con él: la fila de Pedro enseña «pasaporte: doc X ≠ esperado Y» calculado contra Juan |
 * | Se **reemplaza el fichero** por PATCH | la lectura cacheada sigue siendo la del documento viejo, así que un billete cambiado por el PDF bueno se re-juzga eternamente como billete |
 *
 * ⚠️ **Sólo se resetea si cambió el DUEÑO o el FICHERO**, nunca en cualquier actualización. El
 * propio control escribe `datosLeidos` y hace `flush()`: resetear ante cualquier cambio borraría la
 * lectura que se acaba de pagar, en el mismo `flush` que la guarda.
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

            if (self::invalidaLoGuardado($uow->getEntityChangeSet($entidad))) {
                // La lectura también: es del documento viejo, y sin tirarla el control seguiría
                // juzgando eternamente un fichero que ya no está ahí.
                $entidad->olvidarLectura();
                $entidad->olvidarVeredicto();
                $uow->recomputeSingleEntityChangeSet($em->getClassMetadata(CotizacionFilearchivo::class), $entidad);
            }
        }
    }

    /**
     * ¿Cambió algo que invalide lo que este archivo tenía guardado sobre sí mismo?
     *
     * 🔥 **Es la guarda más delicada de este listener y por eso es pública y estática.** El propio
     * control escribe `datosLeidos` y hace `flush()` inmediatamente —una lectura es dinero gastado y
     * no puede esperar al final—, así que **este método corre en el mismo `flush` que guarda la
     * lectura**. Si devolviera `true` de más, borraría lo que se acaba de pagar, en el acto y sin
     * que nadie se entere. Se prueba sola: {@see \App\Tests\Cotizacion\EventListener\InvalidaLoGuardadoTest}.
     *
     * ⚠️ **`imageName` y no `updatedAt`.** Vich reescribe el nombre del fichero al subir uno nuevo,
     * así que es la señal de que el contenido cambió; `updatedAt` se mueve por cualquier cosa
     * —incluida la propia escritura de la lectura— y usarlo sería morderse la cola.
     *
     * @param array<string, mixed> $cambios el changeset de Doctrine
     */
    public static function invalidaLoGuardado(array $cambios): bool
    {
        return isset($cambios['pasajero']) || isset($cambios['imageName']);
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
