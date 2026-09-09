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
    /**
     * Qué escaneo respalda qué número. Un DNI se comprueba contra el **anverso** —el reverso no
     * lleva número— y un pasaporte contra el escaneo del pasaporte.
     *
     * ⚠️ `CE` y `CI` no tienen escaneo propio en el catálogo de tipos de archivo, así que **no se
     * pueden validar**: se quedan en `NO_VALIDADO` y eso es correcto, no un fallo. Inventarles un
     * archivo genérico haría que se cotejaran contra el documento de otra cosa.
     */
    private const ESCANEO_DE = [
        DocumentoTipoEnum::DNI->value => ArchivoTipoEnum::DNI_ANVERSO,
        DocumentoTipoEnum::PASAPORTE->value => ArchivoTipoEnum::PASAPORTE,
    ];

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

                $tipo = $identificacion->getTipo();
                $archivo = $tipo !== null ? $this->escaneoDe($pasajero, $tipo) : null;

                if ($archivo === null) {
                    // Sin escaneo no hay nada contra qué validar. Se deja constancia del porqué:
                    // «sin validar» a secas se lee como «se me olvidó», y esto es «no hay foto».
                    $identificacion->registrarValidacion(
                        ValidacionIdentificacionEnum::NO_VALIDADO,
                        [],
                        ['no hay escaneo de este documento en la bóveda'],
                    );
                    // ⚠️ Suma en los DOS: `sin_documento` es un desglose de `NO_VALIDADO`, no un
                    // estado aparte. Contándolo sólo aquí, el informe decía «Sin validar 0» con un
                    // «de ésos, 8» debajo — y quien lo lea por encima concluye que está todo
                    // cubierto cuando hay ocho números sin comprobar.
                    ++$conteo[ValidacionIdentificacionEnum::NO_VALIDADO->value];
                    ++$conteo['sin_documento'];
                    continue;
                }

                $cotejo = $this->cotejar($archivo, $identificacion, $pasajero);

                $identificacion->registrarValidacion(
                    $cotejo->estado,
                    array_map(static fn (Discrepancia $d): array => $d->aJson(), $cotejo->discrepancias),
                    $cotejo->notas,
                    // ⚠️ Sólo si de verdad se leyó: apuntar el archivo cuando la lectura falló
                    // haría creer que ese escaneo respalda el veredicto.
                    $archivo->getDatosLeidos() !== null ? $archivo : null,
                );

                ++$conteo[$cotejo->estado->value];
                ++$hechos;
            }
        }

        $this->em->flush();

        return $conteo;
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
        $buscado = self::ESCANEO_DE[$tipo->value] ?? null;
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
