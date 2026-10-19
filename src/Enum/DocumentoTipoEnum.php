<?php

declare(strict_types=1);

namespace App\Enum;

enum DocumentoTipoEnum: string
{
    case DNI = 'DNI';
    case CE = 'CE'; // Carné de Extranjería
    case RUC = 'RUC';
    case PASAPORTE = 'PASAPORTE';
    case CI = 'CI'; // Carné de Identidad

    public function getLabel(): string
    {
        return match($this) {
            self::DNI => 'DNI',
            self::CE => 'C.E.',
            self::RUC => 'RUC',
            self::PASAPORTE => 'Pasaporte',
            self::CI => 'Carné de Identidad',
        };
    }

    /**
     * ¿Sirve para identificarse EN UN VIAJE?
     *
     * ⚠️ El RUC no: es un identificador tributario de empresa. Vive en la misma tabla porque una
     * factura lo necesita, pero no es un documento de persona y en una pantalla de viaje —donde se
     * comprueba con qué se va a volar— sólo sería ruido que además es dato fiscal.
     *
     * Se declara aquí y no en el sitio que lo pregunta porque ya hay dos que lo preguntan, y una
     * regla repetida es una regla que acaba discrepando.
     */
    public function esDocumentoDeViaje(): bool
    {
        return match ($this) {
            self::DNI, self::CE, self::PASAPORTE, self::CI => true,
            self::RUC => false,
        };
    }

    /**
     * Retorna el código requerido por la API del Ministerio de Cultura (Machu Picchu).
     */
    public function getCodigoMC(): ?int
    {
        return match($this) {
            self::DNI => 1,
            self::CE => 2,
            self::PASAPORTE => 3,
            self::CI => 7,
            self::RUC => null,
        };
    }

    /**
     * El código de la columna «TIPO DOC» de la lista de pasajeros de PeruRail.
     *
     * Sale de la tabla «TIPO DOCUMENTO» de la hoja INDICACIONES de su propia plantilla
     * (`public/templates/perurail.xlsx`). En Oweb vivía como dato en `maestro_tipodocumento.codigopr`
     * y esa tabla se fue con el archivado; aquí va en código porque son cuatro valores fijos.
     *
     * ⚠️ `ID` («Documento de Identidad») es la cédula de otro país, o sea nuestro CI. El DNI tiene su
     * propio código y no se manda como `ID`.
     */
    public function getCodigoPeruRail(): ?string
    {
        return match($this) {
            self::DNI => 'DNI',
            self::CE => 'CEX',
            self::PASAPORTE => 'PAS',
            self::CI => 'ID',
            self::RUC => null,
        };
    }

    /**
     * Retorna el código requerido por la API de Consettur (Buses Aguas Calientes).
     */
    public function getCodigoConsettur(): ?int
    {
        return match($this) {
            self::DNI => 1,
            self::CE => 2,
            self::PASAPORTE, self::CI => 4,
            self::RUC => null,
        };
    }
}