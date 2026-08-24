<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Padron;

use App\Cotizacion\Enum\GrupoTipoEnum;
use App\Cotizacion\Enum\PasajeroTipoEnum;
use App\Entity\Maestro\MaestroPais;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Construye el .xlsx del padrón a partir de {@see PadronFormato}.
 *
 * ## Por qué se genera y no es un archivo guardado
 *
 * Porque las columnas salen de los enums. Un `DocumentoTipoEnum` nuevo aparece en la plantilla el
 * mismo día que en el código, sin que nadie tenga que acordarse de resubir un fichero — y una
 * plantilla desactualizada es peor que ninguna: la rellenan igual y el dato se pierde al importar.
 *
 * ## Trae ejemplos, y son dos a propósito
 *
 * Uno con **todo** —dos documentos y cuatro ejes— y otro con **lo mínimo**: un nombre y un
 * pasaporte. El segundo es el que enseña que las columnas sobran, que es lo que nadie deduce
 * mirando una plantilla llena.
 */
final readonly class PadronPlantillaGenerador
{
    public function __construct(private EntityManagerInterface $em) {}

    public const NOMBRE_ARCHIVO = 'padron-plantilla.xlsx';

    private const AZUL = 'FF376875';
    private const NARANJA = 'FFE07845';
    private const GRIS = 'FFF1F5F9';

    /** Un color por banda: se ve de un vistazo qué es imprescindible y qué se puede borrar. */
    private const COLOR_DE_BANDA = [
        'clave' => 'FF0F766E',   // teal oscuro: sin esto no hay fila
        'normal' => self::AZUL,  // lo de cualquier expediente
        'grupo' => self::NARANJA,// sólo si es un padrón: se borra entero si no
    ];

    public function generar(): string
    {
        $libro = new Spreadsheet();
        $this->hojaPasajeros($libro);
        $this->hojaInstrucciones($libro);
        $this->hojaTablas($libro);
        $libro->setActiveSheetIndex(0);

        $temporal = tempnam(sys_get_temp_dir(), 'padron_');
        (new Xlsx($libro))->save($temporal);
        $contenido = (string) file_get_contents($temporal);
        unlink($temporal);
        $libro->disconnectWorksheets();

        return $contenido;
    }

    private function hojaPasajeros(Spreadsheet $libro): void
    {
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Pasajeros');

        $cabeceras = PadronFormato::cabeceras();

        foreach ($cabeceras as $i => $cabecera) {
            $col = $i + 1;
            $hoja->setCellValue([$col, 1], $cabecera);

            $estilo = $hoja->getStyle([$col, 1]);
            $estilo->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            // Un color por banda: verde lo imprescindible, azul lo de cualquier expediente,
            // naranja lo que sólo hace falta en un padrón — y que se borra de una pasada.
            $estilo->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB(self::COLOR_DE_BANDA[PadronFormato::bandaDe($cabecera)]);
            $estilo->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $hoja->getColumnDimensionByColumn($col)->setWidth(max(12, mb_strlen($cabecera) + 4));
        }

        // ── Dos ejemplos: el completo y el mínimo ───────────────────────────
        $porNombre = array_flip($cabeceras);
        $ejemplos = [
            [
                PadronFormato::COL_NOMBRES => 'Nune',
                PadronFormato::COL_APELLIDOS => 'Asatryan',
                PadronFormato::COL_NACIONALIDAD => 'US',
                PadronFormato::COL_SEXO => 'F',
                PadronFormato::COL_NACIMIENTO => '15/03/1988',
                'Pasaporte' => '584605247',
                'Venc. Pasaporte' => '27/05/2031',
                'DNI' => '73398300',
                'Venc. DNI' => '04/08/2028',
                PadronFormato::MARCA_EJE.'Grupo' => '5',
                PadronFormato::MARCA_EJE.'Habitación' => 'HA13',
                PadronFormato::MARCA_EJE.'Reserva aérea' => 'JA2CWN',
                PadronFormato::MARCA_SERVICIO.'Seguro' => 'SI',
                PadronFormato::MARCA_SERVICIO.'Tour Saona' => 'SI',
                PadronFormato::MARCA_SERVICIO.'Coco Bongo' => 'NO',
                PadronFormato::MARCA_SERVICIO.'Hotel' => 'SI',
            ],
            [
                PadronFormato::COL_NOMBRES => 'Todd Joseph',
                PadronFormato::COL_APELLIDOS => 'Rouse',
                'Pasaporte' => '679305395',
            ],
        ];

        foreach ($ejemplos as $n => $ejemplo) {
            $fila = $n + 2;
            foreach ($ejemplo as $cabecera => $valor) {
                if (isset($porNombre[$cabecera])) {
                    $hoja->setCellValueExplicit(
                        [$porNombre[$cabecera] + 1, $fila],
                        $valor,
                        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING,
                    );
                }
            }
            $hoja->getStyle([1, $fila, count($cabeceras), $fila])
                ->getFont()->setItalic(true)->getColor()->setARGB('FF94A3B8');
        }

        $hoja->setCellValue([1, 5], '↑ Las dos filas de arriba son EJEMPLOS: bórralas antes de importar.');
        $hoja->getStyle([1, 5])->getFont()->setBold(true)->getColor()->setARGB(self::NARANJA);

        // ── Desplegables, y para TODA la columna ────────────────────────────
        //
        // Es lo que hace que el formato pueda ser estricto sin castigar a nadie: si la hoja ofrece
        // los valores, exigirlos deja de ser una trampa. Antes se adivinaba «alumno» o «acompa» —y
        // eso convertía un «Alumbo» mal escrito en un silencio—.
        $listas = [
            PadronFormato::COL_SEXO => '"M,F"',
            PadronFormato::COL_TIPO => '"'.implode(',', PasajeroTipoEnum::valoresValidos()).'"',
        ];

        foreach ($listas as $columna => $formula) {
            if (!isset($porNombre[$columna])) {
                continue;
            }

            $letra = $hoja->getCell([$porNombre[$columna] + 1, 1])->getColumn();
            $validacion = $hoja->getDataValidation(sprintf('%s2:%s500', $letra, $letra));
            $validacion->setType(DataValidation::TYPE_LIST)
                ->setAllowBlank(true)
                ->setShowDropDown(true)
                ->setShowErrorMessage(true)
                ->setErrorTitle('Valor no válido')
                ->setError('Elige uno de la lista. La hoja «Tablas» los enumera.')
                ->setFormula1($formula);
        }

        // La nacionalidad contra la tabla de países: 198 valores no caben en una fórmula literal.
        if (isset($porNombre[PadronFormato::COL_NACIONALIDAD])) {
            $letra = $hoja->getCell([$porNombre[PadronFormato::COL_NACIONALIDAD] + 1, 1])->getColumn();
            $validacion = $hoja->getDataValidation(sprintf('%s2:%s500', $letra, $letra));
            $validacion->setType(DataValidation::TYPE_LIST)
                ->setAllowBlank(true)
                ->setShowDropDown(true)
                ->setShowErrorMessage(true)
                ->setErrorTitle('País no reconocido')
                ->setError('Usa el código ISO de dos letras. La hoja «Tablas» los lista todos.')
                ->setFormula1('Tablas!$A$3:$A$500');
        }

        $hoja->freezePane('A2');
    }

    /**
     * Los valores válidos, en su propia hoja.
     *
     * Existe para que el formato pueda ser **estricto sin castigar a nadie**: si la plantilla trae
     * la lista y el desplegable, exigir el valor exacto deja de ser una trampa y pasa a ser lo
     * único razonable. Adivinar «alumno» o «acompa» convertía un «Alumbo» mal escrito en un
     * silencio, y el rol decide qué ve cada persona.
     */
    private function hojaTablas(Spreadsheet $libro): void
    {
        $hoja = $libro->createSheet();
        $hoja->setTitle('Tablas');
        $hoja->getColumnDimensionByColumn(1)->setWidth(10);
        $hoja->getColumnDimensionByColumn(2)->setWidth(34);
        $hoja->getColumnDimensionByColumn(4)->setWidth(24);
        $hoja->getColumnDimensionByColumn(5)->setWidth(34);

        $cabecera = function (int $col, int $fila, string $texto) use ($hoja): void {
            $hoja->setCellValue([$col, $fila], $texto);
            $estilo = $hoja->getStyle([$col, $fila]);
            $estilo->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $estilo->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::AZUL);
        };

        $cabecera(1, 1, 'PAÍSES — usa el código');
        $hoja->mergeCells([1, 1, 2, 1]);
        $cabecera(1, 2, 'Código');
        $cabecera(2, 2, 'País');

        /** @var list<MaestroPais> $paises */
        $paises = $this->em->getRepository(MaestroPais::class)->findBy([], ['nombre' => 'ASC']);
        foreach ($paises as $i => $pais) {
            $hoja->setCellValueExplicit([1, $i + 3], (string) $pais->getId(), DataType::TYPE_STRING);
            $hoja->setCellValue([2, $i + 3], $pais->getNombre());
        }

        $cabecera(4, 1, 'SEXO');
        $hoja->mergeCells([4, 1, 5, 1]);
        $hoja->setCellValue([4, 2], 'M');
        $hoja->setCellValue([5, 2], 'Masculino');
        $hoja->setCellValue([4, 3], 'F');
        $hoja->setCellValue([5, 3], 'Femenino');

        $cabecera(4, 5, 'ROL — escribe uno de éstos, tal cual');
        $hoja->mergeCells([4, 5, 5, 5]);
        foreach (PasajeroTipoEnum::cases() as $i => $rol) {
            $hoja->setCellValue([4, $i + 6], $rol->label());
            $hoja->setCellValue([5, $i + 6], match ($rol) {
                PasajeroTipoEnum::PARTICIPANTE => 'Ve sólo lo suyo. El caso normal.',
                PasajeroTipoEnum::PADRE => 'Ve sólo lo suyo.',
                PasajeroTipoEnum::COORDINADOR => 'Ve su grupo entero.',
                PasajeroTipoEnum::SUPERVISOR => 'Ve el viaje entero, menos los invitados.',
                PasajeroTipoEnum::INVITADO => 'Ve sólo lo suyo, y NO APARECE para los demás.',
                PasajeroTipoEnum::NO_PARTICIPA => 'Figura en el padrón pero no viaja.',
            });
        }
        $hoja->getStyle([4, 5, 5, 5 + count(PasajeroTipoEnum::cases())])->getAlignment()->setWrapText(true);
    }

    private function hojaInstrucciones(Spreadsheet $libro): void
    {
        $hoja = $libro->createSheet();
        $hoja->setTitle('Instrucciones');
        $hoja->getColumnDimensionByColumn(1)->setWidth(34);
        $hoja->getColumnDimensionByColumn(2)->setWidth(96);

        $fila = 1;
        $titulo = function (string $texto) use ($hoja, &$fila): void {
            $hoja->setCellValue([1, $fila], $texto);
            $hoja->mergeCells([1, $fila, 2, $fila]);
            $estilo = $hoja->getStyle([1, $fila]);
            $estilo->getFont()->setBold(true)->setSize(12)->getColor()->setARGB('FFFFFFFF');
            $estilo->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::AZUL);
            ++$fila;
        };
        $linea = function (string $izquierda, string $derecha) use ($hoja, &$fila): void {
            $hoja->setCellValue([1, $fila], $izquierda);
            $hoja->setCellValue([2, $fila], $derecha);
            $hoja->getStyle([1, $fila])->getFont()->setBold(true);
            $hoja->getStyle([2, $fila])->getAlignment()->setWrapText(true);
            ++$fila;
        };
        $nota = function (string $texto) use ($hoja, &$fila): void {
            $hoja->setCellValue([1, $fila], $texto);
            $hoja->mergeCells([1, $fila, 2, $fila]);
            $hoja->getStyle([1, $fila])->getAlignment()->setWrapText(true);
            $hoja->getStyle([1, $fila])->getFont()->getColor()->setARGB('FF475569');
            $hoja->getRowDimension($fila)->setRowHeight(30);
            ++$fila;
        };

        $titulo('Cómo se usa esta plantilla');
        $nota('Sólo «Nombres» es imprescindible. TODO lo demás es opcional: si no lo usas, borra la columna entera. '
            .'Las columnas se buscan por su nombre, no por su posición, así que también se pueden reordenar.');
        $nota('Sirve igual para un expediente de dos personas con un pasaporte que para un padrón de colegio de 133 '
            .'con dos documentos y cinco agrupaciones. La segunda fila de ejemplo es justo ese caso mínimo.');
        ++$fila;

        $titulo('Columnas de la persona');
        $nota('⚠️ Los valores de Nacionalidad, Sexo y Rol son ESTRICTOS: hay desplegable en la '
            .'columna y la hoja «Tablas» los enumera. Un valor que no esté en la lista detiene la '
            .'carga con su nombre, en vez de entrar como algo que nadie pidió.');
        foreach (PadronFormato::columnasFijas() as $columna) {
            $linea($columna['columna'].($columna['obligatoria'] ? ' *' : ''), $columna['ayuda']);
        }
        ++$fila;

        $titulo('Documentos');
        $nota('Una columna por tipo con el número, y otra «Venc. …» con su fecha. Una persona puede llevar varios: '
            .'lo normal en un viaje internacional es DNI y pasaporte, con vencimientos distintos.');
        $nota('⚠️ Sin fecha de vencimiento el documento queda como SIN COMPROBAR, que no es lo mismo que vigente. '
            .'Un listado que cuente lo desconocido como bueno es exactamente lo que deja pasar un documento caducado.');
        foreach (PadronFormato::columnasDeDocumento() as $doc) {
            $linea($doc['columna'], 'Número. Su fecha va en «'.$doc['vencimiento'].'» (DD/MM/AAAA).');
        }
        ++$fila;

        $titulo('Agrupaciones');
        $nota('Toda columna que empieza por «#» es una agrupación. NO anidan: una persona está a la vez en su salón, '
            .'su grupo, su habitación y su reserva aérea. Escribe el valor tal cual: B, 5, HA13, JA2CWN.');
        $nota('Los grupos se crean solos al importar, y volver a subir el archivo corregido no los duplica. '
            .'Para quitar una agrupación, borra su columna.');
        foreach (PadronFormato::columnasDeEje() as $eje) {
            // Los ejemplos viven en el enum: al añadir un eje, su `match` obliga a escribirlos.
            $linea($eje['columna'], 'Ejemplos: '.$eje['tipo']->ejemplos());
        }
        ++$fila;

        $titulo('Qué lleva cada participante');
        $nota('Toda columna que empieza por «+» es un servicio del viaje, y se marca SÍ o NO por persona. '
            .'Estas columnas son LAS DE TU VIAJE: cámbialas, quita las que no uses y añade las que falten.');
        $nota('⚠️ Vacío se lee como NO. En un padrón de 133 filas una celda en blanco es «no me consta», y '
            .'apuntar a alguien a un servicio por descuido cuesta dinero.');
        $nota('Con esto cada participante ve en su panel qué le incluye a ÉL, y la orden de servicio de cada '
            .'proveedor sale con la lista de quién va. No se usa para calcular precios.');
        foreach (PadronFormato::SERVICIOS_DE_EJEMPLO as $servicio) {
            $linea(PadronFormato::MARCA_SERVICIO.$servicio, 'SÍ o NO. Ejemplo del padrón real — cámbialo por el tuyo.');
        }

        $hoja->getStyle([1, 1, 2, $fila])->getBorders()->getInside()->setBorderStyle(Border::BORDER_HAIR);
        $hoja->getStyle([1, 1, 2, $fila])->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::GRIS);
    }
}
