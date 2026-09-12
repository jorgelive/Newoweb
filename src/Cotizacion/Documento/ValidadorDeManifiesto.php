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
            $this->adoptarEscaneosSinFicha($pasajero);

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

        $this->adoptarEscaneosSinFicha($pasajero);

        foreach ($pasajero->getIdentificaciones() as $identificacion) {
            $this->validarUna($pasajero, $identificacion, $conteo);
        }

        $this->em->flush();

        return $conteo;
    }

    /**
     * Le crea ficha a los escaneos de esta persona que no respaldan ningún número declarado.
     *
     * 🔥 **Sin esto, un escaneo de un tipo que el manifiesto no declara es INVISIBLE.** La unidad
     * de trabajo de todo lo de arriba es la identificación, no el archivo: se recorre lo que la
     * persona tiene declarado y se busca su foto. Al revés no se miraba nunca, así que quien subía
     * el DNI de alguien fichado sólo con pasaporte lo veía en «ver sus documentos» y **no** en su
     * ficha — y «reprocesar» no lo arreglaba, porque reprocesar recorre identificaciones y ahí no
     * había ninguna de DNI que recorrer. Dos veces lo mismo: el botón parecía roto y lo que
     * faltaba era la fila.
     *
     * Peor aún: el escaneo ni siquiera se LEÍA. La lectura se dispara al cotejar, así que un
     * documento sin número declarado se quedaba con `datosLeidos` a null para siempre.
     *
     * ⚠️ **Nace marcada como copiada del escaneo**, igual que la ficha entera que crea
     * {@see ResolutorDeDocumentoSuelto::crear()}. No es una segunda fuente: es la misma foto, así
     * que se queda `observado` pidiendo que alguien la confirme. Copiar un número de un OCR y
     * darlo por bueno es justo lo que no se hace aquí.
     *
     * ⚠️ **El tipo lo manda la ETIQUETA del archivo, no lo que diga el OCR.** La etiqueta la puso
     * una persona; el tipo leído es una conjetura del modelo. Si no coinciden no se adopta nada:
     * eso es un archivo mal etiquetado, y crear la ficha equivocada es peor que no crearla.
     */
    private function adoptarEscaneosSinFicha(CotizacionFilepasajero $pasajero): void
    {
        $yaDeclarados = [];
        foreach ($pasajero->getIdentificaciones() as $identificacion) {
            $tipo = $identificacion->getTipo();
            if ($tipo !== null) {
                $yaDeclarados[$tipo->value] = true;
            }
        }

        /** @var list<CotizacionFilearchivo> $archivos */
        $archivos = $this->em->getRepository(CotizacionFilearchivo::class)
            ->findBy(['pasajero' => $pasajero]);

        foreach ($archivos as $archivo) {
            // `respaldaA()` es la única definición de la pareja escaneo↔número: el reverso del DNI
            // y la autorización devuelven null porque no llevan número, y ésos no adoptan nada.
            $respalda = $archivo->getTipoArchivo()?->respaldaA();
            if ($respalda === null || isset($yaDeclarados[$respalda->value])) {
                continue;
            }

            $leido = $this->validador->lecturaDe($archivo);
            if ($leido === null || !$leido->esUtilizable() || $leido->tipo !== $respalda) {
                continue;
            }

            $identificacion = new CotizacionPasajeroIdentificacion();
            $identificacion->setPasajero($pasajero);
            $identificacion->setTipo($respalda);
            $identificacion->setNumero($leido->numero);
            $identificacion->setVencimiento($leido->vencimiento);
            $identificacion->marcarCopiadaDelEscaneo();

            $this->em->persist($identificacion);
            // A la colección también: el bucle de quien nos llamó ya la tiene hidratada, y sin
            // esto la identificación recién creada no se validaría hasta la pasada siguiente.
            $pasajero->addIdentificacion($identificacion);

            $yaDeclarados[$respalda->value] = true;
        }
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

        return Cotejo::de(
            $leido,
            FichaGuardada::de($pasajero, $identificacion),
            $leido->verificadoPorMrz() ? null : $this->bandaDeLaOtraCara($pasajero, $identificacion->getTipo()),
        );
    }

    /**
     * La MRZ de la OTRA cara, para los documentos que no la llevan donde va el número.
     *
     * 🔥 **El DNIe peruano lleva su banda en el REVERSO, y esa cara no se leía nunca.** Estaba
     * marcada como que no respalda ningún número —cierto: no lo lleva impreso— y de ahí se dedujo
     * que no servía para nada. Resultado: **ningún DNI podía llegar a «validado por MRZ»**, por
     * bueno que fuera el escaneo, y encima se le echaba la culpa a la foto del anverso.
     *
     * ⚠️ Sólo se pide si la propia cara no trae banda buena, así que al pasaporte —que la lleva en
     * la misma página que el número— no le cuesta ni una lectura de más.
     */
    private function bandaDeLaOtraCara(CotizacionFilepasajero $pasajero, ?DocumentoTipoEnum $tipo): ?Mrz
    {
        $cara = $tipo !== null ? ArchivoTipoEnum::paraVerificar($tipo) : null;
        // Para el pasaporte la cara que verifica es la que ya se leyó: no hay otra que mirar.
        if ($cara === null || $cara === ArchivoTipoEnum::paraValidar($tipo)) {
            return null;
        }

        $archivo = $this->escaneoPorTipo($pasajero, $cara);

        return $archivo !== null ? $this->validador->lecturaDe($archivo)?->mrz : null;
    }

    /** El escaneo que respalda ese tipo de documento, si esa persona lo tiene subido. */
    private function escaneoDe(CotizacionFilepasajero $pasajero, DocumentoTipoEnum $tipo): ?CotizacionFilearchivo
    {
        $buscado = ArchivoTipoEnum::paraValidar($tipo);

        return $buscado !== null ? $this->escaneoPorTipo($pasajero, $buscado) : null;
    }

    /** El escaneo de ese tipo concreto que tiene esa persona, o `null`. */
    private function escaneoPorTipo(CotizacionFilepasajero $pasajero, ArchivoTipoEnum $buscado): ?CotizacionFilearchivo
    {
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
