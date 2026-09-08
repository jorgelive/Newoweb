<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Padron;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Enum\ArchivoTipoEnum;
use App\Cotizacion\Enum\GrupoTipoEnum;
use App\Enum\DocumentoTipoEnum;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * El estado de los escaneos de identidad, persona a persona, en una hoja.
 *
 * ## Para qué, si el manifiesto ya los muestra
 *
 * Porque la pregunta que se hace de verdad no es «¿qué tiene Fulano?» sino «¿a quién le escribo
 * hoy?». Con 133 personas eso es una lista que se ordena, se filtra y se pega en un mensaje —y eso
 * es una hoja de cálculo, no un panel—. La columna que importa es la última, {@see self::COL_FALTA}:
 * dice qué pedir, ya redactado.
 *
 * ## Los tres documentos, y por qué el DNI son dos columnas
 *
 * Se piden pasaporte, DNI anverso y DNI reverso ({@see \App\Cotizacion\Enum\ArchivoTipoEnum}). El
 * DNI va por caras porque en un control migratorio no vale sólo el anverso: con una columna sola,
 * «le falta el reverso» sería indistinguible de «no ha subido nada».
 *
 * ## ⚠️ Cuenta ARCHIVOS, no marca sí/no
 *
 * Cada celda lleva la fecha de subida y, **si hay más de uno de ese tipo, cuántos hay**. No es
 * adorno: la vía del pasajero reemplaza el escaneo anterior al subir otro, pero la del operador
 * —API Platform, el formulario de la bóveda— no lo hace, y no hay índice único que lo impida. Si
 * algún día se acumulan dos pasaportes de la misma persona, esta hoja es donde se ve.
 *
 * @see \App\Cotizacion\Service\Padron\PadronPlantillaGenerador el otro .xlsx, el de los datos
 */
final readonly class ReporteDeDocumentos
{
    private const AZUL = 'FF376875';
    private const VERDE = 'FFDCFCE7';
    private const ROJO = 'FFFEE2E2';
    private const AMBAR = 'FFFEF3C7';
    private const GRIS = 'FFF1F5F9';

    public const COL_FALTA = 'Qué falta';

    /** Las columnas, en el orden en que se leen. */
    private const CABECERAS = [
        'Grupo',
        'Apellidos',
        'Nombres',
        'Tipo',
        'DNI',
        'Pasaporte',
        'Otro documento',
        'DNI anverso',
        'DNI reverso',
        'Pasaporte (escaneo)',
        self::COL_FALTA,
    ];

    /** Qué escaneo mira cada una de las tres columnas de estado, en su orden. */
    private const ESCANEOS = [
        'DNI anverso' => ArchivoTipoEnum::DNI_ANVERSO,
        'DNI reverso' => ArchivoTipoEnum::DNI_REVERSO,
        'Pasaporte (escaneo)' => ArchivoTipoEnum::PASAPORTE,
    ];

    /**
     * @param list<string>|null $soloEstos ids de pasajero; `null` es el expediente entero
     */
    public function generar(CotizacionFile $file, ?array $soloEstos = null): string
    {
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Documentos');

        $ultima = count(self::CABECERAS);

        // Título: el expediente y CUÁNDO se sacó. Una hoja de faltantes sin fecha se reenvía
        // semanas después como si siguiera vigente.
        $hoja->setCellValue([1, 1], sprintf(
            'Documentos de identidad — %s — generado el %s',
            (string) $file->getNombreGrupo(),
            (new \DateTimeImmutable())->format('d/m/Y H:i'),
        ));
        $hoja->mergeCells([1, 1, $ultima, 1]);
        $hoja->getStyle([1, 1])->getFont()->setBold(true)->setSize(12);
        $hoja->getRowDimension(1)->setRowHeight(22);

        foreach (self::CABECERAS as $i => $cabecera) {
            $hoja->setCellValue([$i + 1, 2], $cabecera);
        }
        $hoja->getStyle([1, 2, $ultima, 2])->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::AZUL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $hoja->getRowDimension(2)->setRowHeight(20);

        $permitidos = $soloEstos === null ? null : array_flip($soloEstos);
        $fila = 3;
        $completos = 0;
        $parciales = 0;
        $vacios = 0;

        foreach ($file->getFilepasajeros() as $pasajero) {
            if ($permitidos !== null && !isset($permitidos[(string) $pasajero->getId()])) {
                continue;
            }

            $subidos = $this->escaneosDe($file, $pasajero);
            $faltan = [];

            $this->texto($hoja, 1, $fila, $this->gruposDe($pasajero));
            $this->texto($hoja, 2, $fila, $pasajero->getApellido());
            $this->texto($hoja, 3, $fila, $pasajero->getNombre());
            $this->texto($hoja, 4, $fila, $pasajero->getTipo()?->label());
            $this->texto($hoja, 5, $fila, $this->documento($pasajero, DocumentoTipoEnum::DNI));
            $this->texto($hoja, 6, $fila, $this->documento($pasajero, DocumentoTipoEnum::PASAPORTE));
            $this->texto($hoja, 7, $fila, $this->otrosDocumentos($pasajero));

            $columna = 8;
            foreach (self::ESCANEOS as $etiqueta => $tipo) {
                /** @var list<\DateTimeImmutable|null> $cuando */
                $cuando = $subidos[$tipo->value] ?? [];
                $cuantos = count($cuando);

                if ($cuantos === 0) {
                    $faltan[] = $etiqueta;
                    $this->texto($hoja, $columna, $fila, '—');
                    $hoja->getStyle([$columna, $fila])->getFill()
                        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::ROJO);
                } else {
                    $reciente = $cuando[0]?->format('d/m/Y') ?? 'sí';
                    $this->texto($hoja, $columna, $fila, $cuantos > 1
                        ? sprintf('%s (%d archivos)', $reciente, $cuantos)
                        : $reciente);
                    // Ámbar para los duplicados: hay documento, pero hay que mirarlo.
                    $hoja->getStyle([$columna, $fila])->getFill()
                        ->setFillType(Fill::FILL_SOLID)->getStartColor()
                        ->setARGB($cuantos > 1 ? self::AMBAR : self::VERDE);
                }

                ++$columna;
            }

            $this->texto($hoja, $ultima, $fila, $faltan === [] ? 'Completo' : implode(', ', $faltan));
            if ($faltan !== []) {
                $hoja->getStyle([$ultima, $fila])->getFont()->setBold(true);
            }

            match (count($faltan)) {
                0 => $completos++,
                count(self::ESCANEOS) => $vacios++,
                default => $parciales++,
            };

            ++$fila;
        }

        $total = $fila - 3;

        // El resumen ABAJO y no arriba: arriba desplazaría las filas y rompería el autofiltro.
        $hoja->setCellValue([1, $fila + 1], sprintf(
            '%d personas · %d completas · %d a medias · %d sin nada',
            $total,
            $completos,
            $parciales,
            $vacios,
        ));
        $hoja->mergeCells([1, $fila + 1, $ultima, $fila + 1]);
        $hoja->getStyle([1, $fila + 1])->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::GRIS]],
        ]);

        if ($total > 0) {
            $hoja->getStyle([1, 2, $ultima, $fila - 1])->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFCBD5E1');
            $hoja->setAutoFilter($hoja->calculateWorksheetDimension());
        }

        // Congelar cabecera Y las dos columnas de nombre: al desplazarse a la derecha para ver los
        // escaneos, sin esto se pierde de quién es la fila.
        $hoja->freezePane('D3');

        foreach (range(1, $ultima) as $c) {
            $hoja->getColumnDimensionByColumn($c)->setAutoSize(true);
        }

        $escritor = new Xlsx($libro);
        ob_start();
        $escritor->save('php://output');
        $contenido = (string) ob_get_clean();
        $libro->disconnectWorksheets();

        return $contenido;
    }

    private function texto(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $hoja, int $columna, int $fila, ?string $valor): void
    {
        if ($valor === null || $valor === '') {
            return;
        }
        // Explícito SIEMPRE: un DNI que empieza por cero deja de serlo si Excel lo lee como número.
        $hoja->setCellValueExplicit([$columna, $fila], $valor, DataType::TYPE_STRING);
    }

    /**
     * Los escaneos de esa persona, agrupados por tipo y **del más nuevo al más viejo**.
     *
     * @return array<string, list<\DateTimeImmutable|null>>
     */
    private function escaneosDe(CotizacionFile $file, CotizacionFilepasajero $pasajero): array
    {
        $id = (string) $pasajero->getId();
        $porTipo = [];

        foreach ($file->getFilearchivos() as $archivo) {
            $tipo = $archivo->getTipoArchivo();
            if ($tipo === null || !in_array($tipo, self::ESCANEOS, true)) {
                continue;
            }
            if ((string) $archivo->getPasajero()?->getId() !== $id) {
                continue;
            }
            $porTipo[$tipo->value][] = $archivo->getCreatedAt();
        }

        foreach ($porTipo as &$fechas) {
            usort($fechas, static fn (?\DateTimeImmutable $a, ?\DateTimeImmutable $b): int
                => ($b?->getTimestamp() ?? 0) <=> ($a?->getTimestamp() ?? 0));
        }

        return $porTipo;
    }

    /** El número del documento de ese tipo, si lo tiene. */
    private function documento(CotizacionFilepasajero $pasajero, DocumentoTipoEnum $tipo): ?string
    {
        foreach ($pasajero->getIdentificaciones() as $identificacion) {
            if ($identificacion->getTipo() === $tipo) {
                return $identificacion->getNumero();
            }
        }

        return null;
    }

    /**
     * Lo que no es DNI ni pasaporte —CE, CI, RUC— con su tipo delante.
     *
     * Va en una sola columna porque son la excepción: darles una columna a cada uno dejaría tres
     * vacías en el 95 % de los expedientes.
     */
    private function otrosDocumentos(CotizacionFilepasajero $pasajero): ?string
    {
        $otros = [];

        foreach ($pasajero->getIdentificaciones() as $identificacion) {
            $tipo = $identificacion->getTipo();
            if ($tipo === null || $tipo === DocumentoTipoEnum::DNI || $tipo === DocumentoTipoEnum::PASAPORTE) {
                continue;
            }
            $otros[] = sprintf('%s %s', $tipo->value, (string) $identificacion->getNumero());
        }

        return $otros === [] ? null : implode(' · ', $otros);
    }

    /**
     * A qué grupo pertenece.
     *
     * Sólo los de tipo `grupo`: la habitación y la reserva aérea también son grupos suyos, pero
     * quien reclama documentos lo hace por aula, no por vuelo.
     */
    private function gruposDe(CotizacionFilepasajero $pasajero): ?string
    {
        $claves = [];

        foreach ($pasajero->grupos() as $grupo) {
            if ($grupo->getTipo() !== GrupoTipoEnum::GRUPO) {
                continue;
            }
            $claves[] = (string) $grupo->getClave();
        }

        return $claves === [] ? null : implode(', ', $claves);
    }
}
