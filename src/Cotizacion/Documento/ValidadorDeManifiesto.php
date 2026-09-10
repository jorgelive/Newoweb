<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFilearchivo;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Entity\CotizacionPasajeroIdentificacion;
use App\Cotizacion\Enum\ArchivoTipoEnum;
use App\Cotizacion\Enum\ValidacionIdentificacionEnum;
use App\Enum\DocumentoTipoEnum;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Recorre el manifiesto y valida **cada número** contra el escaneo que le corresponde.
 *
 * 🔑 **La unidad de trabajo es la identificación, no el archivo.** Una persona con DNI y pasaporte
 * tiene dos veredictos independientes: puede tener el pasaporte comprobado por MRZ y el DNI
 * observado por un dedazo en el vencimiento, y hay que saber **cuál** de los dos hay que arreglar.
 *
 * ### Por qué se puede volver a lanzar sin miedo
 *
 * Lo ya resuelto se salta ({@see ValidacionIdentificacionEnum::estaResuelto()}), así que una
 * segunda pasada **sólo cuesta lo que falta**. Y aunque no se saltara, la lectura del documento
 * está cacheada en el archivo: lo caro se paga una vez por documento y no una vez por pasada.
 *
 * Eso convierte esto en algo que se puede colgar de un botón sin pensárselo: quien lo pulse dos
 * veces seguidas no paga dos veces.
 */
final readonly class ValidadorDeManifiesto
{
    public function __construct(
        private EntityManagerInterface $em,
        private ValidadorDeDocumento $validador,
    ) {}

    /**
     * @param bool $forzar Vuelve a mirar también lo ya resuelto. Para cuando cambia el criterio,
     *        no para el uso normal — sin esto, la tanda es incremental.
     * @return array<string, int> Conteo por estado, para enseñarlo al terminar.
     */
    public function validar(CotizacionFile $expediente, bool $forzar = false, ?int $limite = null): array
    {
        $conteo = array_fill_keys(array_column(ValidacionIdentificacionEnum::cases(), 'value'), 0);
        $conteo['sin_documento'] = 0;
        $hechos = 0;

        foreach ($expediente->getFilepasajeros() as $pasajero) {
            foreach ($pasajero->getIdentificaciones() as $identificacion) {
                if (!$forzar && $identificacion->estaResuelta()) {
                    ++$conteo[$identificacion->getEstadoValidacion()->value];
                    continue;
                }

                if ($limite !== null && $hechos >= $limite) {
                    break 2;
                }

                $this->validarUna($pasajero, $identificacion, $conteo);
                ++$hechos;
            }
        }

        $this->em->flush();

        return $conteo;
    }

    /**
     * Vuelve a cotejar los documentos de UNA persona, siempre a fondo.
     *
     * ⚠️ **Siempre a fondo, y por eso es un método aparte.** Quien pulsa «reprocesar» sobre una
     * persona acaba de tocar algo suyo —corregir el manifiesto, girar su escaneo— y quiere ver el
     * resultado: saltarse lo ya resuelto, que es lo correcto en la tanda, aquí sería no hacer nada
     * y parecer que sí.
     *
     * No cuesta nada: la lectura está cacheada. Sólo paga si el escaneo se giró, porque girar la
     * tira a propósito.
     *
     * @return array<string, int>
     */
    public function validarPasajero(CotizacionFilepasajero $pasajero): array
    {
        $conteo = array_fill_keys(array_column(ValidacionIdentificacionEnum::cases(), 'value'), 0);
        $conteo['sin_documento'] = 0;

        foreach ($pasajero->getIdentificaciones() as $identificacion) {
            $this->validarUna($pasajero, $identificacion, $conteo);
        }

        $this->em->flush();

        return $conteo;
    }

    /**
     * Valida una identificación y anota el resultado en el conteo.
     *
     * ⚠️ Extraído para que la tanda y el «reprocesar» de una persona **hagan exactamente lo
     * mismo**. Duplicado, el día que cambie una regla cambiaría en un sitio y no en el otro, y
     * nadie lo notaría hasta que los dos caminos dieran veredictos distintos del mismo documento.
     *
     * @param array<string, int> $conteo
     */
    private function validarUna(
        CotizacionFilepasajero $pasajero,
        CotizacionPasajeroIdentificacion $identificacion,
        array &$conteo,
    ): void {
        $tipo = $identificacion->getTipo();
        $archivo = $tipo !== null ? $this->escaneoDe($pasajero, $tipo) : null;

        if ($archivo === null) {
            // Sin escaneo no hay nada contra qué validar. Se deja constancia del porqué: «sin
            // validar» a secas se lee como «se me olvidó», y esto es «no hay foto».
            $identificacion->registrarValidacion(
                ValidacionIdentificacionEnum::NO_VALIDADO,
                [],
                ['no hay escaneo de este documento en la bóveda'],
            );
            // ⚠️ Suma en los DOS: `sin_documento` es un desglose de `NO_VALIDADO`, no un estado
            // aparte. Contándolo sólo aquí, el informe decía «Sin validar 0» con un «de ésos, 8»
            // debajo, y quien lo lea por encima concluye que está todo cubierto.
            ++$conteo[ValidacionIdentificacionEnum::NO_VALIDADO->value];
            ++$conteo['sin_documento'];

            return;
        }

        $cotejo = $this->cotejar($archivo, $identificacion, $pasajero);

        $identificacion->registrarValidacion(
            $cotejo->estado,
            array_map(static fn (Discrepancia $d): array => $d->aJson(), $cotejo->discrepancias),
            $cotejo->notas,
            // ⚠️ Sólo si de verdad se leyó: apuntar el archivo cuando la lectura falló haría creer
            // que ese escaneo respalda el veredicto.
            $archivo->getDatosLeidos() !== null ? $archivo : null,
        );

        ++$conteo[$cotejo->estado->value];
    }

    /**
     * El cotejo de UN número contra SU escaneo.
     *
     * ⚠️ Coteja contra la ficha de **esa** identificación, no contra «la primera que tenga». Con
     * DNI y pasaporte a la vez, cotejar el pasaporte contra el número del DNI daría «número no
     * coincide» siempre — un aviso perfectamente falso repetido en medio manifiesto.
     */
    private function cotejar(
        CotizacionFilearchivo $archivo,
        CotizacionPasajeroIdentificacion $identificacion,
        CotizacionFilepasajero $pasajero,
    ): Cotejo {
        $leido = $this->validador->lecturaDe($archivo);
        if ($leido === null) {
            return Cotejo::ilegible($archivo->getLecturaError() ?? 'no se pudo leer el escaneo');
        }

        return Cotejo::de($leido, FichaGuardada::de($pasajero, $identificacion));
    }

    /** El escaneo que respalda ese tipo de documento, si esa persona lo tiene subido. */
    private function escaneoDe(CotizacionFilepasajero $pasajero, DocumentoTipoEnum $tipo): ?CotizacionFilearchivo
    {
        $buscado = ArchivoTipoEnum::paraValidar($tipo);
        if ($buscado === null) {
            return null;
        }

        /** @var list<CotizacionFilearchivo> $archivos */
        $archivos = $this->em->getRepository(CotizacionFilearchivo::class)
            ->findBy(['pasajero' => $pasajero, 'tipoArchivo' => $buscado]);

        // Si hay varios —se subió dos veces—, manda el más reciente: es el que la persona
        // corrigió. El anterior sigue en la bóveda, pero no es el que se coteja.
        usort($archivos, static fn (CotizacionFilearchivo $a, CotizacionFilearchivo $b): int
            => ($b->getCreatedAt()?->getTimestamp() ?? 0) <=> ($a->getCreatedAt()?->getTimestamp() ?? 0));

        return $archivos[0] ?? null;
    }
}
