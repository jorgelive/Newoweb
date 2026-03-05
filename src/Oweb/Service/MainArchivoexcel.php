<?php

namespace App\Oweb\Service;

use Doctrine\Persistence\ObjectRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Response;

/**
 * Servicio principal para la gestión, generación, lectura y manipulación de archivos Excel.
 * Actúa como un Wrapper avanzado sobre PhpSpreadsheet, añadiendo lógica de negocio
 * para parsear tablas complejas, aplicar validaciones y configurar formatos de salida.
 */
class MainArchivoexcel
{
    /**
     * @var string Ruta absoluta hacia el directorio de plantillas internas.
     */
    private string $internalTemplateDir;

    /**
     * @var array<string, string> Mapeo de extensiones a los Writers de PhpSpreadsheet.
     */
    protected array $tipoWriter = ['xlsx' => 'Xlsx', 'xls' => 'Xls', 'csv' => 'Csv'];

    private string $archivoBasePath = '';
    private Spreadsheet $archivo;
    private Worksheet $hoja;

    // //reader
    private array $setTablaSpecs;
    private array $setColumnaSpecs;
    private array $validCols;
    private bool $parsed = false;
    private bool $descartarBlanco = false;
    private bool $trimEspacios = false;
    private array $tablaSpecs;
    private array $columnaSpecs;
    private array $existentesRaw;
    private array $existentesIndizados; // indizados por la llave
    private array $existentesIndizadosMulti; // indizados por la llave
    private array $existentesIndizadosKp; // indizados valores incluyen las llaves
    private array $existentesIndizadosMultiKp; // indizados valores incluyen las llaves
    private array $existentesCustomRaw;
    private array $existentesCustomIndizados;
    private array $existentesCustomIndizadosMulti;
    private array $existentesDescartados;
    private array $camposCustom;
    private int $skipRows = 0;

    // //writer
    private int $filaBase = 1;
    private string $columnaBase = 'A';
    private string $nombre;
    private string $tipo;
    private bool $removeEnclosure;

    /**
     * Constructor del servicio.
     *
     * Inyecta las dependencias necesarias mediante promoción de propiedades de PHP 8
     * y utiliza Autowire para obtener la ruta pública sin necesidad de bindings en services.yaml.
     *
     * @param MainVariableproceso $variableproceso Dependencia para manejo de variables de proceso, mensajes de error y utilidades.
     * @param MainArchivoexcelFactory $archivoexcelFactory Fábrica para construir instancias y Writers de PhpSpreadsheet.
     * @param string $publicDir Parámetro inyectado con la ruta al directorio público.
     */
    public function __construct(
        protected MainVariableproceso $variableproceso,
        private MainArchivoexcelFactory $archivoexcelFactory,
        #[Autowire('%app.public_dir%')]
        string $publicDir
    ) {
        $this->internalTemplateDir = $publicDir . '/templates';
    }

    /**
     * Obtiene la ruta base actual del archivo con el que se está trabajando.
     *
     * @return string La ruta absoluta del archivo base.
     */
    public function getArchivoBasePath(): string
    {
        return $this->archivoBasePath;
    }

    /**
     * Obtiene la hoja de cálculo activa.
     *
     * Este método existe para uso interno de la clase en los procesos de lectura
     * y escritura, encapsulando el acceso directo a PhpSpreadsheet.
     *
     * @return Worksheet
     */
    private function getHoja(): Worksheet
    {
        return $this->hoja;
    }

    /**
     * Establece la cantidad de filas iniciales que el reader debe saltar antes de procesar.
     *
     * Útil cuando los archivos Excel tienen cabeceras, títulos o metadatos en las
     * primeras filas que no forman parte de los datos tabulares.
     *
     * @param int $rows Número de filas a ignorar.
     * @return self
     */
    public function setSkipRows(int $rows): self
    {
        $this->skipRows = $rows;
        return $this;
    }

    /**
     * Configura el archivo base a procesar consultando una entidad en la base de datos.
     *
     * Este método existe para vincular directamente el servicio Excel con archivos
     * previamente almacenados y registrados mediante un repositorio Doctrine.
     *
     * @param ObjectRepository $repositorio Repositorio de la entidad del archivo.
     * @param int $id Identificador del archivo en la base de datos.
     * @return self
     */
    public function setArchivoBaseRepositorio(ObjectRepository $repositorio, int $id): self
    {
        if(empty($repositorio) || empty($id)) {
            $this->variableproceso->setMensajes('Los parametros del archivo base no son válidos.', 'error');
            return $this;
        }
        $archivoAlmacenado = $repositorio->find($id);
        if(empty($archivoAlmacenado) || !is_object($archivoAlmacenado)) {
            $this->variableproceso->setMensajes('El archivo no existe en la base de datos o es inválido.', 'error');
            return $this;
        }
        $fs = new Filesystem();

        if(!$fs->exists($archivoAlmacenado->getInternalPath())) {
            $this->variableproceso->setMensajes('El archivo no existe en la ruta.', 'error');
            return $this;
        }
        $this->archivoBasePath = $archivoAlmacenado->getInternalPath();
        return $this;
    }

    /**
     * Establece explícitamente la ruta del archivo base a procesar desde el directorio de plantillas.
     *
     * @param string $path Ruta relativa al archivo dentro del directorio de plantillas internas.
     * @return self
     */
    public function setArchivoBasePath(string $path): self
    {
        if(empty($path)) {
            $this->variableproceso->setMensajes('Los parametros del archivo base no son válidos.', 'error');
            return $this;
        }

        $fs = new Filesystem();
        $this->archivoBasePath = $this->internalTemplateDir . '/' . $path;

        if(!$fs->exists($this->archivoBasePath)) {
            $this->variableproceso->setMensajes('El archivo no existe en la ruta.', 'error');
            return $this;
        }

        return $this;
    }

    /**
     * Inicializa la instancia de Spreadsheet, ya sea cargando un archivo base o creando uno nuevo en blanco.
     *
     * @throws \PhpOffice\PhpSpreadsheet\Exception Si ocurre un error al cargar el archivo base.
     * @return self
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
    }

    /**
     * Selecciona una hoja específica del Excel para trabajar, creándola si no existe.
     *
     * @param int $hojaIndex Índice base 1 de la hoja a seleccionar.
     * @throws \PhpOffice\PhpSpreadsheet\Exception Si hay un problema al crear o activar la hoja.
     * @return self
     */
    public function setHoja(int $hojaIndex): self
    {
        $cantidad = $this->archivo->getSheetCount();

        if($this->archivo->getSheetCount() < $hojaIndex) {
            $diferencia = $hojaIndex - $this->archivo->getSheetCount();
            for ($x = 0; $x < $diferencia; $x++) {
                $numeroHoja = $cantidad + 1 + $x;
                $this->archivo->createSheet();
            }
        }
        $this->hoja = $this->archivo->setActiveSheetIndex($hojaIndex - 1);

        return $this;
    }

    /**
     * Configura los metadatos y especificaciones de las columnas antes de iniciar el parseo.
     *
     * @param array $setTablaSpecs Especificaciones generales de la tabla (ej. tipo).
     * @param array $setColumnaSpecs Especificaciones detalladas por columna.
     * @return self
     */
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

    /**
     * Método principal del Reader: Lee la hoja activa y extrae los datos basados en las especificaciones.
     *
     * @return bool True si el parseo fue exitoso y encontró datos, False en caso contrario.
     */
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

        for ($row = $startRow; $row <= $highestRow; ++$row) {
            $procesandoNombre = false;
            for ($col = 0; $col < $highestColumnIndex; ++$col) {
                $value = $this->getHoja()->getCellByColumnAndRow($col, $row)->getValue();

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

    /**
     * Indica si el archivo ya fue procesado mediante parseExcel.
     * @return bool
     */
    public function isParsed(): bool
    {
        return $this->parsed;
    }

    /**
     * @return bool
     */
    public function isDescartarBlanco(): bool
    {
        return $this->descartarBlanco;
    }

    /**
     * @param bool $descartarBlanco
     * @return self
     */
    public function setDescartarBlanco(bool $descartarBlanco): self
    {
        $this->descartarBlanco = $descartarBlanco;
        return $this;
    }

    /**
     * @param bool $removeEnclosure
     * @return self
     */
    public function setRemoveEnclosure(bool $removeEnclosure): self
    {
        $this->removeEnclosure = $removeEnclosure;
        return $this;
    }

    /**
     * @return bool
     */
    public function isRemoveEnclosure(): bool
    {
        return $this->removeEnclosure;
    }

    /**
     * @return bool
     */
    public function isTrimEspacios(): bool
    {
        return $this->trimEspacios;
    }

    /**
     * @param bool $trimEspacios
     * @return self
     */
    public function setTrimEspacios(bool $trimEspacios): self
    {
        $this->trimEspacios = $trimEspacios;
        return $this;
    }

    /**
     * @return array
     */
    public function getExistentesRaw(): array
    {
        return $this->existentesRaw;
    }

    /**
     * @return array
     */
    public function getExistentesIndizados(): array
    {
        return $this->existentesIndizados;
    }

    /**
     * @param array $existentesIndizados
     * @return self
     */
    private function setExistentesIndizados(array $existentesIndizados): self
    {
        $this->existentesIndizados = $existentesIndizados;
        return $this;
    }

    /**
     * @return array
     */
    public function getExistentesIndizadosMulti(): array
    {
        return $this->existentesIndizadosMulti;
    }

    /**
     * @param array $existentesIndizadosMulti
     * @return self
     */
    private function setExistentesIndizadosMulti(array $existentesIndizadosMulti): self
    {
        $this->existentesIndizadosMulti = $existentesIndizadosMulti;
        return $this;
    }

    /**
     * @return array
     */
    public function getExistentesIndizadosKp(): array
    {
        return $this->existentesIndizadosKp;
    }

    /**
     * @param array $existentesIndizadosKp
     * @return self
     */
    private function setExistentesIndizadosKp(array $existentesIndizadosKp): self
    {
        $this->existentesIndizadosKp = $existentesIndizadosKp;
        return $this;
    }

    /**
     * @return array
     */
    public function getExistentesIndizadosMultiKp(): array
    {
        return $this->existentesIndizadosMultiKp;
    }

    /**
     * @param array $existentesIndizadosMultiKp
     * @return self
     */
    private function setExistentesIndizadosMultiKp(array $existentesIndizadosMultiKp): self
    {
        $this->existentesIndizadosMultiKp = $existentesIndizadosMultiKp;
        return $this;
    }

    /**
     * @param array $existentesRaw
     * @return self
     */
    private function setExistentesRaw(array $existentesRaw): self
    {
        $this->existentesRaw = $existentesRaw;
        return $this;
    }

    /**
     * @param array $campos
     * @return self
     */
    public function setCamposCustom(array $campos): self
    {
        $this->camposCustom = $campos;
        return $this;
    }

    /**
     * @return array
     */
    public function getCamposCustom(): array
    {
        return $this->camposCustom;
    }

    /**
     * @return array
     */
    public function getExistentesCustomIndizados(): array
    {
        return $this->existentesCustomIndizados;
    }

    /**
     * @param array $existentesCustomIndizados
     * @return self
     */
    private function setExistentesCustomIndizados(array $existentesCustomIndizados): self
    {
        $this->existentesCustomIndizados = $existentesCustomIndizados;
        return $this;
    }

    /**
     * @return array
     */
    public function getExistentesCustomIndizadosMulti(): array
    {
        return $this->existentesCustomIndizadosMulti;
    }

    /**
     * @param array $existentesCustomIndizadosMulti
     * @return self
     */
    private function setExistentesCustomIndizadosMulti(array $existentesCustomIndizadosMulti): self
    {
        $this->existentesCustomIndizadosMulti = $existentesCustomIndizadosMulti;
        return $this;
    }

    /**
     * @return array
     */
    public function getExistentesCustomRaw(): array
    {
        return $this->existentesCustomRaw;
    }

    /**
     * @param array $existentesCustomRaw
     * @return self
     */
    private function setExistentesCustomRaw(array $existentesCustomRaw): self
    {
        $this->existentesCustomRaw = $existentesCustomRaw;
        return $this;
    }

    /**
     * @return array
     */
    public function getExistentesDescartados(): array
    {
        return $this->existentesDescartados;
    }

    /**
     * @param array $existentesDescartados
     * @return self
     */
    public function setExistentesDescartados(array $existentesDescartados): self
    {
        $this->existentesDescartados = $existentesDescartados;
        return $this;
    }

    /**
     * @param int $filaBase
     * @return self
     */
    public function setFilaBase(int $filaBase): self
    {
        $this->filaBase = $filaBase;
        return $this;
    }

    /**
     * @param string $columnaBase
     * @return self
     */
    public function setColumnaBase(string $columnaBase): self
    {
        $this->columnaBase = $columnaBase;
        return $this;
    }

    /**
     * Método helper del Writer que agrupa la configuración de nombre, extensión y volcado de datos.
     *
     * @param array $contenido Array multidimensional con la data a exportar.
     * @param array $encabezado Array unidimensional con los títulos de las columnas.
     * @param string $nombre Nombre del archivo final.
     * @param string $tipo Extensión del archivo ('xlsx', 'csv', etc).
     * @param bool $removeEnclosure Flag específico para generación CSV sin comillas.
     * @return self
     */
    public function setParametrosWriter(array $contenido, array $encabezado = [], string $nombre = 'archivoGenerado', string $tipo = 'xlsx', bool $removeEnclosure = false): self
    {
        $this->setNombre($nombre);
        $this->setRemoveEnclosure($removeEnclosure);

        if(!empty($encabezado)) {
            $this->setFila($encabezado, $this->columnaBase . $this->filaBase);
            $this->setFilaBase($this->filaBase + 1);
        }
        $this->setTabla($contenido, $this->columnaBase . $this->getFilaBase());

        $this->setTipo($tipo);
        return $this;
    }

    /**
     * Inserta los datos de un array unidimensional a lo largo de una fila específica.
     *
     * @param array $fila Datos a insertar.
     * @param string $posicion Coordenada inicial (ej. 'A1').
     * @return self
     */
    public function setFila(array $fila, string $posicion): self
    {
        if(empty($this->getHoja()) || empty($fila) || !is_array($fila) || $this->variableproceso->isMultiArray($fila) || empty($posicion)) {
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

    /**
     * @return int
     */
    public function getFilaBase(): int
    {
        return $this->filaBase;
    }

    /**
     * @param array $columna Array de valores.
     * @param string $posicion Coordenada inicial.
     * @return self
     */
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

    /**
     * Vuelca un array multidimensional directamente a la hoja de cálculo.
     *
     * @param array $tabla Array multidimensional con la data.
     * @param string $posicion Coordenada de anclaje.
     * @return self
     */
    public function setTabla(array $tabla, string $posicion): self
    {
        if(empty($this->getHoja()) || empty($tabla) || !$this->variableproceso->isMultiArray($tabla) || empty($posicion)) {
            $this->variableproceso->setMensajes('El formato de tabla no es correcto.', 'error');
            return $this;
        }
        $this->getHoja()->fromArray($tabla, NULL, $posicion);
        return $this;
    }

    /**
     * @param string $tipo
     * @return self
     */
    public function setTipo(string $tipo): self
    {
        $this->tipo = $tipo;
        return $this;
    }

    /**
     * @return string
     */
    public function getTipo(): string
    {
        return $this->tipo;
    }

    /**
     * @param string $nombre
     * @return self
     */
    public function setNombre(string $nombre): self
    {
        $this->nombre = $nombre;
        return $this;
    }

    /**
     * @return string
     */
    public function getNombre(): string
    {
        return $this->nombre;
    }

    /**
     * Aplica formatos de celda (ej. monetario, fechas, ceros a la izquierda) a rangos de columnas.
     * BUG FIX: Corrección de error de concatenación Array-to-String.
     *
     * @param array $formatoColumna Estructura: ['formatoExcel' => [columna1, columna2]].
     * @return self
     */
    public function setFormatoColumna(array $formatoColumna): self
    {
        if(empty($this->getHoja()) || empty($formatoColumna) || !$this->variableproceso->isMultiArray($formatoColumna)) {
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
                            $columna[1] = $this->archivoexcelFactory->columnIndexFromString($highestColumn);
                        }
                        foreach(range($columna[0], $columna[1]) as $columnaProceso) {
                            $columnaString = $this->archivoexcelFactory->stringFromColumnIndex($columnaProceso);
                            $this->getHoja()
                                ->getStyle($columnaString . $this->getFilaBase() . ':' . $columnaString . $highestRow)
                                ->getNumberFormat()
                                ->setFormatCode($formato);
                        }
                    }
                }else{
                    if(is_numeric($columna)) {
                        $columna = $this->archivoexcelFactory->stringFromColumnIndex($columna);
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

    /**
     * Ajusta el ancho de las columnas dadas a un tamaño fijo o automático.
     * BUG FIX: Corrección de error de concatenación Array-to-String.
     *
     * @param array $anchoColumna Mapeo [columna => anchoFijo O 'auto'].
     * @return self
     */
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

    /**
     * Forzar la escritura de celdas específicas fijando estrictamente un tipo de dato subyacente.
     *
     * @param array $celdas Mapeo de [Coordenada => Valor] (ej. ['A1' => '00123']).
     * @param string $tipo Tipo forzado, por defecto 'texto'.
     * @return self
     */
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

    /**
     * Construye y retorna la respuesta HTTP de Symfony para iniciar la descarga directa.
     *
     * @return Response Objeto Response de Symfony configurado.
     */
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

    /**
     * Guarda el archivo Excel generado en el sistema temporal del servidor y retorna su ruta.
     *
     * @return string Ruta absoluta al archivo temporal creado.
     */
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