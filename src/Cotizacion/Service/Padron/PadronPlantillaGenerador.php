<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Padron;

use App\Cotizacion\Enum\GrupoTipoEnum;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Enum\PasajeroTipoEnum;
use App\Entity\Maestro\MaestroPais;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
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

    /**
     * La plantilla en blanco, con dos filas de ejemplo.
     */
    public function generar(): string
    {
        return $this->construir(null);
    }

    /**
     * La misma plantilla, **ya rellena** con lo que hay cargado.
     *
     * Es la forma cómoda de completar un padrón a medias: se descarga lo que existe, se rellenan los
     * huecos —los vencimientos que faltan, los teléfonos— y se vuelve a subir.
     *
     * ⚠️ Trae la columna `Id`, y ahí está la gracia: al resubir, cada fila vuelve a SU persona
     * aunque le hayan cambiado el nombre y el documento a la vez. Sin ella habría que casar por
     * documento, y corregir un pasaporte duplicaría a esa persona.
     */
    public function exportar(CotizacionFile $file): string
    {
        return $this->construir($file);
    }

    private function construir(?CotizacionFile $file): string
    {
        $libro = new Spreadsheet();
        $this->hojaPasajeros($libro, $file);
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

    private function hojaPasajeros(Spreadsheet $libro, ?CotizacionFile $file = null): void
    {
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Pasajeros');

        $cabeceras = $file === null
            ? PadronFormato::cabeceras()
            : $this->cabecerasDelExpediente($file);

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

        $porNombre = array_flip($cabeceras);

        if ($file !== null) {
            $this->volcarPasajeros($hoja, $file, $porNombre, count($cabeceras));
            $this->desplegables($hoja, $porNombre);
            $hoja->freezePane('A2');

            return;
        }

        // ── Dos ejemplos: el completo y el mínimo ───────────────────────────
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

        $this->desplegables($hoja, $porNombre);

        $hoja->freezePane('A2');
    }


    /**
     * Las cabeceras de UN expediente: sólo lo que usa de verdad.
     *
     * ⚠️ No se parte de la plantilla en blanco. Ésta trae servicios de ejemplo —`+Coco Bongo`— y el
     * expediente tiene los suyos ya normalizados —`+COCO BONGO`—: juntarlos daba **dos columnas
     * para lo mismo**, y al reimportar ganaba una por casualidad de orden.
     *
     * ⚠️ Y de cada eje se sacan **tantas columnas como grupos tenga la persona que más tenga**. Con
     * una sola, alguien con dos reservas aéreas —la nacional y la internacional, que es el caso
     * normal— perdía la segunda al exportar, y al resubir el archivo se la quitaba de verdad.
     *
     * @return list<string>
     */
    private function cabecerasDelExpediente(CotizacionFile $file): array
    {
        $cabeceras = [PadronFormato::COL_ID, ...PadronFormato::cabecerasBase(), PadronFormato::COL_TIPO];

        /** @var array<string, int> $cuantosPorEje */
        $cuantosPorEje = [];
        foreach ($file->getFilepasajeros() as $pasajero) {
            $suyos = [];
            foreach ($pasajero->grupos() as $grupo) {
                if ($grupo->getTipo() !== null && $grupo->getTipo() !== GrupoTipoEnum::SERVICIO) {
                    $suyos[$grupo->getTipo()->value] = ($suyos[$grupo->getTipo()->value] ?? 0) + 1;
                }
            }
            foreach ($suyos as $eje => $cuantos) {
                $cuantosPorEje[$eje] = max($cuantosPorEje[$eje] ?? 0, $cuantos);
            }
        }

        $servicios = [];
        foreach ($file->getGrupos() as $grupo) {
            $tipo = $grupo->getTipo();
            if ($tipo === GrupoTipoEnum::SERVICIO) {
                $servicios[(string) $grupo->getClave()] = true;
            } elseif ($tipo !== null && !isset($cuantosPorEje[$tipo->value])) {
                // Un eje con grupos pero sin nadie dentro: se saca una columna igual, para poder
                // asignar gente sin volver a crearlos.
                $cuantosPorEje[$tipo->value] = 1;
            }
        }

        foreach ($cuantosPorEje as $eje => $cuantos) {
            $etiqueta = GrupoTipoEnum::from($eje)->label();
            for ($i = 0; $i < $cuantos; ++$i) {
                $cabeceras[] = PadronFormato::MARCA_EJE.$etiqueta;
            }
        }

        foreach (array_keys($servicios) as $servicio) {
            $cabeceras[] = PadronFormato::MARCA_SERVICIO.$servicio;
        }

        return $cabeceras;
    }

    /**
     * Los desplegables con validación, para TODA la columna.
     *
     * Es lo que hace que el formato pueda ser estricto sin castigar a nadie: si la hoja ofrece los
     * valores, exigirlos deja de ser una trampa. Antes se adivinaba «alumno» o «acompa», y eso
     * convertía un «Alumbo» mal escrito en un silencio.
     *
     * @param array<string, int> $porNombre
     */
    private function desplegables(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $hoja, array $porNombre): void
    {
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

    }

    /**
     * Vuelca los pasajeros que ya están cargados.
     *
     * @param array<string, int> $porNombre
     */
    private function volcarPasajeros(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $hoja,
        CotizacionFile $file,
        array $porNombre,
        int $totalColumnas,
    ): void {
        $texto = static function (\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $h, array $porNombre, int $fila, string $columna, ?string $valor): void {
            if ($valor === null || $valor === '' || !isset($porNombre[$columna])) {
                return;
            }
            $h->setCellValueExplicit([$porNombre[$columna] + 1, $fila], $valor, DataType::TYPE_STRING);
        };

        $n = 2;

        foreach ($file->getFilepasajeros() as $pasajero) {
            $texto($hoja, $porNombre, $n, PadronFormato::COL_ID, (string) $pasajero->getId());
            $texto($hoja, $porNombre, $n, PadronFormato::COL_NOMBRES, $pasajero->getNombre());
            $texto($hoja, $porNombre, $n, PadronFormato::COL_APELLIDOS, $pasajero->getApellido());
            $texto($hoja, $porNombre, $n, PadronFormato::COL_NACIONALIDAD, $pasajero->getPais()?->getId());
            $texto($hoja, $porNombre, $n, PadronFormato::COL_SEXO, $pasajero->getSexo()?->value);
            $texto($hoja, $porNombre, $n, PadronFormato::COL_NACIMIENTO, $pasajero->getFechanacimiento()?->format('d/m/Y'));
            $texto($hoja, $porNombre, $n, PadronFormato::COL_TIPO, $pasajero->getTipo()?->label());
            $texto($hoja, $porNombre, $n, PadronFormato::COL_TELEFONO, $pasajero->getTelefono());
            $texto($hoja, $porNombre, $n, PadronFormato::COL_OBSERVACIONES, $pasajero->getObservaciones());

            foreach ($pasajero->getIdentificaciones() as $identificacion) {
                $tipo = $identificacion->getTipo();
                if ($tipo === null) {
                    continue;
                }
                $etiqueta = PadronFormato::etiquetaDeDocumento($tipo);
                $texto($hoja, $porNombre, $n, $etiqueta, $identificacion->getNumero());
                $texto($hoja, $porNombre, $n, PadronFormato::PREFIJO_VENCIMIENTO.$etiqueta,
                    $identificacion->getVencimiento()?->format('d/m/Y'));
            }

            // ⚠️ Los servicios se vuelcan SÍ/NO explícito, no sólo los «SÍ». Dejar en blanco al que
            // no participa haría indistinguible «no va» de «nadie lo ha mirado», y el importador lee
            // el vacío como NO: al resubir se perdería la diferencia sin que nadie lo notase.
            foreach ($file->getGrupos() as $grupo) {
                if ($grupo->getTipo() !== GrupoTipoEnum::SERVICIO) {
                    continue;
                }
                $columna = PadronFormato::MARCA_SERVICIO.$grupo->getClave();
                $va = in_array($grupo, $pasajero->grupos(), true);
                $texto($hoja, $porNombre, $n, $columna, $va ? 'SI' : 'NO');
            }

            foreach ($pasajero->grupos() as $grupo) {
                if ($grupo->getTipo() === GrupoTipoEnum::SERVICIO) {
                    continue;
                }
                $columna = PadronFormato::MARCA_EJE.($grupo->getTipo()?->label() ?? '');

                // ⚠️ Varias columnas comparten cabecera —dos «#Reserva aérea», la nacional y la
                // internacional— así que no vale `array_flip`: colapsa las repetidas y la segunda
                // reserva se perdía. Se recorren TODAS las posiciones de esa cabecera y se usa la
                // primera libre de esta fila.
                foreach ($this->columnasDe($hoja, $columna) as $col) {
                    if ($hoja->getCell([$col, $n])->getValue() === null) {
                        $hoja->setCellValueExplicit([$col, $n], (string) $grupo->getClave(), DataType::TYPE_STRING);
                        break;
                    }
                }
            }

            ++$n;
        }

        // El `Id` en gris: se ve que es maquinaria y no un dato que rellenar.
        if (isset($porNombre[PadronFormato::COL_ID]) && $n > 2) {
            $hoja->getStyle([$porNombre[PadronFormato::COL_ID] + 1, 2, $porNombre[PadronFormato::COL_ID] + 1, $n - 1])
                ->getFont()->setSize(8)->getColor()->setARGB('FFCBD5E1');
        }

        unset($totalColumnas);
    }

    /**
     * Todas las columnas (1-indexadas) que llevan esa cabecera.
     *
     * @return list<int>
     */
    private function columnasDe(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $hoja, string $cabecera): array
    {
        $columnas = [];

        $ultima = Coordinate::columnIndexFromString($hoja->getHighestColumn());

        for ($col = 1; $col <= $ultima; ++$col) {
            if ((string) $hoja->getCell([$col, 1])->getValue() === $cabecera) {
                $columnas[] = $col;
            }
        }

        return $columnas;
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
                PasajeroTipoEnum::PARTICIPANTE => 'Ve sólo lo suyo. El caso normal: quien viaja.',
                PasajeroTipoEnum::ACOMPANANTE => 'Ve sólo lo suyo. Acompaña: un padre, una pareja, un directivo.',
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
