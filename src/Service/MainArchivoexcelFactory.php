<?php

namespace App\Service;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\IWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MainArchivoexcelFactory
{
    private string $phpExcelIO;
    private string $phpExcelCell;

    public function __construct(string $phpExcelIO = '\PhpOffice\PhpSpreadsheet\IOFactory', string $phpExcelCell = '\PhpOffice\PhpSpreadsheet\Cell\Coordinate')
    {
        $this->phpExcelIO = $phpExcelIO;
        $this->phpExcelCell = $phpExcelCell;
    }
    /**
     * Creates an empty PHPExcel Object if the filename is empty, otherwise loads the file into the object.
     */
    public function createPHPExcelObject(?string $filename =  null): Spreadsheet
    {
        if(null == $filename) {
            return new Spreadsheet();
        }

        return call_user_func(array($this->phpExcelIO, 'load'), $filename);
    }


    /**
     * Creates an empty PHPExcel Object if the filename is empty, otherwise loads the file into the object.
     */
    public function columnIndexFromString(string $column): int
    {
        return call_user_func(array($this->phpExcelCell, 'columnIndexFromString'), $column);
    }

    public function stringFromColumnIndex(int $indice): string
    {
        return call_user_func(array($this->phpExcelCell, 'stringFromColumnIndex'), $indice);
    }

    /**
     * Create a writer given the PHPExcelObject and the type,
     *   the type could be one of PHPExcel_IOFactory::$_autoResolveClasses
     */
    public function createWriter(Spreadsheet $phpExcelObject, string $type = 'Xlsx', bool $removeEnclosure = false): IWriter
    {
        $writer = call_user_func(array($this->phpExcelIO, 'createWriter'), $phpExcelObject, $type);

        if($removeEnclosure){
            $writer->setEnclosure('');
        }

        return $writer;
    }

    /**
     * Stream the file as Response.
     */
    public function createStreamedResponse(IWriter $writer): StreamedResponse
    {
        return new StreamedResponse(
            function () use ($writer) {
                $writer->save('php://output');
            }
        );
    }

}
