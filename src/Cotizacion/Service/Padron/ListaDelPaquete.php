<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Padron;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Enum\ArchivoTipoEnum;
use App\Enum\DocumentoTipoEnum;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * La lista que acompaña al ZIP de escaneos: **lo que va dentro, y nada más**.
 *
 * ── Por qué no vale el informe de siempre ───────────────────────────────────
 * El paquete llevaba {@see ReporteDeDocumentos}, que es el informe de CONTROL: quién ha subido
 * qué, qué falta, qué observó la validación. Eso es para nosotros y responde «a quién hay que
 * escribirle hoy».
 *
 * ⚠️ **Quien recibe el sobre no tiene ese problema**: necesita cotejar los ficheros que le
 * llegaron con una lista de personas, números y caducidades. Mandarle además nuestras
 * observaciones internas —«nombre no coincide», «sin comprobar»— es enseñarle dudas sobre sus
 * huéspedes que no le tocaba resolver, y encima le entierra el dato que sí buscaba.
 *
 * ── Una fila por DOCUMENTO, no por persona ──────────────────────────────────
 * Quien tiene pasaporte y DNI sale en dos filas. Es lo que hace que la lista se pueda contar
 * contra los ficheros del ZIP: **una fila, un fichero**. Con una fila por persona y columnas por
 * tipo, contar cuadraba sólo si nadie llevaba dos documentos.
 *
 * ── Y sólo de los tipos que se piden ────────────────────────────────────────
 * Si se manda únicamente el pasaporte, la lista no menciona el DNI. Una columna vacía en una hoja
 * que va fuera de casa se lee como un dato que falta, no como uno que no se pidió.
 */
final readonly class ListaDelPaquete
{
    private const CABECERAS = ['Apellidos', 'Nombres', 'Documento', 'Número', 'Vence', 'Archivo'];

    /**
     * @param list<array{pasajero: CotizacionFilepasajero, tipo: ArchivoTipoEnum, archivo: string}> $filas
     *        Lo que de verdad entró en el ZIP, en su orden, con el nombre del fichero.
     */
    public function generar(CotizacionFile $file, array $filas): string
    {
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Documentos');

        $hoja->setCellValue('A1', sprintf('Documentos de identidad — %s', (string) $file->getNombreGrupo()));
        $hoja->mergeCells('A1:F1');
        $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        foreach (self::CABECERAS as $i => $cabecera) {
            $hoja->setCellValue([$i + 1, 3], $cabecera);
        }

        $hoja->getStyle('A3:F3')->getFont()->setBold(true);

        $fila = 4;

        foreach ($filas as $dato) {
            $pasajero = $dato['pasajero'];
            $identificacion = $this->identificacionDe($pasajero, $dato['tipo']->respaldaA());

            $hoja->setCellValue([1, $fila], (string) $pasajero->getApellido());
            $hoja->setCellValue([2, $fila], (string) $pasajero->getNombre());
            $hoja->setCellValue([3, $fila], $dato['tipo']->getLabel());

            // ⚠️ El número como TEXTO. Un DNI peruano empieza por cero más veces de las que
            // parece, y Excel se lo come: «08123456» llega como 8123456 y ya no casa con nada.
            $hoja->setCellValueExplicit([4, $fila], (string) ($identificacion?->getNumero() ?? ''), DataType::TYPE_STRING);

            $hoja->setCellValue([5, $fila], $identificacion?->getVencimiento()?->format('d/m/Y') ?? '');
            $hoja->setCellValue([6, $fila], $dato['archivo']);

            $fila++;
        }

        foreach (range('A', 'F') as $columna) {
            $hoja->getColumnDimension($columna)->setAutoSize(true);
        }

        $hoja->setCellValue([1, $fila + 1], sprintf('%d documentos.', count($filas)));
        $hoja->getStyle([1, $fila + 1])->getFont()->setBold(true);

        $temporal = tempnam(sys_get_temp_dir(), 'lista_');
        (new Xlsx($libro))->save((string) $temporal);
        $contenido = (string) file_get_contents((string) $temporal);
        unlink((string) $temporal);
        $libro->disconnectWorksheets();

        return $contenido;
    }

    /** El número del manifiesto que respalda ese escaneo. */
    private function identificacionDe(
        CotizacionFilepasajero $pasajero,
        ?DocumentoTipoEnum $tipo,
    ): ?\App\Cotizacion\Entity\CotizacionPasajeroIdentificacion {
        if ($tipo === null) {
            return null;
        }

        foreach ($pasajero->getIdentificaciones() as $identificacion) {
            if ($identificacion->getTipo() === $tipo) {
                return $identificacion;
            }
        }

        return null;
    }
}
