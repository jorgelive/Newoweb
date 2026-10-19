<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Padron;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Entity\CotizacionPasajeroIdentificacion;
use App\Cotizacion\Enum\PasajeroTipoEnum;
use App\Entity\Maestro\MaestroPais;
use App\Enum\DocumentoTipoEnum;
use DateTimeImmutable;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use ZipArchive;

/**
 * La lista de pasajeros en el formato que PeruRail pide para emitir los boletos de un grupo.
 *
 * Reimplementa `CotizacionFileController::archivoprAction()` de Oweb (archivado el 17/09/2026,
 * etiqueta `oweb-final`). Lo que se conserva del original, a propósito:
 *
 * - **Se rellena SU plantilla** (`public/templates/perurail.xlsx`), no una hoja propia: es la que
 *   PeruRail entrega, con su hoja INDICACIONES y sus listas desplegables, y la que su sistema lee.
 * - **Desde la fila 2 y con los mismos valores** que mandaba Oweb y PeruRail aceptaba: códigos
 *   pelados (`1`, `M`, `PAS`, `207`), no el «1-Adult» de su ejemplo.
 * - **100 personas por archivo.** Las listas desplegables de la plantilla llegan hasta la fila 101;
 *   con más gente se parte en varios archivos y se bajan en un ZIP.
 *
 * Lo que cambia respecto a Oweb:
 *
 * - **La edad se calcula al día del tren**, no a hoy. Ver {@see CotizacionFilepasajero::edadAl()}.
 * - **Un dato que falta no tumba la descarga.** Oweb llamaba a `->getCodigopr()` sobre un país o un
 *   documento nulos y daba un 500. Aquí la celda queda vacía, pintada de ámbar, y la persona se
 *   cuenta en {@see ResultadoListaPeruRail::$incompletos} para avisar antes de subirla.
 * - **Los «No participa» no salen** en la lista completa: no viajan. Con filtro manda el panel.
 */
final readonly class ListaPeruRail
{
    public const PLANTILLA = 'public/templates/perurail.xlsx';

    /** Hasta dónde llegan las listas desplegables de la plantilla (filas 2 a 101). */
    public const MAX_POR_ARCHIVO = 100;

    private const HOJA = 'PASAJEROS';
    private const PRIMERA_FILA = 2;
    private const AMBAR = 'FFFEF3C7';

    /**
     * Qué documento se manda cuando la persona tiene varios.
     *
     * El peruano viaja con DNI (y PeruRail le aplica tarifa nacional con él); el extranjero, con el
     * pasaporte. Los demás son el plan B en ese orden. El RUC nunca: no es documento de viaje.
     */
    private const PREFERENCIA_PERUANO = [
        DocumentoTipoEnum::DNI,
        DocumentoTipoEnum::PASAPORTE,
        DocumentoTipoEnum::CE,
        DocumentoTipoEnum::CI,
    ];
    private const PREFERENCIA_EXTRANJERO = [
        DocumentoTipoEnum::PASAPORTE,
        DocumentoTipoEnum::CE,
        DocumentoTipoEnum::CI,
        DocumentoTipoEnum::DNI,
    ];

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    /**
     * @param list<string>|null $soloEstos ids de pasajero; `null` es el expediente entero sin los «No participa»
     * @param DateTimeInterface|null $fechaDelTren contra qué día se calcula adulto/niño; `null` es hoy
     */
    public function generar(
        CotizacionFile $file,
        ?array $soloEstos = null,
        ?DateTimeInterface $fechaDelTren = null,
    ): ResultadoListaPeruRail {
        $alDia = $fechaDelTren ?? new DateTimeImmutable('today');
        $personas = $this->seleccionados($file, $soloEstos);

        if ($personas === []) {
            throw new RuntimeException('No hay pasajeros que exportar.');
        }

        $base = 'PERURAIL_' . $this->nombreSeguro($file);
        $partes = array_chunk($personas, self::MAX_POR_ARCHIVO);
        /** @var list<string> $incompletos */
        $incompletos = [];

        $libros = [];
        foreach ($partes as $parte) {
            $libros[] = $this->libro($parte, $alDia, $incompletos);
        }

        if (count($libros) === 1) {
            return new ResultadoListaPeruRail($libros[0], $base . '.xlsx', false, count($personas), $incompletos);
        }

        return new ResultadoListaPeruRail(
            $this->zip($libros, $base),
            $base . '.zip',
            true,
            count($personas),
            $incompletos,
        );
    }

    /**
     * @param list<CotizacionFilepasajero> $personas
     * @param list<string> $incompletos se le añade quien tenga algún hueco
     */
    private function libro(array $personas, DateTimeInterface $alDia, array &$incompletos): string
    {
        $ruta = $this->projectDir . '/' . self::PLANTILLA;
        if (!is_file($ruta)) {
            throw new RuntimeException(sprintf('Falta la plantilla de PeruRail en %s.', self::PLANTILLA));
        }

        $libro = IOFactory::load($ruta);
        $hoja = $libro->getSheetByName(self::HOJA) ?? $libro->getSheet(0);
        $libro->setActiveSheetIndex($libro->getIndex($hoja));

        $fila = self::PRIMERA_FILA;
        foreach ($personas as $pasajero) {
            $faltan = $this->escribirFila($hoja, $fila, $pasajero, $alDia);
            if ($faltan !== []) {
                $incompletos[] = sprintf(
                    '%s: %s',
                    trim(sprintf('%s %s', $pasajero->getApellido(), $pasajero->getNombre())) ?: '(sin nombre)',
                    implode(', ', $faltan),
                );
            }
            ++$fila;
        }

        $escritor = new Xlsx($libro);
        ob_start();
        $escritor->save('php://output');
        $contenido = (string) ob_get_clean();
        $libro->disconnectWorksheets();

        return $contenido;
    }

    /**
     * Columnas A–H de la plantilla: TIPO PASAJERO, GENERO(F/M), TIPO DOC, NRO DOC, PRIMER NOMBRE,
     * PRIMER APELLIDO, FECHA NAC., NACIONALIDAD.
     *
     * @return list<string> los rótulos de lo que falta
     */
    private function escribirFila(Worksheet $hoja, int $fila, CotizacionFilepasajero $pasajero, DateTimeInterface $alDia): array
    {
        $documento = $this->documentoDeViaje($pasajero);
        $pais = $pasajero->getPais() ?? $documento?->getPaisEmisor();

        $celdas = [
            'tipo pasajero' => $pasajero->getTipopaxperurail($alDia),
            'género' => $pasajero->getSexo()?->value,
            'tipo de documento' => $documento?->getTipo()?->getCodigoPeruRail(),
            'número de documento' => $this->limpiarNumero($documento?->getNumero()),
            'nombre' => $this->limpiarTexto($pasajero->getNombre()),
            'apellido' => $this->limpiarTexto($pasajero->getApellido()),
            'fecha de nacimiento' => $pasajero->getFechanacimiento()?->format('d/m/Y'),
            'nacionalidad' => $pais?->getCodigoPeruRail(),
        ];

        $faltan = [];
        $columna = 1;
        foreach ($celdas as $rotulo => $valor) {
            if ($valor === null || $valor === '') {
                $faltan[] = $rotulo;
                $hoja->getStyle([$columna, $fila])->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::AMBAR);
            } elseif (is_int($valor)) {
                $hoja->setCellValueExplicit([$columna, $fila], $valor, DataType::TYPE_NUMERIC);
            } else {
                // Explícito: un DNI que empieza por cero deja de serlo si Excel lo lee como número,
                // y una fecha d/m/Y tecleada como texto es lo que la plantilla espera.
                $hoja->setCellValueExplicit([$columna, $fila], $valor, DataType::TYPE_STRING);
            }
            ++$columna;
        }

        // Un país sin código PeruRail cargado es un dato del maestro, no del pasajero: se dice cuál.
        if ($pais !== null && $pais->getCodigoPeruRail() === null) {
            $i = array_search('nacionalidad', $faltan, true);
            if ($i !== false) {
                $faltan[$i] = sprintf('código PeruRail del país %s', (string) $pais->getNombre());
            }
        }

        return $faltan;
    }

    private function documentoDeViaje(CotizacionFilepasajero $pasajero): ?CotizacionPasajeroIdentificacion
    {
        $esPeruano = $pasajero->getPais()?->getId() === MaestroPais::ID_PERU;
        $orden = $esPeruano ? self::PREFERENCIA_PERUANO : self::PREFERENCIA_EXTRANJERO;

        foreach ($orden as $tipo) {
            $identificacion = $pasajero->identificacionDe($tipo);
            if ($identificacion !== null && trim((string) $identificacion->getNumero()) !== '') {
                return $identificacion;
            }
        }

        return null;
    }

    /**
     * @param list<string>|null $soloEstos
     * @return list<CotizacionFilepasajero>
     */
    private function seleccionados(CotizacionFile $file, ?array $soloEstos): array
    {
        $permitidos = $soloEstos === null ? null : array_flip(array_map('strtolower', $soloEstos));
        $personas = [];

        foreach ($file->getFilepasajeros() as $pasajero) {
            if ($permitidos === null) {
                if ($pasajero->getTipo() === PasajeroTipoEnum::NO_PARTICIPA) {
                    continue;
                }
            } elseif (!isset($permitidos[strtolower((string) $pasajero->getId())])) {
                continue;
            }
            $personas[] = $pasajero;
        }

        // Por apellido: PeruRail devuelve los boletos en el orden de la lista, y así se encuentran.
        usort($personas, static fn (CotizacionFilepasajero $a, CotizacionFilepasajero $b): int =>
            mb_strtolower(trim(sprintf('%s %s', $a->getApellido(), $a->getNombre())))
            <=> mb_strtolower(trim(sprintf('%s %s', $b->getApellido(), $b->getNombre()))));

        return $personas;
    }

    /** Sin espacios ni guiones: es como viene en el chip del documento y como lo teclea PeruRail. */
    private function limpiarNumero(?string $numero): ?string
    {
        if ($numero === null) {
            return null;
        }
        $limpio = strtoupper((string) preg_replace('/[\s.\-]+/u', '', $this->sinTildes($numero)));

        return $limpio === '' ? null : $limpio;
    }

    private function limpiarTexto(?string $texto): ?string
    {
        if ($texto === null) {
            return null;
        }
        $limpio = trim((string) preg_replace('/\s+/u', ' ', $texto));

        return $limpio === '' ? null : $limpio;
    }

    private function sinTildes(string $texto): string
    {
        $sin = \Normalizer::normalize($texto, \Normalizer::FORM_D);

        return $sin === false ? $texto : (string) preg_replace('/\p{Mn}+/u', '', $sin);
    }

    private function nombreSeguro(CotizacionFile $file): string
    {
        $nombre = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $this->sinTildes((string) $file->getNombreGrupo())), '-');

        return $nombre === '' ? 'grupo' : $nombre;
    }

    /**
     * @param list<string> $libros
     */
    private function zip(array $libros, string $base): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'perurail_');
        if ($ruta === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal del ZIP.');
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($ruta, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('No se pudo abrir el ZIP para escribir.');
            }
            foreach ($libros as $i => $contenido) {
                $zip->addFromString(sprintf('%s_Parte_%d.xlsx', $base, $i + 1), $contenido);
            }
            $zip->close();

            $contenido = file_get_contents($ruta);
            if ($contenido === false) {
                throw new RuntimeException('No se pudo leer el ZIP generado.');
            }

            return $contenido;
        } finally {
            @unlink($ruta);
        }
    }
}
