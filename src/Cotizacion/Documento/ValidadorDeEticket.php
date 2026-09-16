<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

use App\Cotizacion\Entity\CotizacionFilearchivo;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Enum\ArchivoTipoEnum;
use App\Cotizacion\Enum\GrupoTipoEnum;
use App\Cotizacion\Enum\PaisDeControlEnum;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionVuelo;
use App\Enum\DocumentoTipoEnum;
use Throwable;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * El control del trámite migratorio: lee el E-Ticket, lo coteja con los vuelos de esa persona y
 * **propone** un veredicto.
 *
 * ⚠️ **No escribe nada**, igual que {@see ValidadorDeDocumento} y por el mismo motivo: con 87
 * documentos, escribir a ciegas es pedir un desastre callado. Devuelve el {@see CotejoDeEticket} y
 * quien llama decide.
 *
 * ── Las tres piezas, y de dónde sale cada una ───────────────────────────────
 * ```
 *   el documento   → LectorDeEticket           (IA una vez, cacheada en `datosLeidos`)
 *   los vuelos     → CruceDeFrontera           (su subgrupo aéreo, no el del expediente)
 *   el pasaporte   → su ficha del manifiesto
 *                  ↓
 *              CotejoDeEticket                 (puro: es quien juzga)
 * ```
 *
 * 🔑 **Los vuelos salen del SUBGRUPO, y ahí estaba el hueco.** Un expediente tiene varias formas de
 * entrar y de salir del país; una persona tiene una. El vínculo subgrupo↔vuelos ya existía y nadie
 * lo había usado para esto.
 */
final readonly class ValidadorDeEticket
{
    public function __construct(
        private LectorDeEticket $lector,
        private ValidadorDeDocumento $identidad,
        private StorageInterface $almacen,
    ) {}

    /**
     * La lectura, **pagando el modelo como mucho una vez en la vida del documento**.
     *
     * Calcado de {@see ValidadorDeDocumento::lecturaDe()}: si ya se leyó, se reinterpreta lo
     * guardado —gratis, sin red, y con el criterio de hoy—; si nunca se leyó, se lee y se guarda.
     * Un error de lectura también se guarda, o la tanda reintentaría eternamente el mismo fallo.
     *
     * ⚠️ **Muta la entidad pero no persiste**: el `flush()` es de quien llama, que es quien sabe si
     * está en una tanda de cien o en una petición suelta.
     */
    public function lecturaDe(CotizacionFilearchivo $archivo): ?DatosDeEticket
    {
        if ($archivo->getTipoArchivo() !== ArchivoTipoEnum::ETICKET) {
            return null;
        }

        $guardado = $archivo->getDatosLeidos();

        if ($guardado !== null) {
            return $this->lector->interpretar($guardado);
        }

        if ($archivo->seIntentoLeer()) {
            return null;   // se intentó y falló; el motivo está en `lecturaError`
        }

        $ruta = $this->almacen->resolvePath($archivo, 'imageFile');

        if (!is_string($ruta) || !is_readable($ruta)) {
            $archivo->registrarLectura(null, 'el fichero no está en disco');

            return null;
        }

        try {
            $crudo = $this->lector->extraer((string) file_get_contents($ruta), (string) mime_content_type($ruta));
        } catch (Throwable $e) {
            // En una tanda de cien, uno ilegible no puede parar los otros 99.
            $archivo->registrarLectura(null, mb_substr($e->getMessage(), 0, 255));

            return null;
        }

        $archivo->registrarLectura($crudo);

        return $this->lector->interpretar($crudo);
    }

    /**
     * El veredicto para este documento, o `null` si no es un E-Ticket o no tiene dueño.
     *
     * ⚠️ Un E-Ticket **sin pasajero asignado** no se juzga: no hay contra qué. Distinto de un
     * documento de identidad, que puede proponer a quién pertenece por su número —aquí el número
     * del trámite no lo lleva nadie más, así que no hay a quién buscar.
     */
    public function validar(CotizacionFilearchivo $archivo, PaisDeControlEnum $pais): ?CotejoDeEticket
    {
        $pasajero = $archivo->getPasajero();

        if ($pasajero === null) {
            return null;
        }

        $leido = $this->lecturaDe($archivo);

        if ($leido === null) {
            return null;
        }

        return CotejoDeEticket::de(
            $leido,
            CruceDeFrontera::de($this->vuelosDe($pasajero), $pais),
            $this->identidadDe($archivo->getFile(), $pasajero),
        );
    }

    /**
     * Contra qué identidad se coteja: **el escaneo de su pasaporte antes que el manifiesto**.
     *
     * 🔑 El manifiesto está tecleado a mano y el E-Ticket también. El escaneo con MRZ es la única
     * fuente que no tecleó nadie, y ya está leída y guardada: no cuesta ni una llamada más. El
     * porqué completo, con los dos casos reales que lo destaparon, en {@see ReferenciaDeIdentidad}.
     */
    private function identidadDe(?CotizacionFile $file, CotizacionFilepasajero $pasajero): ReferenciaDeIdentidad
    {
        $escaneo = $this->escaneoDelPasaporte($file, $pasajero);

        if ($escaneo !== null) {
            $leido = $this->identidad->lecturaDe($escaneo);

            if ($leido !== null && $leido->esUtilizable()) {
                return ReferenciaDeIdentidad::delEscaneo($leido);
            }
        }

        $numero = trim((string) $pasajero->identificacionDe(DocumentoTipoEnum::PASAPORTE)?->getNumero());
        $nombre = trim($pasajero->getNombre().' '.$pasajero->getApellido());

        if ($numero === '' && $nombre === '') {
            return ReferenciaDeIdentidad::vacia();
        }

        return ReferenciaDeIdentidad::delManifiesto($numero === '' ? null : $numero, $nombre === '' ? null : $nombre);
    }

    /**
     * ⚠️ Se coge el **más reciente**, no el primero: la vía del operador no reemplaza el escaneo
     * anterior al subir otro, así que puede haber dos pasaportes de la misma persona y el bueno es
     * el que se subió después.
     */
    private function escaneoDelPasaporte(?CotizacionFile $file, CotizacionFilepasajero $pasajero): ?CotizacionFilearchivo
    {
        if ($file === null) {
            return null;
        }

        $id = (string) $pasajero->getId();
        $mejor = null;

        foreach ($file->getFilearchivos() as $archivo) {
            if ($archivo->getTipoArchivo() !== ArchivoTipoEnum::PASAPORTE) {
                continue;
            }

            if ((string) $archivo->getPasajero()?->getId() !== $id) {
                continue;
            }

            if ($mejor === null || $archivo->getCreatedAt() > $mejor->getCreatedAt()) {
                $mejor = $archivo;
            }
        }

        return $mejor;
    }

    /**
     * Los vuelos de ESA persona: los de sus subgrupos del eje aéreo.
     *
     * ⚠️ Se recorren **todos** sus subgrupos aéreos y no sólo el primero. Un pasajero puede llevar
     * el tramo nacional en un PNR y el internacional en otro —es el caso normal en este expediente—
     * y quedarse con uno solo dejaría fuera justo el vuelo que cruza la frontera.
     *
     * @return list<CotizacionVuelo>
     */
    private function vuelosDe(CotizacionFilepasajero $pasajero): array
    {
        $vuelos = [];

        foreach ($pasajero->getPertenencias() as $pertenencia) {
            $grupo = $pertenencia->getGrupo();

            if ($grupo?->getTipo() !== GrupoTipoEnum::RESERVA_AEREA) {
                continue;
            }

            foreach ($grupo->getVuelos() as $vuelo) {
                // Dos subgrupos pueden declarar el mismo tramo: se cuenta una vez o el recuento de
                // «entra más de una vez» diría que hay dos estancias donde hay una.
                $vuelos[(string) $vuelo->getId()] = $vuelo;
            }
        }

        return array_values($vuelos);
    }

}
