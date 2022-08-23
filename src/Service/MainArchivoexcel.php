<?php

namespace App\Service;

use Doctrine\Persistence\ObjectRepository;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\HttpFoundation\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;


class MainArchivoexcel implements ContainerAwareInterface
{

    use ContainerAwareTrait;

    protected array $tipoWriter = ['xlsx' => 'Xlsx', 'xls' => 'Xls', 'csv' => 'Csv'];

    protected MainVariableproceso $variableproceso;

    private string $archivoBasePath = '';
    private MainArchivoexcelFactory $archivoexcelFactory;
    private Spreadsheet $archivo;
    private Worksheet $hoja;

//reader
    private array $setTablaSpecs;
    private array $setColumnaSpecs;
    private array $validCols;
    private bool $parsed = false;
    private bool $descartarBlanco = false;
    private bool $trimEspacios = false ;
    private array $tablaSpecs;
    private array $columnaSpecs;
    private array $existentesRaw;
    private array $existentesIndizados; //indizados por la llave
    private array $existentesIndizadosMulti; //indizados por la llave
    private array $existentesIndizadosKp; //indizados valores incluyen las llaves
    private array $existentesIndizadosMultiKp; //indizados valores incluyen las llaves
    private array $existentesCustomRaw;
    private array $existentesCustomIndizados;
    private array $existentesCustomIndizadosMulti;
    private array $existentesDescartados;
    private array $camposCustom;
    private int $skipRows = 0;

//writer
    private int $filaBase = 1;
    private string $nombre;
    private string $tipo;
    private bool $removeEnclosure;

    //llamado por inyeccion de componentes
    public function setVariableproceso(MainVariableproceso $variableproceso): void
    {
        $this->variableproceso = $variableproceso;
    }

    //llamado por inyeccion de componentes
    public function setArchivoexcelFactory(MainArchivoexcelFactory $archivoexcelFactory): void
    {
        $this->archivoexcelFactory = $archivoexcelFactory;
    }

    public function getArchivoBasePath(): string
    {
        return $this->archivoBasePath;
    }

    private function getHoja(): Worksheet
    {
        return $this->hoja;
    }
    
    public function setSkipRows(int $rows): self
    {
        $this->skipRows = $rows;

        return $this;
    }

    public function setArchivoBasePath(ObjectRepository $repositorio, int $id, string $funcionArchivo): self
    {
        if(empty($repositorio) || empty($id) || empty($funcionArchivo)) {
            $this->variableproceso->setMensajes('Los parametros del archivo base no son válidos.', 'error');
            return $this;
        }
        $archivoAlmacenado = $repositorio->find($id);
        if(empty($archivoAlmacenado) || $archivoAlmacenado->getOperacion() != $funcionArchivo || !is_object($archivoAlmacenado)) {
            $this->variableproceso->setMensajes('El archivo no existe en la base de datos o es inválido.', 'error');
            return $this;
        }
        $fs = new Filesystem();

        if(!$fs->exists($archivoAlmacenado->getInternalFullPath())) {
            $this->variableproceso->setMensajes('El archivo no existe en la ruta.', 'error');
            return $this;
        }
        $this->archivoBasePath = $archivoAlmacenado->getInternalFullPath();
        return $this;
    }

    /**
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     */
    public function setArchivo(): self
    {
        if(!empty($this->getArchivoBasePath())) {
            $this->archivo = $this->archivoexcelFactory->createPHPExcelObject($this->getArchivoBasePath());
        }else{
            $this->archivo = $this->archivoexcelFactory->createPHPExcelObject();
        }
        $this->archivo->getProperties()->setCreator("OpenPeru")
            ->setTitle("Documento Generado")
            ->setDescription("Documento generado para descargar");

        $this->hoja = $this->archivo->setActiveSheetIndex(0);

        return $this;
        //$total_sheets=$this->archivo->getSheetCount();
        //$allSheetName=$this->archivo->getSheetNames();

    }

    /**
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     */
    public function setHoja(int $hojaIndex): self
    {
        $cantidad = $this->archivo->getSheetCount();

        //si no existe la hoja del indice creamos las hojas necesarias
        if($this->archivo->getSheetCount() < $hojaIndex) {
            $diferencia = $hojaIndex - $this->archivo->getSheetCount();
            for ($x = 0; $x < $diferencia; $x++) {
                $numeroHoja = $cantidad + 1 + $x;
                $this->archivo->createSheet();
            }
        }
        $this->hoja = $this->archivo->setActiveSheetIndex($hojaIndex - 1);

        return $this;
        //$total_sheets=$this->archivo->getSheetCount();
        //$allSheetName=$this->archivo->getSheetNames();
    }


    public function setParametrosReader(array $setTablaSpecs, array $setColumnaSpecs): self
    {
        $this->setTablaSpecs = $setTablaSpecs;
        $this->setColumnaSpecs = $setColumnaSpecs;
        if(empty($setTablaSpecs['tipo'])) {
            $setTablaSpecs['tipo'] = 'S';
        }
        if(!empty($setTablaSpecs)) {
            $this->tablaSpecs = $setTablaSpecs;
        }else{
            $this->tablaSpecs = [];
        }
        if(!empty($setColumnaSpecs)) {
            foreach($setColumnaSpecs as $columna):
                if(isset($columna['nombre'])) {
                    $this->validCols[] = $columna['nombre'];
                    if(preg_match("/-/i", $columna['nombre'])) {
                        $nombres = explode('-', $columna['nombre']);
                    }else{
                        $nombres = array($columna['nombre']);
                    }
                    unset($columna['nombre']);
                    foreach($nombres as $nombre):
                        $this->columnaSpecs[$nombre] = $columna;
                        $this->columnaSpecs[$nombre]['nombre'] = $nombre;
                        $this->tablaSpecs['columnas'][] = $nombre;
                        if(!isset($columna['proceso']) || (isset($columna['proceso']) && $columna['proceso'] == 'si')) {
                            $this->tablaSpecs['columnasProceso'][] = $nombre;
                        }
                        if(isset($columna['llave']) && $columna['llave'] == 'si') {
                            $this->tablaSpecs['llaves'][] = $nombre;
                        }
                    endforeach;

                }else{
                    $this->validCols[] = 'noProcess';
                }
            endforeach;
        }else{
            $this->columnaSpecs = [];
            $this->validCols = [];
        }
        return $this;
    }

    public function parseExcel(): bool
    {
        if(empty($this->archivo)) {
            $this->variableproceso->setMensajes('El archivo no pudo ser puesto en memoria.', 'error');
            return false;
        }

        if($this->isParsed()) {
            $this->variableproceso->setMensajes('El archivo ya fue procesado anteriormente.', 'info');
            return true;
        }
        $this->parsed = true;

        $highestRow = $this->getHoja()->getHighestRow();
        $highestColumn = $this->getHoja()->getHighestColumn();
        $highestColumnIndex = $this->archivoexcelFactory->columnIndexFromString($highestColumn);
        $specRow = false;
        $specRowType = '';
        $existentesRaw = array();
        $existentesIndizados = array();
        $existentesIndizadosMulti = array();
        $existentesIndizadosKp = array();
        $existentesIndizadosMultiKp = array();
        $fila = 0;

        $startRow = $this->skipRows + 1;

        //recorre filas
        for ($row = $startRow; $row <= $highestRow; ++$row) {
            $procesandoNombre = false;
            //recorre columnas
            for ($col = 0; $col < $highestColumnIndex; ++$col) {

                //lee valor
                $value = $this->getHoja()->getCellByColumnAndRow($col, $row)->getValue();

                //detecta filas de "especificacion"
                if($col == 0 && str_starts_with($value, "&") && substr($value, 3, 1) == "&") {
                    $specRow = true;
                    if(str_starts_with($value, "&ta&")) {
                        $specRowType = 'T';
                        $value = substr($value, 4);
                    }elseif(str_starts_with($value, "&co&")) {
                        $specRowType = 'C';
                        $value = substr($value, 4);
                    }else{
                        $specRowType = '';
                    }

                }elseif($col == 0 && !str_starts_with($value, "&")) {
                    $specRow = false;
                    $specRowType = '';
                }

                //si es fila de especificaciones y no se han pasado las especificaciones como variable
                //noProcess como nombre descarta la columna
                //guion en el medio del nombre lee dos variables separadas por guion
                //proceso='no' es para que no se utilice en la consulta de la base de datos

                if($specRow === true) {
                    if($specRowType == 'C' && is_null($this->setColumnaSpecs)) {
                        $valorArray = explode(':', $value);
                        if(isset($valorArray[1])) {
                            if($valorArray[0] == 'nombre') {
                                $this->validCols[] = $valorArray[1];
                                if(preg_match("/-/i", $valorArray[1])) {
                                    $nombres = explode('-', $valorArray[1]);
                                }else{
                                    $nombres = array($valorArray[1]);
                                }
                                foreach($nombres as $nombre):
                                    $this->columnaSpecs[$nombre]['nombre'] = $nombre;
                                    $this->tablaSpecs['columnas'][] = $nombre;
                                    $this->tablaSpecs['columnasProceso'][] = $nombre;
                                endforeach;
                                $procesandoNombre = true;
                            }elseif($procesandoNombre === true) {
                                $this->validCols[] = 'noProcess';
                            }elseif(!empty($this->validCols) && isset($this->validCols[$col]) && $this->validCols[$col] != 'noProcess') {
                                if(preg_match("/-/i", $this->validCols[$col])) {
                                    $nombres = explode('-', $this->validCols[$col]);
                                }else{
                                    $nombres = array($this->validCols[$col]);
                                }
                                foreach($nombres as $nombre):
                                    $this->columnaSpecs[$nombre][$valorArray[0]] = $valorArray[1];
                                endforeach;
                            }
                            if($valorArray[0] == 'llave' && $valorArray[1] == 'si' && isset($this->validCols[$col]) && $this->validCols[$col] != 'noProcess') {
                                if(preg_match("/-/i", $this->validCols[$col])) {
                                    $nombres = explode('-', $this->validCols[$col]);
                                }else{
                                    $nombres = array($this->validCols[$col]);
                                }
                                foreach($nombres as $nombre):
                                    $this->tablaSpecs['llaves'][] = $this->columnaSpecs[$nombre]['nombre'];
                                endforeach;
                            }
                            if($valorArray[0] == 'proceso' && $valorArray[1] == 'no' && isset($this->validCols[$col]) && $this->validCols[$col] != 'noProcess') {
                                if(preg_match("/-/i", $this->validCols[$col])) {
                                    $nombres = explode('-', $this->validCols[$col]);
                                }else{
                                    $nombres = array($this->validCols[$col]);
                                }
                                foreach($nombres as $nombre):
                                    $encontrado = array_search($this->columnaSpecs[$nombre]['nombre'], $this->tablaSpecs['columnasProceso'], true);
                                    if($encontrado !== false) {
                                        unset($this->tablaSpecs['columnasProceso'][$encontrado]);
                                    }
                                endforeach;
                            }
                        }
                    }
                    if($specRowType == 'T' && is_null($this->setTablaSpecs)) {
                        $valorArray = explode(':', $value);
                        if(isset($valorArray[1])) {
                            $this->tablaSpecs[$valorArray[0]] = $valorArray[1];
                        }
                    }
                }else{
                    if(!empty($this->validCols) && isset($this->validCols[$col]) && $this->validCols[$col] != 'noProcess') {
                        $columnName = $this->validCols[$col];
                        if(preg_match("/-/i", $this->validCols[$col])) {
                            $value = explode('-', $value);
                            $columnName = explode('-', $columnName);
                        }else{
                            $value = array($value);
                            $columnName = array($columnName);
                        }
                        foreach($value as $key => $parteValor):

                            if(trim(str_replace(chr(194) . chr(160), "", $parteValor)) != '' || !$this->isDescartarBlanco()) {

                                if($this->isTrimEspacios()) {
                                    $parteValor = trim(str_replace(chr(194) . chr(160), "", $parteValor));
                                }

                                if(isset($this->columnaSpecs[$columnName[$key]]['tipo'])){
                                    if($this->columnaSpecs[$columnName[$key]]['tipo'] == 'file' && $key == 1) {
                                        $parteValor = str_pad($parteValor, 10, 0, STR_PAD_LEFT);
                                    }elseif($this->columnaSpecs[$columnName[$key]]['tipo'] == 'exceldate') {
                                        $parteValor = $this->variableproceso->exceldate($parteValor);
                                    }elseif($this->columnaSpecs[$columnName[$key]]['tipo'] == 'exceltime') {
                                        $parteValor = $this->variableproceso->exceltime($parteValor);
                                    }
                                }
                                $existentesRaw[$fila][$this->columnaSpecs[$columnName[$key]]['nombre']] = str_replace(chr(194) . chr(160), "", $parteValor);
                            }

                        endforeach;
                    }else{
                        if(trim(str_replace(chr(194) . chr(160), "", $value)) != '' || !$this->isDescartarBlanco()) {
                            if($this->isTrimEspacios()) {
                                $value = trim(str_replace(chr(194) . chr(160), "", $value));
                            }
                            $existentesDescartados[$fila][] = str_replace(chr(194) . chr(160), "", $value);
                        }
                    }

                    if(isset($existentesRaw[$fila]) && count($existentesRaw[$fila]) > 0 ){
                        $existentesRaw[$fila]['excelRowNumber'] = $fila + $startRow;
                    }
                }

            }
            if(!empty($this->tablaSpecs['llaves'])) {
                foreach($this->tablaSpecs['llaves'] as $llave):
                    if(empty($existentesRaw[$fila][$llave])) {
                        unset($existentesRaw[$fila]);
                        break;
                    }
                endforeach;
            }
            $fila++;

        }

        if(empty($existentesRaw)) {
            $this->variableproceso->setMensajes('La lectura del archivo no obtuvo resultados.', 'error');
            return false;
        }

        foreach($existentesRaw as $nroLinea => $valor):
            //primero obtenemos los custom
            if(!empty($this->getCamposCustom())) {

                foreach($this->getCamposCustom() as $llaveCustom):

                    if(isset($valor[$llaveCustom])) {

                        $existentesCustomRaw[$nroLinea][$llaveCustom] = $valor[$llaveCustom];
                    }
                endforeach;
            }

            if(!empty($this->tablaSpecs['llaves'])) {
                $indice = array();
                $llavesSave = array();

                foreach($this->tablaSpecs['llaves'] as $llave):
                    $indice[] = $valor[$llave];
                    $llavesSave[$llave] = $valor[$llave];
                    unset($valor[$llave]);
                endforeach;
                $existentesIndizados[implode('|', $indice)] = $valor;
                $existentesIndizadosMulti[implode('|', $indice)][] = $valor;
                $existentesIndizadosKp[implode('|', $indice)] = array_merge($llavesSave, $valor);
                $existentesIndizadosMultiKp[implode('|', $indice)][] = array_merge($llavesSave, $valor);
                if(!empty($this->getCamposCustom())) {
                    $i = 0;
                    foreach($this->getCamposCustom() as $llaveCustom):
                        if(isset($valor[$llaveCustom])) {
                            $existentesCustomIndizadosMulti[implode('|', $indice)][$i][$llaveCustom] = $valor[$llaveCustom];
                            $existentesCustomIndizados[implode('|', $indice)][$llaveCustom] = $valor[$llaveCustom];

                        }
                        $i++;
                    endforeach;
                }
            }else{
                $noGroup = true;
            }

        endforeach;

        $this->setExistentesRaw($existentesRaw);
        if(!empty($existentesCustomRaw)) {
            $this->setExistentesCustomRaw($existentesCustomRaw);
        }
        if(!empty($existentesDescartados)) {
            $this->setExistentesDescartados($existentesDescartados);
        }

        if(!isset($noGroup) || $noGroup === true){
            $this->setExistentesIndizados($existentesIndizados);
            $this->setExistentesIndizadosMulti($existentesIndizadosMulti);
            $this->setExistentesIndizadosKp($existentesIndizadosKp);
            $this->setExistentesIndizadosMultiKp($existentesIndizadosMultiKp);
            if(!empty($existentesCustomIndizados)) {
                $this->setExistentesCustomIndizados($existentesCustomIndizados);
            }

            if(!empty($existentesCustomIndizadosMulti)) {
                $this->setExistentesCustomIndizadosMulti($existentesCustomIndizadosMulti);
            }
        }

        return true;
    }

    public function isParsed(): bool
    {
        return $this->parsed;
    }


    public function isDescartarBlanco(): bool
    {
        return $this->descartarBlanco;
    }

    public function setDescartarBlanco(bool $descartarBlanco): self
    {
        $this->descartarBlanco = $descartarBlanco;
        return $this;
    }

    public function setRemoveEnclosure(bool $removeEnclosure): self
    {
        $this->removeEnclosure = $removeEnclosure;
        return $this;
    }

    public function isRemoveEnclosure(): bool
    {
        return $this->removeEnclosure;
    }

    public function isTrimEspacios(): bool
    {
        return $this->trimEspacios;
    }

    public function setTrimEspacios(bool $trimEspacios): self
    {
        $this->trimEspacios = $trimEspacios;
        return $this;
    }

    public function getExistentesRaw(): array
    {
        return $this->existentesRaw;
    }

    public function getExistentesIndizados(): array
    {
        return $this->existentesIndizados;
    }

    private function setExistentesIndizados(array $existentesIndizados): self
    {
        $this->existentesIndizados = $existentesIndizados;
        return $this;
    }

    public function getExistentesIndizadosMulti(): array
    {
        return $this->existentesIndizadosMulti;
    }

    private function setExistentesIndizadosMulti(array $existentesIndizadosMulti): self
    {
        $this->existentesIndizadosMulti = $existentesIndizadosMulti;
        return $this;
    }

    public function getExistentesIndizadosKp(): array
    {
        return $this->existentesIndizadosKp;
    }

    private function setExistentesIndizadosKp(array $existentesIndizadosKp): self
    {
        $this->existentesIndizadosKp = $existentesIndizadosKp;
        return $this;
    }

    public function getExistentesIndizadosMultiKp(): array
    {
        return $this->existentesIndizadosMultiKp;
    }

    private function setExistentesIndizadosMultiKp(array $existentesIndizadosMultiKp): self
    {
        $this->existentesIndizadosMultiKp = $existentesIndizadosMultiKp;
        return $this;
    }

    private function setExistentesRaw(array $existentesRaw): self
    {
        $this->existentesRaw = $existentesRaw;
        return $this;
    }

    public function setCamposCustom(array $campos): self
    {
        $this->camposCustom = $campos;
        return $this;
    }

    public function getCamposCustom(): array
    {
        return $this->camposCustom;
    }

    public function getExistentesCustomIndizados(): array
    {
        return $this->existentesCustomIndizados;
    }

    private function setExistentesCustomIndizados(array $existentesCustomIndizados): self
    {
        $this->existentesCustomIndizados = $existentesCustomIndizados;
        return $this;
    }

    public function getExistentesCustomIndizadosMulti(): array
    {
        return $this->existentesCustomIndizadosMulti;
    }

    private function setExistentesCustomIndizadosMulti(array $existentesCustomIndizadosMulti): self
    {
        $this->existentesCustomIndizadosMulti = $existentesCustomIndizadosMulti;
        return $this;
    }

    public function getExistentesCustomRaw(): array
    {
        return $this->existentesCustomRaw;
    }

    private function setExistentesCustomRaw(array $existentesCustomRaw): self
    {
        $this->existentesCustomRaw = $existentesCustomRaw;
        return $this;
    }

    public function getExistentesDescartados(): array
    {
        return $this->existentesDescartados;
    }

    public function setExistentesDescartados(array $existentesDescartados): self
    {
        $this->existentesDescartados = $existentesDescartados;
        return $this;
    }


    public function setParametrosWriter(array $contenido, array $encabezado = [], string $nombre = 'archivoGenerado', string $tipo = 'xlsx', bool $removeEnclosure = false): self
    {
        $this->setNombre($nombre);

        $this->setRemoveEnclosure($removeEnclosure);

        if(!empty($encabezado)) {
            $this->setFila($encabezado, 'A1');
            $this->filaBase = 2;
        }
        $this->setTabla($contenido, 'A' . $this->getFilaBase());

        $this->setTipo($tipo);
        return $this;
    }

    public function setFila(array $fila, string $posicion): self
    {
        if(empty($this->getHoja()) || empty($fila) || !is_array($fila) || $this->variableproceso->is_multi_array($fila) || empty($posicion)) {
            $this->variableproceso->setMensajes('El formato de fila no es correcto.', 'error');
            return $this;
        }
        $posicionX = preg_replace("/[0-9]/", '', $posicion);
        $posicionY = preg_replace("/[^0-9]/", '', $posicion);
        $posicionXNumerico = $this->archivoexcelFactory->columnIndexFromString($posicionX);
        foreach($fila as $key => $celda):
            if(!is_numeric($key)) {
                $this->variableproceso->setMensajes('El índice del array debe ser numérico', 'error');
                return $this;
            }
            $columna = $this->archivoexcelFactory->stringFromColumnIndex((int)$key + $posicionXNumerico);

            $this->getHoja()->setCellValue($columna . $posicionY, $celda);
        endforeach;
        return $this;
    }

    public function getFilaBase(): int
    {
        return $this->filaBase;
    }

    public function setColumna(array $columna, string $posicion): self
    {
        if(empty($this->getHoja()) || empty($posicion)) {
            $this->variableproceso->setMensajes('El formato de columna no es correcto.', 'error');
            return $this;
        }
        $columnArray = array_chunk($columna, 1);
        $this->setTabla($columnArray, $posicion);
        return $this;
    }



    public function setTabla(array $tabla, string $posicion): self
    {
        if(empty($this->getHoja()) || empty($tabla) || !$this->variableproceso ->is_multi_array($tabla) || empty($posicion)) {
            $this->variableproceso->setMensajes('El formato de tabla no es correcto.', 'error');
            return $this;
        }
        $this->getHoja()->fromArray($tabla, NULL, $posicion);
        return $this;
    }

    public function setTipo(string $tipo): self
    {
        $this->tipo = $tipo;
        return $this;
    }

    public function getTipo(): string
    {
        return $this->tipo;
    }

    public function setNombre(string $nombre): self
    {
        $this->nombre = $nombre;
        return $this;
    }

    public function getNombre(): string
    {
        return $this->nombre;
    }

    public function setFormatoColumna(array $formatoColumna): self
    {

        if(empty($this->getHoja()) || empty($formatoColumna) || !$this->variableproceso->is_multi_array($formatoColumna)) {
            $this->variableproceso->setMensajes('El formato de columna no es correcto.', 'error');
            return $this;
        }

        $highestRow = $this->getHoja()->getHighestDataRow();
        $highestColumn = $this->getHoja()->getHighestDataColumn();
        foreach($formatoColumna as $formato => $columnas):
            foreach($columnas as $columna):
                if(str_contains($columna, ':')) {
                    $columna = explode(':', $columna, 2);
                    if(is_numeric($columna[0]) && (is_numeric($columna[1]) || empty($columna[1]))) {
                        if(empty($columna[1])) {
                            $columna[1] = $this->archivoexcelFactory
                                ->columnIndexFromString($highestColumn);
                        }
                        foreach(range($columna[0], $columna[1]) as $columnaProceso) {
                            $columnaString = $this->archivoexcelFactory->stringFromColumnIndex($columnaProceso);
                            $this->getHoja()
                                ->getStyle($columna . $this->getFilaBase() . ':' . $columna . $highestRow)
                                ->getNumberFormat()
                                ->setFormatCode($columnaString);
                        }
                    }
                }else{
                    if(is_numeric($columna)) {
                        $columna = $this->archivoexcelFactory
                            ->stringFromColumnIndex($columna);
                    }
                    $this->getHoja()
                        ->getStyle($columna . $this->getFilaBase() . ':' . $columna . $highestRow)
                        ->getNumberFormat()
                        ->setFormatCode($formato);
                }
            endforeach;
        endforeach;

        return $this;
    }

    public function setAnchoColumna(array $anchoColumna): self
    {
        if(empty($this->getHoja()) || empty($anchoColumna) || !is_array($anchoColumna)) {
            $this->variableproceso->setMensajes('El ancho no tiene el formato correcto.', 'error');
            return $this;
        }

        foreach($anchoColumna as $columna => $ancho):
            if(str_contains($columna, ':')) {
                $columna = explode(':', $columna, 2);

                if(is_numeric($columna[0]) && (is_numeric($columna[1]) || empty($columna[1]))) {
                    if(empty($columna[1])) {
                        $columna[1] = $this->archivoexcelFactory->columnIndexFromString($this->getHoja()->getHighestDataColumn());
                    }
                    foreach(range($columna[0], $columna[1]) as $columnaProceso) {
                        $columnaString = $this->archivoexcelFactory->stringFromColumnIndex($columnaProceso);
                        if(is_numeric($ancho)) {
                            $this->getHoja()->getColumnDimension($columnaString)->setWidth($ancho);
                        }elseif($ancho == 'auto') {
                            $this->getHoja()->getColumnDimension($columnaString)->setAutoSize(true);
                        }
                    }
                }
            }else{
                if(is_numeric($columna)) {
                    $columna = $this->archivoexcelFactory->stringFromColumnIndex($columna);
                }
                if(is_numeric($ancho)) {
                    $this->getHoja()->getColumnDimension($columna)->setWidth($ancho);
                }elseif($ancho == 'auto') {
                    $this->getHoja()->getColumnDimension($columna)->setAutoSize(true);
                }
            }
        endforeach;

        return $this;
    }

    public function setCeldas(array $celdas, string $tipo = 'texto'): self
    {
        if(empty($this->getHoja()) || empty($celdas)) {
            $this->variableproceso->setMensajes('Las celdas no tienen el formato correcto.', 'error');
            return $this;
        }

        if($tipo == 'texto') {
            foreach($celdas as $celda => $valor):
                $this->getHoja()->setCellValueExplicit($celda, $valor, 's');
            endforeach;
        }
        return $this;
    }

    public function getResponse(): Response
    {
        if(empty($this->getTipo())) {
            $this->variableproceso->setMensajes('El tipo esta vacio.', 'error');
        }

        $writer = $this->archivoexcelFactory->createWriter($this->archivo, $this->tipoWriter[$this->getTipo()], $this->isRemoveEnclosure());

        $response = $this->archivoexcelFactory->createStreamedResponse($writer);
        $response->headers->set('Content-Type', 'text/vnd.ms-excel; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment;filename=' . $this->variableproceso->sanitizeString($this->getNombre() . '.' . $this->getTipo()));
        $response->headers->set('Pragma', 'public');
        $response->headers->set('Cache-Control', 'max-age=1');
        return $response;
    }

    public function createFile(): string
    {
        if(empty($this->getTipo())) {
            $this->variableproceso->setMensajes('El tipo esta vacio.', 'error');
        }

        $writer = $this->archivoexcelFactory->createWriter($this->archivo, $this->tipoWriter[$this->getTipo()], $this->isRemoveEnclosure());

        $path = tempnam(sys_get_temp_dir(), $this->getTipo());
        $writer->save($path);
        return $path;
    }
}