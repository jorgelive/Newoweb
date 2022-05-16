<?php

namespace App\Service;

use \Symfony\Component\Filesystem\Filesystem;
use \Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use \Symfony\Component\DependencyInjection\ContainerAwareInterface;
use \Symfony\Component\DependencyInjection\ContainerAwareTrait;

class MainArchivoexcel implements ContainerAwareInterface
{

    use ContainerAwareTrait;


    protected $variableproceso;

    private $archivoBase;
    private $proceso;
    private $archivo;
    private $hoja;

//reader
    private $setTablaSpecs;
    private $setColumnaSpecs;
    private $validCols;
    private $parsed;
    private $descartarBlanco;
    private $trimEspacios;
    private $tablaSpecs;
    private $columnaSpecs;
    private $existentesRaw;
    private $existentesIndizados; //indizados por la llave
    private $existentesIndizadosMulti; //indizados por la llave
    private $existentesIndizadosKp; //indizados valores incluyen las llaves
    private $existentesIndizadosMultiKp; //indizados valores incluyen las llaves
    private $existentesCustomRaw;
    private $existentesCustomIndizados;
    private $existentesCustomIndizadosMulti;
    private $existentesDescartados;
    private $camposCustom;
    private $skipRows;

//writer
    private $filaBase = 1;
    private $nombre;
    private $tipo;

    public function setVariableproceso(MainVariableproceso $variableproceso = null)
    {
        $this->variableproceso = $variableproceso;
    }

    public function getArchivoBase()
    {
        return $this->archivoBase;
    }

    private function getProceso()
    {
        return $this->proceso;
    }

    private function getHoja()
    {
        return $this->hoja;
    }
    
    public function setSkipRows($rows)
    {
        if (is_numeric($rows)){
            $this->skipRows = $rows;
        }
        return $this;
    }

    public function setArchivoBase($repositorio, $id, $funcionArchivo)
    {
        if (empty($repositorio) || empty($id) || empty($funcionArchivo)) {
            $this->variableproceso->setMensajes('Los parametros del archivo base no son válidos.', 'error');
            return $this;
        }
        $archivoAlmacenado = $repositorio->find($id);
        if (empty($archivoAlmacenado) || $archivoAlmacenado->getOperacion() != $funcionArchivo || !is_object($archivoAlmacenado)) {
            $this->variableproceso->setMensajes('El archivo no existe en la base de datos o es inválido.', 'error');
            return $this;
        }
        $fs = new Filesystem();

        if (!$fs->exists($archivoAlmacenado->getInternalFullPath())) {
            $this->variableproceso->setMensajes('El archivo no existe en la ruta.', 'error');
            return $this;
        }
        $this->archivoBase = $archivoAlmacenado;
        return $this;
    }

    public function setArchivo()
    {
        $this->proceso = $this->container->get('App\Service\MainExcelFactory');
        if (!empty($this->getArchivoBase())) {
            $this->archivo = $this->getProceso()->createPHPExcelObject($this->getArchivoBase()->getInternalFullPath());
        } else {
            $this->archivo = $this->getProceso()->createPHPExcelObject();
        }
        $this->archivo->getProperties()->setCreator("OpenPeru")
            ->setTitle("Documento Generado")
            ->setDescription("Documento generado para descargar");
        $this->hoja = $this->archivo->setActiveSheetIndex(0);
        return $this;
        //$total_sheets=$this->archivo->getSheetCount();
        //$allSheetName=$this->archivo->getSheetNames();
    }

    public function setHoja($hoja)
    {
        $cantidad = $this->archivo->getSheetCount();
        if ($this->archivo->getSheetCount() < $hoja) {
            $diferencia = $hoja - $this->archivo->getSheetCount();
            for ($x = 0; $x < $diferencia; $x++) {
                $numeroHoja = $cantidad + 1 + $x;
                $this->archivo->addSheet($this->getProceso()->createPHPExcelSheet($this->archivo, "Worksheet - " . $numeroHoja));
            }
        }
        $this->hoja = $this->archivo->setActiveSheetIndex($hoja - 1);
        return $this;
        //$total_sheets=$this->archivo->getSheetCount();
        //$allSheetName=$this->archivo->getSheetNames();
    }


    public function setParametrosReader($setTablaSpecs, $setColumnaSpecs)
    {
        $this->setTablaSpecs = $setTablaSpecs;
        $this->setColumnaSpecs = $setColumnaSpecs;
        if (empty($setTablaSpecs['tipo'])) {
            $setTablaSpecs['tipo'] = 'S';
        }
        if (!empty($setTablaSpecs)) {
            $this->tablaSpecs = $setTablaSpecs;
        } else {
            $this->tablaSpecs = array();
        }
        if (!empty($setColumnaSpecs)) {
            foreach ($setColumnaSpecs as $columna):
                if (isset($columna['nombre'])) {
                    $this->validCols[] = $columna['nombre'];
                    if (preg_match("/-/i", $columna['nombre'])) {
                        $nombres = explode('-', $columna['nombre']);
                    } else {
                        $nombres = array($columna['nombre']);
                    }
                    unset($columna['nombre']);
                    foreach ($nombres as $nombre):
                        $this->columnaSpecs[$nombre] = $columna;
                        $this->columnaSpecs[$nombre]['nombre'] = $nombre;
                        $this->tablaSpecs['columnas'][] = $nombre;
                        if (!isset($columna['proceso']) || (isset($columna['proceso']) && $columna['proceso'] == 'si')) {
                            $this->tablaSpecs['columnasProceso'][] = $nombre;
                        }
                        if (isset($columna['llave']) && $columna['llave'] == 'si') {
                            $this->tablaSpecs['llaves'][] = $nombre;
                        }
                    endforeach;

                } else {
                    $this->validCols[] = 'noProcess';
                }
            endforeach;
        } else {
            $this->columnaSpecs = array();
            $this->validCols = array();
        }
        return $this;
    }

    public function parseExcel()
    {

        if (empty($this->archivo)) {
            $this->variableproceso->setMensajes('El archivo no pudo ser puesto en memoria.', 'error');
            return false;
        }

        if ($this->parsed == 'si') {
            $this->variableproceso->setMensajes('El archivo ya fue procesado anteriormente.', 'info');
            return true;
        }
        $this->parsed = 'si';

        $highestRow = $this->getHoja()->getHighestRow();
        $highestColumn = $this->getHoja()->getHighestColumn();
        $highestColumnIndex = $this->getProceso()->columnIndexFromString($highestColumn);
        $specRow = false;
        $specRowType = '';
        $existentesRaw = array();
        $existentesIndizados = array();
        $existentesIndizadosMulti = array();
        $existentesIndizadosKp = array();
        $existentesIndizadosMultiKp = array();
        $fila = 0;
        $startRow = 1;

        //descartamos filas de acuerdo a las especificaciones
        if (is_numeric($this->skipRows)) {
            $startRow = $this->skipRows + 1;
        }

        //recorre filas
        for ($row = $startRow; $row <= $highestRow; ++$row) {
            $procesandoNombre = false;
            //recorre columnas
            for ($col = 0; $col < $highestColumnIndex; ++$col) {

                //lee valor
                $value = $this->getHoja()->getCellByColumnAndRow($col, $row)->getValue();

                //detecta lilas de especificacion
                if ($col == 0 && substr($value, 0, 1) == "&" && substr($value, 3, 1) == "&") {
                    $specRow = true;
                    if (substr($value, 0, 4) == "&ta&") {
                        $specRowType = 'T';
                        $value = substr($value, 4);
                    } elseif (substr($value, 0, 4) == "&co&") {
                        $specRowType = 'C';
                        $value = substr($value, 4);
                    } else {
                        $specRowType = '';
                    }

                } elseif ($col == 0 && substr($value, 0, 1) != "&") {
                    $specRow = false;
                    $specRowType = '';
                }

                //si es fila de especificaciones y no se han pasado las especificaciones como variable
                //noProcess como nombre descarta la columna
                //guion en el medio del nombre lee dos variables separadas por guion
                //proceso='no' es para que no se utilice en la condilta de la base de datos

                if ($specRow === true) {
                    if ($specRowType == 'C' && is_null($this->setColumnaSpecs)) {
                        $valorArray = explode(':', $value);
                        if (isset($valorArray[1])) {
                            if ($valorArray[0] == 'nombre') {
                                $this->validCols[] = $valorArray[1];
                                if (preg_match("/-/i", $valorArray[1])) {
                                    $nombres = explode('-', $valorArray[1]);
                                } else {
                                    $nombres = array($valorArray[1]);
                                }
                                foreach ($nombres as $nombre):
                                    $this->columnaSpecs[$nombre]['nombre'] = $nombre;
                                    $this->tablaSpecs['columnas'][] = $nombre;
                                    $this->tablaSpecs['columnasProceso'][] = $nombre;
                                endforeach;
                                $procesandoNombre = true;
                            } elseif ($procesandoNombre === true) {
                                $this->validCols[] = 'noProcess';
                            } elseif ($procesandoNombre !== true && !empty($this->validCols) && isset($this->validCols[$col]) && $this->validCols[$col] != 'noProcess') {
                                if (preg_match("/-/i", $this->validCols[$col])) {
                                    $nombres = explode('-', $this->validCols[$col]);
                                } else {
                                    $nombres = array($this->validCols[$col]);
                                }
                                foreach ($nombres as $nombre):
                                    $this->columnaSpecs[$nombre][$valorArray[0]] = $valorArray[1];
                                endforeach;
                            }
                            if ($valorArray[0] == 'llave' && $valorArray[1] == 'si' && isset($this->validCols[$col]) && $this->validCols[$col] != 'noProcess') {
                                if (preg_match("/-/i", $this->validCols[$col])) {
                                    $nombres = explode('-', $this->validCols[$col]);
                                } else {
                                    $nombres = array($this->validCols[$col]);
                                }
                                foreach ($nombres as $nombre):
                                    $this->tablaSpecs['llaves'][] = $this->columnaSpecs[$nombre]['nombre'];
                                endforeach;
                            }
                            if ($valorArray[0] == 'proceso' && $valorArray[1] == 'no' && isset($this->validCols[$col]) && $this->validCols[$col] != 'noProcess') {
                                if (preg_match("/-/i", $this->validCols[$col])) {
                                    $nombres = explode('-', $this->validCols[$col]);
                                } else {
                                    $nombres = array($this->validCols[$col]);
                                }
                                foreach ($nombres as $nombre):
                                    $encontrado = array_search($this->columnaSpecs[$nombre]['nombre'], $this->tablaSpecs['columnasProceso'], true);
                                    if ($encontrado !== false) {
                                        unset($this->tablaSpecs['columnasProceso'][$encontrado]);
                                    }
                                endforeach;
                            }
                        }
                    }
                    if ($specRowType == 'T' && is_null($this->setTablaSpecs)) {
                        $valorArray = explode(':', $value);
                        if (isset($valorArray[1])) {
                            $this->tablaSpecs[$valorArray[0]] = $valorArray[1];
                        }
                    }
                } else {
                    if (!empty($this->validCols) && isset($this->validCols[$col]) && $this->validCols[$col] != 'noProcess') {
                        $columnName = $this->validCols[$col];
                        if (preg_match("/-/i", $this->validCols[$col])) {
                            $value = explode('-', $value);
                            $columnName = explode('-', $columnName);
                        } else {
                            $value = array($value);
                            $columnName = array($columnName);
                        }
                        foreach ($value as $key => $parteValor):

                            if (trim(str_replace(chr(194) . chr(160), "", $parteValor)) != '' || empty($this->getDescartarBlanco())) {

                                if (!empty($this->getTrimEspacios())) {
                                    $parteValor = trim(str_replace(chr(194) . chr(160), "", $parteValor));
                                }

                                if (isset($this->columnaSpecs[$columnName[$key]]['tipo'])){
                                    if ($this->columnaSpecs[$columnName[$key]]['tipo'] == 'file' && $key == 1) {
                                        $parteValor = str_pad($parteValor, 10, 0, STR_PAD_LEFT);
                                    }elseif($this->columnaSpecs[$columnName[$key]]['tipo'] == 'exceldate') {
                                        $parteValor = $this->container->get('App\Service\MainVariableproceso')->exceldate($parteValor);
                                    }elseif($this->columnaSpecs[$columnName[$key]]['tipo'] == 'exceltime') {
                                        $parteValor = $this->container->get('App\Service\MainVariableproceso')->exceltime($parteValor);
                                    }
                                }
                                $existentesRaw[$fila][$this->columnaSpecs[$columnName[$key]]['nombre']] = str_replace(chr(194) . chr(160), "", $parteValor);
                            }

                        endforeach;
                    } else {
                        if (trim(str_replace(chr(194) . chr(160), "", $value)) != '' || empty($this->getDescartarBlanco())) {
                            if (!empty($this->getTrimEspacios())) {
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
            if (!empty($this->tablaSpecs['llaves'])) {
                foreach ($this->tablaSpecs['llaves'] as $llave):
                    if (empty($existentesRaw[$fila][$llave])) {
                        unset($existentesRaw[$fila]);
                        break;
                    }
                endforeach;
            }
            $fila++;

        }

        if (empty($existentesRaw)) {
            $this->variableproceso->setMensajes('La lectura del archivo no obtuvo resultados.', 'error');
            return false;
        }

        foreach ($existentesRaw as $nroLinea => $valor):
            //primero obtenemos los custom
            if (!empty($this->getCamposCustom())) {

                foreach ($this->getCamposCustom() as $llaveCustom):

                    if (isset($valor[$llaveCustom])) {

                        $existentesCustomRaw[$nroLinea][$llaveCustom] = $valor[$llaveCustom];
                    }
                endforeach;
            }

            if (!empty($this->tablaSpecs['llaves'])) {
                $indice = array();
                $llavesSave = array();

                foreach ($this->tablaSpecs['llaves'] as $llave):
                    $indice[] = $valor[$llave];
                    $llavesSave[$llave] = $valor[$llave];
                    unset($valor[$llave]);
                endforeach;
                $existentesIndizados[implode('|', $indice)] = $valor;
                $existentesIndizadosMulti[implode('|', $indice)][] = $valor;
                $existentesIndizadosKp[implode('|', $indice)] = array_merge($llavesSave, $valor);
                $existentesIndizadosMultiKp[implode('|', $indice)][] = array_merge($llavesSave, $valor);
                if (!empty($this->getCamposCustom())) {
                    $i = 0;
                    foreach ($this->getCamposCustom() as $llaveCustom):
                        if (isset($valor[$llaveCustom])) {
                            $existentesCustomIndizadosMulti[implode('|', $indice)][$i][$llaveCustom] = $valor[$llaveCustom];
                            $existentesCustomIndizados[implode('|', $indice)][$llaveCustom] = $valor[$llaveCustom];

                        }
                        $i++;
                    endforeach;
                }
            } else {
                $noGroup = true;
            }

        endforeach;

        $this->setExistentesRaw($existentesRaw);
        if (!empty($existentesCustomRaw)) {
            $this->setExistentesCustomRaw($existentesCustomRaw);
        }
        if (!empty($existentesDescartados)) {
            $this->setExistentesDescartados($existentesDescartados);
        }

        if(!isset($noGroup) || $noGroup === true){
            $this->setExistentesIndizados($existentesIndizados);
            $this->setExistentesIndizadosMulti($existentesIndizadosMulti);
            $this->setExistentesIndizadosKp($existentesIndizadosKp);
            $this->setExistentesIndizadosMultiKp($existentesIndizadosMultiKp);
            if (!empty($existentesCustomIndizados)) {
                $this->setExistentesCustomIndizados($existentesCustomIndizados);
            }

            if (!empty($existentesCustomIndizadosMulti)) {
                $this->setExistentesCustomIndizadosMulti($existentesCustomIndizadosMulti);
            }
        }

        return true;
    }

    public function getTablaSpecs()
    {
        return $this->tablaSpecs;
    }

    public function getColumnaSpecs()
    {
        return $this->columnaSpecs;
    }

    public function getDescartarBlanco()
    {
        return $this->descartarBlanco;
    }

    public function setDescartarBlanco($descartarBlanco)
    {
        $this->descartarBlanco = $descartarBlanco;
        return $this;
    }

    public function getTrimEspacios()
    {
        return $this->trimEspacios;
    }

    public function setTrimEspacios($trimEspacios)
    {
        $this->trimEspacios = $trimEspacios;
        return $this;
    }

    public function getExistentesRaw()
    {
        return $this->existentesRaw;
    }

    public function getExistentesIndizados()
    {
        return $this->existentesIndizados;
    }

    private function setExistentesIndizados($existentesIndizados)
    {
        $this->existentesIndizados = $existentesIndizados;
        return $this;
    }

    public function getExistentesIndizadosMulti()
    {
        return $this->existentesIndizadosMulti;
    }

    private function setExistentesIndizadosMulti($existentesIndizadosMulti)
    {
        $this->existentesIndizadosMulti = $existentesIndizadosMulti;
        return $this;
    }

    public function getExistentesIndizadosKp()
    {
        return $this->existentesIndizadosKp;
    }

    private function setExistentesIndizadosKp($existentesIndizadosKp)
    {
        $this->existentesIndizadosKp = $existentesIndizadosKp;
        return $this;
    }

    public function getExistentesIndizadosMultiKp()
    {
        return $this->existentesIndizadosMultiKp;
    }

    private function setExistentesIndizadosMultiKp($existentesIndizadosMultiKp)
    {
        $this->existentesIndizadosMultiKp = $existentesIndizadosMultiKp;
        return $this;
    }

    private function setExistentesRaw($existentesRaw)
    {
        $this->existentesRaw = $existentesRaw;
        return $this;
    }

    public function setCamposCustom($campos)
    {
        $this->camposCustom = $campos;
        return $this;
    }

    public function getCamposCustom()
    {
        return $this->camposCustom;
    }

    public function getExistentesCustomIndizados()
    {
        return $this->existentesCustomIndizados;
    }

    private function setExistentesCustomIndizados($existentesCustomIndizados)
    {
        $this->existentesCustomIndizados = $existentesCustomIndizados;
        return $this;
    }

    public function getExistentesCustomIndizadosMulti()
    {
        return $this->existentesCustomIndizadosMulti;
    }

    private function setExistentesCustomIndizadosMulti($existentesCustomIndizadosMulti)
    {
        $this->existentesCustomIndizadosMulti = $existentesCustomIndizadosMulti;
        return $this;
    }

    public function getExistentesCustomRaw()
    {
        return $this->existentesCustomRaw;
    }

    private function setExistentesCustomRaw($existentesCustomRaw)
    {
        $this->existentesCustomRaw = $existentesCustomRaw;
        return $this;
    }

    public function getExistentesDescartados()
    {
        return $this->existentesDescartados;
    }

    public function setExistentesDescartados($existentesDescartados)
    {
        $this->existentesDescartados = $existentesDescartados;
        return $this;
    }


    public function setParametrosWriter($nombre = 'archivoGenerado', $contenido = null, $encabezado = null, $tipo = 'xlsx')
    {
        $this->setNombre($nombre);
        if (is_array($encabezado)) {
            $this->setFila($encabezado, 'A1');
            $this->filaBase = 2;
        }
        if (is_array($contenido)) {
            $this->setTabla($contenido, 'A' . $this->getFilaBase());
        }

        $this->setTipo($tipo);
        return $this;
    }

    public function setFila($fila, $posicion)
    {
        if (empty($this->getHoja()) || empty($fila) || !is_array($fila) || $this->container->get('App\Service\MainVariableproceso')->is_multi_array($fila) || empty($posicion)) {
            $this->variableproceso->setMensajes('El formato de fila no es correcto.', 'error');
            return $this;
        }
        $posicionX = preg_replace("/[0-9]/", '', $posicion);
        $posicionY = preg_replace("/[^0-9]/", '', $posicion);
        $posicionXNumerico = $this->getProceso()->columnIndexFromString($posicionX);
        foreach ($fila as $key => $celda):
            $columna = $this->getProceso()->stringFromColumnIndex($key + $posicionXNumerico - 1);
            $this->getHoja()->setCellValue($columna . $posicionY, $celda);
        endforeach;
        return $this;

    }

    public function getFilaBase()
    {
        return $this->filaBase;
    }

    public function setColumna($columna, $posicion)
    {
        if (empty($this->getHoja()) || !is_array($columna) || empty($posicion)) {
            $this->variableproceso->setMensajes('El formato de columna no es correcto.', 'error');
            return $this;
        }
        $columnArray = array_chunk($columna, 1);
        $this->setTabla($columnArray, $posicion);
        return $this;
    }



    public function setTabla($tabla, $posicion)
    {
        if (empty($this->getHoja()) || empty($tabla) || !is_array($tabla) || !$this->container->get('App\Service\MainVariableproceso')->is_multi_array($tabla) || empty($posicion)) {
            $this->variableproceso->setMensajes('El formato de tabla no es correcto.', 'error');
            return $this;
        }
        $this->getHoja()->fromArray($tabla, NULL, $posicion);
        return $this;
    }

    public function setTipo($tipo)
    {
        $this->tipo = $tipo;
        return $this;
    }

    public function getTipo()
    {
        return $this->tipo;
    }

    public function setNombre($nombre)
    {
        $this->nombre = $nombre;
        return $this;
    }

    public function getNombre()
    {
        return $this->nombre;
    }

    public function setFormatoColumna($formatoColumna)
    {

        if (empty($this->getHoja()) || empty($formatoColumna) || !$this->container->get('App\Service\MainVariableproceso')->is_multi_array($formatoColumna)) {
            $this->variableproceso->setMensajes('El formato de columna no es correcto.', 'error');
            return $this;
        }
        $highestRow = $this->getHoja()->getHighestRow();
        foreach ($formatoColumna as $formato => $columnas):
            foreach ($columnas as $columna):
                if (strpos($columna, ':') !== false) {
                    $columna = explode(':', $columna, 2);
                    if (is_numeric($columna[0]) && (is_numeric($columna[1]) || empty($columna[1]))) {
                        if (empty($columna[1])) {
                            $columna[1] = $this->getProceso()->columnIndexFromString($highestRow);
                        }
                        foreach (range($columna[0], $columna[1]) as $columnaProceso) {
                            $columnaString = $this->getProceso()->stringFromColumnIndex($columnaProceso);
                            $this->getHoja()->getStyle($columna . $this->getFilaBase() . ':' . $columna . $highestRow)
                                ->getNumberFormat()
                                ->setFormatCode($columnaString);
                        }
                    }
                } else {
                    if (is_numeric($columna)) {
                        $columna = $this->getProceso()->stringFromColumnIndex($columna);
                    }
                    $this->getHoja()->getStyle($columna . $this->getFilaBase() . ':' . $columna . $highestRow)
                        ->getNumberFormat()
                        ->setFormatCode($formato);
                }
            endforeach;
        endforeach;

        return $this;
    }

    public function setAnchoColumna($anchoColumna)
    {
        if (empty($this->getHoja()) || empty($anchoColumna) || !is_array($anchoColumna)) {
            $this->variableproceso->setMensajes('El ancho no tiene el formato correcto.', 'error');
            return $this;
        }

        foreach ($anchoColumna as $columna => $ancho):
            if (strpos($columna, ':') !== false) {
                $columna = explode(':', $columna, 2);
                if (is_numeric($columna[0]) && (is_numeric($columna[1]) || empty($columna[1]))) {
                    if (empty($columna[1])) {
                        $columna[1] = $this->getProceso()->columnIndexFromString($this->getHoja()->getHighestColumn());
                    }
                    foreach (range($columna[0], $columna[1]) as $columnaProceso) {
                        $columnaString = $this->getProceso()->stringFromColumnIndex($columnaProceso);
                        if (is_numeric($ancho)) {
                            $this->getHoja()->getColumnDimension($columnaString)->setWidth($ancho);
                        } elseif ($ancho == 'auto') {
                            $this->getHoja()->getColumnDimension($columnaString)->setAutoSize(true);
                        }
                    }
                }
            } else {
                if (is_numeric($columna)) {
                    $columna = $this->getProceso()->stringFromColumnIndex($columna);
                }
                if (is_numeric($ancho)) {
                    $this->getHoja()->getColumnDimension($columna)->setWidth($ancho);
                } elseif ($ancho == 'auto') {
                    $this->getHoja()->getColumnDimension($columna)->setAutoSize(true);
                }
            }
        endforeach;

        return $this;
    }

    public function setCeldas($celdas, $tipo = 'texto')
    {
        if (empty($this->getHoja()) || empty($celdas) || !is_array($celdas)) {
            $this->variableproceso->setMensajes('Las celdas no tienen el formato correcto.', 'error');
            return $this;
        }

        if ($tipo == 'texto') {
            foreach ($celdas as $celda => $valor):
                $this->getHoja()->setCellValueExplicit($celda, $valor, 's');
            endforeach;
        }
        return $this;
    }

    public function getArchivo($tipo = 'response')
    {
        if (empty($this->getTipo())) {
            $this->variableproceso->setMensajes('Las celdas no tienen el formato correcto.', 'error');
        }
        $tipoWriter['xlsx'] = 'Xlsx';
        $writer = $this->getProceso()->createWriter($this->archivo, $tipoWriter[$this->getTipo()]);
        if ($tipo == 'response') {
            $response = $this->container->get('phpexcel')->createStreamedResponse($writer);
            $response->headers->set('Content-Type', 'text/vnd.ms-excel; charset=utf-8');
            $response->headers->set('Content-Disposition', 'attachment;filename=' . $this->container->get('App\Service\MainVariableproceso')->sanitizeString($this->getNombre() . '.' . $this->getTipo()));
            $response->headers->set('Pragma', 'public');
            $response->headers->set('Cache-Control', 'max-age=1');
            return $response;
        } elseif ($tipo == 'archivo') {
            //$path=$this->container->getParameter('kernel.root_dir').'/../web/temp/'.$this->container->get('gopro_main_variable_proceso')->sanitizeString($this->getNombre().'.'.$this->getTipo());
            $path = tempnam(sys_get_temp_dir(), $this->getTipo());
            $writer->save($path);
            return $path;
        } else {
            return null;
        }
    }
}