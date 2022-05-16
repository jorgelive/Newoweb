<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\StreamedResponse;



class MainExcelFactory
{
    private $phpExcelIO;
    private $phpExcelCell;
    //private $phpExcelStyle;

    public function __construct($phpExcelIO = '\PhpOffice\PhpSpreadsheet\IOFactory', $phpExcelCell = '\PhpOffice\PhpSpreadsheet\Cell\Coordinate')
    {
        $this->phpExcelIO = $phpExcelIO;
        $this->phpExcelCell = $phpExcelCell;
    }
    /**
     * Creates an empty PHPExcel Object if the filename is empty, otherwise loads the file into the object.
     *
     * @param string $filename
     *
     * @return \PhpOffice\PhpSpreadsheet\Spreadsheet
     */
    public function createPHPExcelObject($filename =  null)
    {
        if (null == $filename) {
            $phpExcelObject = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

            return $phpExcelObject;
        }

        return call_user_func(array($this->phpExcelIO, 'load'), $filename);
    }

    /**
     * Creates an empty PHPExcel Sheet if the filename is empty, otherwise loads the file into the object.
     *
     * @paran \PHPExcel
     * @param string $filename
     *
     * @return \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
     */
    public function createPHPExcelSheet($phpExcelObject, $name = null)
    {
        return new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($phpExcelObject, $name);
    }

    /**
     * Creates an empty PHPExcel Object if the filename is empty, otherwise loads the file into the object.
     *
     * @param string $column
     *
     * @return \PhpOffice\PhpSpreadsheet\Spreadsheet
     */
    public function columnIndexFromString($column =  null)
    {
        return call_user_func(array($this->phpExcelCell, 'columnIndexFromString'), $column);
    }

    /**
     * Creates an empty PHPExcel Object if the filename is empty, otherwise loads the file into the object.
     *
     * @param string $indice
     *
     * @return \PhpOffice\PhpSpreadsheet\Spreadsheet
     */
    public function stringFromColumnIndex($indice =  null)
    {
        return call_user_func(array($this->phpExcelCell, 'stringFromColumnIndex'), $indice);
    }

    /**
     * Create a writer given the PHPExcelObject and the type,
     *   the type coul be one of PHPExcel_IOFactory::$_autoResolveClasses
     *
     * @param \PhpOffice\PhpSpreadsheet\Spreadsheet $phpExcelObject
     * @param string    $type
     *
     *
     * @return \PhpOffice\PhpSpreadsheet\Writer\IWriter
     */
    public function createWriter(\PhpOffice\PhpSpreadsheet\Spreadsheet $phpExcelObject, $type = 'Xlsx')
    {
        return call_user_func(array($this->phpExcelIO, 'createWriter'), $phpExcelObject, $type);
    }

    /**
     * Stream the file as Response.
     *
     * @param \PhpOffice\PhpSpreadsheet\Writer\IWriter $writer
     * @param int                      $status
     * @param array                    $headers
     *
     * @return StreamedResponse
     */
    public function createStreamedResponse(\PhpOffice\PhpSpreadsheet\Writer\IWriter $writer, $status = 200, $headers = array())
    {
        return new StreamedResponse(
            function () use ($writer) {
                $writer->save('php://output');
            },
            $status,
            $headers
        );
    }


}
