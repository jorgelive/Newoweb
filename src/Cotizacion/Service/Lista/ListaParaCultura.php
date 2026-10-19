<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Lista;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Enum\DocumentoTipoEnum;
use App\Enum\SexoEnum;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * La carga masiva del Ministerio de Cultura (Llaqta / Machu Picchu), sobre su plantilla
 * (`public/templates/tuboletocultura.xlsx`).
 *
 * Recuperada de `archivomcAction()` (etiqueta git `oweb-final`) con sus reglas de procedencia y
 * tarifa, y escrita con `RellenadorDePlantillaXlsx` en vez de PhpSpreadsheet: el Ministerio empezó a
 * detectar los archivos que no se llenaron a mano (Jorge, 19/10/2026), y el motivo más probable es
 * lo que la librería rompe al guardar. Ver el docblock del rellenador.
 *
 * ── Los códigos salen de la PLANTILLA, no de aquí ───────────────────────────
 * Las columnas idProcedencia, idPais, idTarifa e idTipoDocumento son fórmulas `BUSCARV` sobre la
 * hoja oculta «Tablas». Se les deja la fórmula y se les pone el resultado, calculado con esas mismas
 * tablas leídas del archivo. Así, si el Ministerio cambia un código y se actualiza la plantilla, el
 * código cambia con ella; y no se depende de `DocumentoTipoEnum::getCodigoMC()`, que ya discrepa (CI
 * es 7 allí y 8 en la tabla del Ministerio, donde 7 es DIE).
 *
 * El país va por `MaestroPais::$codigoMc`, que coincide con el idPais de la tabla —comprobado,
 * hasta su «Paises Bajos» sin tilde—, y el NOMBRE se toma de la tabla por ese id.
 *
 * Diez filas preparadas (4 a 13): más de diez, en partes. Los menores de 3 años no llevan entrada.
 */
final readonly class ListaParaCultura implements ListaParaProveedorInterface
{
    private const int FILAS = 10;
    private const int PRIMERA_FILA = 4;
    private const string HOJA = 'xl/worksheets/sheet1.xml';
    private const string TABLAS = 'xl/worksheets/sheet2.xml';

    /** Comunidad Andina: tarifa promocional, igual que el peruano. */
    private const array CAN = ['BO', 'CO', 'EC'];

    private const array TIPO_DOC = ['DNI' => 'DNI', 'CE' => 'CE', 'PASAPORTE' => 'PAS', 'CI' => 'CI'];

    public function __construct(
        private RellenadorDePlantillaXlsx $rellenador,
        #[Autowire('%kernel.project_dir%/public/templates/tuboletocultura.xlsx')]
        private string $plantilla,
    ) {
    }

    public function formato(): string
    {
        return 'cultura';
    }

    public function generar(CotizacionFile $file, array $pasajeros): ArchivoDeLista
    {
        $t = $this->rellenador->columnas($this->plantilla, self::TABLAS, ['D', 'E', 'G', 'H', 'M', 'N', 'P', 'Q']);
        $tablas = [
            'documento' => self::diccionario($t['D'], $t['E']),
            'pais' => self::diccionario($t['G'], $t['H']),
            'tarifa' => self::diccionario($t['M'], $t['N']),
            'procedencia' => self::diccionario($t['P'], $t['Q']),
        ];
        // id → nombre, para escribir el país como lo escribe el Ministerio.
        $paisPorId = [];
        foreach ($tablas['pais'] as $nombre => $id) {
            $paisPorId[(string) $id] = $nombre;
        }

        $avisos = [];
        $filas = [];

        foreach ($pasajeros as $pasajero) {
            $edad = $pasajero->getEdad();
            if ($edad === null) {
                $avisos[] = sprintf('%s: falta la fecha de nacimiento — no se sabe qué tarifa le toca, va sin tarifa', $this->nombre($pasajero));
            } elseif ($edad < 3) {
                $avisos[] = sprintf('%s: menor de 3 años, no necesita entrada — no va en la lista', $this->nombre($pasajero));
                continue;
            }
            $filas[] = $pasajero;
        }

        $partes = [];
        foreach (array_chunk($filas, self::FILAS) ?: [[]] as $tanda) {
            $celdas = [];
            foreach ($tanda as $i => $pasajero) {
                $faltan = $this->fila($pasajero, self::PRIMERA_FILA + $i, (string) $file->getLocalizador(), $tablas, $paisPorId, $celdas);
                if ($faltan !== []) {
                    $avisos[] = sprintf('%s: falta %s', $this->nombre($pasajero), implode(', ', $faltan));
                }
            }
            $partes[] = $this->rellenador->rellenar($this->plantilla, self::HOJA, $celdas);
        }

        return ArchivoDeLista::dePartes($partes, 'MC_' . $file->getLocalizador(), $avisos);
    }

    /**
     * @param array<string, array<string, int|float|string>> $tablas
     * @param array<int|string, string> $paisPorId
     * @param array<string, Celda> $celdas
     *
     * @return list<string>
     */
    private function fila(CotizacionFilepasajero $pasajero, int $fila, string $codFile, array $tablas, array $paisPorId, array &$celdas): array
    {
        $faltan = [];
        $documento = DocumentoParaLista::de($pasajero);
        $tipoDoc = $documento?->getTipo();
        $iso = $pasajero->getPais()?->getId();

        // 1. Procedencia: peruano, CAN, residente (con carné de extranjería) o extranjero.
        $procedencia = match (true) {
            $iso === 'PE' => 'Peruano',
            in_array($iso, self::CAN, true) => 'Países CAN',
            $tipoDoc === DocumentoTipoEnum::CE => 'Residente extranjero',
            default => 'Extranjero',
        };
        $this->conCodigo($celdas, "C$fila", "D$fila", $procedencia, $tablas['procedencia'], $faltan, 'la procedencia');

        // 2. País: por su código del Ministerio, y el nombre como lo escribe su tabla.
        $codigoMc = $pasajero->getPais()?->getCodigoMc();
        $nombrePais = $codigoMc !== null ? ($paisPorId[$codigoMc] ?? null) : null;
        if ($codigoMc !== null && $nombrePais !== null) {
            $celdas["E$fila"] = Celda::texto($nombrePais);
            $celdas["F$fila"] = Celda::calculada($codigoMc);
        } else {
            $faltan[] = 'el país (sin código del Ministerio)';
        }

        // 3. Tarifa: por edad y procedencia. Promocional para peruanos, CAN y residentes.
        $edad = $pasajero->getEdad();
        if ($edad !== null) {
            $promocional = $procedencia !== 'Extranjero';
            $tarifa = $edad >= 18
                ? ($promocional ? 'General promocional' : 'General')
                : ($promocional ? 'Menor de edad entre 3-17 años promocional' : 'Menor de edad entre 3 - 17 años');
            $this->conCodigo($celdas, "G$fila", "H$fila", $tarifa, $tablas['tarifa'], $faltan, 'la tarifa');
        }

        // 4. Documento.
        if ($documento !== null && $tipoDoc instanceof DocumentoTipoEnum && isset(self::TIPO_DOC[$tipoDoc->value])) {
            $this->conCodigo($celdas, "I$fila", "J$fila", self::TIPO_DOC[$tipoDoc->value], $tablas['documento'], $faltan, 'el tipo de documento');
            $celdas["K$fila"] = Celda::texto(trim((string) $documento->getNumero()));
        } else {
            $faltan[] = 'el documento';
        }

        $nacimiento = $pasajero->getFechanacimiento();
        if ($nacimiento !== null) {
            $celdas["L$fila"] = Celda::texto($nacimiento->format('d-m-Y'));
        }

        $celdas["M$fila"] = Celda::texto(trim((string) $pasajero->getNombre()));
        [$paterno, $materno] = CotizacionFilepasajero::apellidosPorSeparado($pasajero->getApellido());
        if ($paterno !== null) {
            $celdas["N$fila"] = Celda::texto($paterno);
        }
        if ($materno !== null) {
            $celdas["O$fila"] = Celda::texto($materno);
        }

        $celdas["P$fila"] = Celda::texto($codFile);

        $sexo = $pasajero->getSexo();
        $sexo !== null
            ? $celdas["Q$fila"] = Celda::texto($sexo === SexoEnum::FEMENINO ? 'Femenino' : 'Masculino')
            : $faltan[] = 'el sexo';

        return $faltan;
    }

    /**
     * El texto en su celda y, en la de al lado, el resultado de su `BUSCARV`.
     *
     * @param array<string, Celda> $celdas
     * @param array<string, int|float|string> $tabla
     * @param list<string> $faltan
     */
    private function conCodigo(array &$celdas, string $refTexto, string $refCodigo, string $texto, array $tabla, array &$faltan, string $que): void
    {
        if (!isset($tabla[$texto])) {
            // La tabla del Ministerio no tiene ese valor: se deja vacío y se dice, en vez de
            // mandar un texto que su BUSCARV no encontraría.
            $faltan[] = sprintf('%s («%s» no está en la tabla del Ministerio)', $que, $texto);

            return;
        }

        $celdas[$refTexto] = Celda::texto($texto);
        $celdas[$refCodigo] = Celda::calculada($tabla[$texto]);
    }

    /**
     * @param array<int, string|int|float> $claves  fila → texto
     * @param array<int, string|int|float> $valores fila → código
     *
     * @return array<string, int|float|string>
     */
    private static function diccionario(array $claves, array $valores): array
    {
        $salida = [];
        foreach ($claves as $fila => $clave) {
            // La fila 1 es la cabecera de cada tabla.
            if ($fila > 1 && isset($valores[$fila])) {
                $salida[(string) $clave] = $valores[$fila];
            }
        }

        return $salida;
    }

    private function nombre(CotizacionFilepasajero $pasajero): string
    {
        return trim(sprintf('%s %s', $pasajero->getNombre(), $pasajero->getApellido())) ?: 'Pasajero sin nombre';
    }
}
