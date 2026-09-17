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

            $cambios = $uow->getEntityChangeSet($entidad);
            $caduca = self::queCaduca($uow->isScheduledForInsert($entidad), self::hayFicheroNuevo($entidad), $cambios);

            // 🔥 **Antes esto corría en CUALQUIER actualización de un archivo de identidad.** Girar
            // un pasaporte —que no pasa por Vich: `GiradorDeEscaneo` escribe con Imagick y toca
            // `rotacionAplicada`— o renombrarlo tiraba el veredicto de su dueño, y con él
            // `confirmadaEn`/`confirmadaPor`: **la firma de quien lo había mirado**. Ver `queCaduca()`.
            if ($caduca['veredicto']) {
                foreach ($this->veredictosQueCaducan($entidad, $cambios) as $identificacion) {
                    $identificacion->hayEscaneoNuevo();
                    $uow->recomputeSingleEntityChangeSet($metadatos, $identificacion);
                }
            }

            // 🔥 **Son DOS preguntas, y las había fundido en una.**
            //
            // | Qué cambió | ¿caduca el veredicto? | ¿caduca la LECTURA? |
            // |---|---|---|
            // | el fichero | sí | **sí** — la lectura es del documento viejo |
            // | el dueño | sí — se calculó contra otra persona | **no** — los bytes son los mismos |
            //
            // Tirar la lectura al reasignar salía carísimo y en silencio: `ResolutorDeDocumentoSuelto`
            // **paga la lectura**, crea la ficha a partir de ella y entonces hace `setPasajero()`.
            // El flush de esa misma transacción borraba la lectura de la que acababa de salir la
            // ficha — y volver a tenerla cuesta otra llamada, si el fichero sigue en disco.
            if ($caduca['veredicto']) {
                if ($caduca['lectura']) {
                    $entidad->olvidarLectura();
                }

                $entidad->olvidarVeredicto();
                $uow->recomputeSingleEntityChangeSet($em->getClassMetadata(CotizacionFilearchivo::class), $entidad);
            }
        }
    }

    /**
     * ¿Viene un fichero nuevo en esta escritura?
     *
     * 🔥 **Se pregunta por el `imageFile` PENDIENTE, no por `imageName` en el changeset**, y la
     * diferencia es que la primera versión no podía funcionar nunca. Vich escribe `imageName` en
     * **`preUpdate`**, y el ORM despacha `onFlush` **antes** de `executeUpdates()`, que es donde
     * corre `preUpdate`: cuando este listener mira, `imageName` todavía no ha cambiado. El
     * changeset de un PATCH con fichero nuevo trae sólo `updatedAt` —lo toca `setImageFile()` a
     * propósito, para forzar el UPDATE—.
     *
     * Resultado: reemplazar el PDF por el bueno dejaba la lectura del viejo para siempre, y cada
     * tanda repetía «es otro documento» sobre un trámite correcto. El test que la defendía probaba
     * una función pura con un changeset que en producción no existe en ese instante.
     *
     * `getImageFile()` sí está puesto en `onFlush`: Vich aún no lo ha consumido.
     *
     * ── 🔥 Y no vale «`imageFile` no es null» (17/09/2026) ──────────────────────
     * Vich, al terminar de subir, **vuelve a inyectar un `File`** en `imageFile`
     * (`UploadHandler::upload()` → `FileInjector::injectFile()`). Así que en la MISMA petición, cada
     * `flush()` posterior al de la subida se leía como «viene un fichero nuevo» y tiraba la lectura
     * y el veredicto recién escritos. Medido en producción: cada subida desde `pax` pagaba **2 o 3
     * lecturas** seguidas, y el E-Ticket se quedaba sin veredicto porque `rejuzgar()` se borraba en
     * su propio flush.
     *
     * 🔑 La pregunta correcta es **la misma que usa Vich** (`UploadHandler::hasUploadedFile()`): un
     * `UploadedFile` —lo que llega por HTTP— o un `ReplacingFile` —lo que pone la carga masiva—. Un
     * `File` a secas es el que Vich reinyecta, y ése no es nada nuevo.
     *
     * ⚠️ Y el test que defendía la versión anterior usaba `new File(__FILE__)`: **otra entrada
     * inventada**, en el mismo método cuya cabecera ya contaba la primera vez que pasó.
     *
     * ⚠️ Y **no vale `updatedAt`**: se mueve por cualquier cosa, incluida la propia escritura de la
     * lectura, así que usarlo sería morderse la cola —el control guardaría `datosLeidos`, el
     * listener vería `updatedAt` y borraría lo que se acaba de pagar—.
     */
    public static function hayFicheroNuevo(CotizacionFilearchivo $archivo): bool
    {
        $fichero = $archivo->getImageFile();

        return $fichero instanceof \Symfony\Component\HttpFoundation\File\UploadedFile
            || $fichero instanceof \Vich\UploaderBundle\FileAbstraction\ReplacingFile;
    }

    /**
     * Qué deja de valer con esta escritura: el veredicto, y además la lectura.
     *
     * ── 🔥 La pregunta que faltaba: ¿cambió algo que se COTEJÓ? ────────────────
     * El bloque de las identificaciones caducaba en **cualquier** actualización de un archivo de
     * identidad. Girar un pasaporte o cambiarle el nombre tiraba el veredicto de su dueño, y
     * `invalidarVeredicto()` se lleva `confirmadaEn`, `confirmadaPor` y `validadoCon`: **la firma de
     * una persona, borrada por enderezar una foto**. El bloque del archivo ya preguntaba bien; las
     * dos mitades del mismo listener respondían distinto a la misma pregunta.
     *
     * | Qué cambió | ¿veredicto? | ¿lectura? | Por qué |
     * |---|---|---|---|
     * | es un archivo nuevo | sí | si trae fichero | un escaneo que aparece |
     * | el fichero | sí | **sí** | es otro documento |
     * | el dueño | sí, a los dos | no | se cotejó contra otra persona; los bytes son los mismos |
     * | `tipoArchivo` | sí, al tipo viejo y al nuevo | **sí** | un DNI que pasa a pasaporte respalda otro número, y la lectura se hizo con las preguntas de otro documento |
     * | giro, nombre, tamaño, la propia lectura | **no** | no | nada de lo que se cotejó |
     *
     * ⚠️ **El giro es el caso que importa y el que no se ve.** `GiradorDeEscaneo` no pasa por Vich
     * —escribe con Imagick—, así que `hayFicheroNuevo()` es `false` y el changeset sólo trae
     * `rotacionAplicada`, `datosLeidos` e `imageSize`. Correcto: mismo documento, derecho.
     *
     * ⚠️ **`tipoArchivo` sólo cuenta en una ACTUALIZACIÓN.** En un alta el changeset trae todos los
     * campos, y contarlo ahí tiraría la lectura de un archivo que nace ya leído.
     *
     * Pura y estática para poder probarla. ⚠️ Pero **un test verde aquí no basta**: en este mismo
     * listener ya hubo uno sobre un changeset que en producción no existe en ese instante. Se
     * verifica también con el flujo real.
     *
     * @param array<string, mixed> $cambios el changeset del archivo
     *
     * @return array{veredicto: bool, lectura: bool}
     */
    public static function queCaduca(bool $esNuevo, bool $ficheroNuevo, array $cambios): array
    {
        $cambioDeTipo = !$esNuevo && isset($cambios['tipoArchivo']);

        return [
            'veredicto' => $esNuevo || $ficheroNuevo || isset($cambios['pasajero']) || $cambioDeTipo,
            'lectura' => $ficheroNuevo || $cambioDeTipo,
        ];
    }

    /**
     * Los veredictos que este escaneo deja sin valor: el de su dueño actual y —si acaba de
     * cambiar de manos— el del anterior, que se quedó sin el documento que lo respaldaba.
     *
     * @param array<string, mixed> $cambios
     *
     * @return list<CotizacionPasajeroIdentificacion>
     */
    private function veredictosQueCaducan(CotizacionFilearchivo $archivo, array $cambios): array
    {
        // 🔥 **El reverso del DNI cuenta, y antes no.** No lleva el número impreso —`respaldaA()`
        // es `null`— pero lleva la MRZ que lo verifica, así que subirlo puede llevar ese DNI de
        // «observado» a «validado por MRZ». Mirando sólo `respaldaA()`, subir el reverso no
        // caducaba nada: el veredicto viejo se quedaba puesto y el escaneo nuevo no servía de nada
        // hasta que alguien pulsara reprocesar sin saber por qué.
        //
        // Y si el TIPO cambió, también el de antes: un DNI reetiquetado como pasaporte deja al DNI
        // sin el escaneo que lo respaldaba.
        //
        // 🔥 **El changeset trae el tipo como TEXTO, no como enum.** Medido en producción:
        // `['pasaporte', 'eticket']`, dos `string`. La primera versión comprobaba
        // `instanceof ArchivoTipoEnum`, lo descartaba en silencio, y reetiquetar un pasaporte como
        // E-Ticket dejaba el pasaporte de esa persona en verde **sin escaneo que lo respaldara**.
        // El test pasaba porque el changeset lo había escrito yo — la misma trampa que ya cuenta la
        // cabecera de `hayFicheroNuevo()`. Lo cazó el flujo real, no el test.
        $tipos = [$archivo->getTipoArchivo()];
        $tipoViejo = is_array($cambios['tipoArchivo'] ?? null) ? ($cambios['tipoArchivo'][0] ?? null) : null;
        $tipoViejo = is_string($tipoViejo) ? \App\Cotizacion\Enum\ArchivoTipoEnum::tryFrom($tipoViejo) : $tipoViejo;
        if ($tipoViejo instanceof \App\Cotizacion\Enum\ArchivoTipoEnum) {
            $tipos[] = $tipoViejo;
        }

        $numeros = [];
        foreach ($tipos as $tipo) {
            $numero = $tipo?->respaldaA() ?? $tipo?->verificaA();
            if ($numero !== null) {
                $numeros[] = $numero;
            }
        }

        if ($numeros === []) {
            return [];   // un boleto o una autorización no dicen nada de ningún número
        }

        $duenos = [$archivo->getPasajero()];

        // El changeset dice de quién ERA. Sin esto, reasignar un documento dejaría al anterior con
        // un sello verde apoyado en un escaneo que ya no es suyo.
        $duenoViejo = is_array($cambios['pasajero'] ?? null) ? ($cambios['pasajero'][0] ?? null) : null;
        if ($duenoViejo instanceof \App\Cotizacion\Entity\CotizacionFilepasajero) {
            $duenos[] = $duenoViejo;
        }

        $afectados = [];
        foreach (array_filter($duenos) as $dueno) {
            foreach ($dueno->getIdentificaciones() as $identificacion) {
                if (in_array($identificacion->getTipo(), $numeros, true)) {
                    $afectados[] = $identificacion;
                }
            }
        }

        return $afectados;
    }
}
