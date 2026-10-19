<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Lista;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Enum\DocumentoTipoEnum;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * La lista de pasajeros para PeruRail, sobre su plantilla (`public/templates/perurail.xlsx`).
 *
 * Recuperada del panel viejo (`archivoprAction()`, etiqueta git `oweb-final`) con las mismas reglas,
 * que funcionaron desde 2022: tipo 1 adulto (12 o más) / 2 niño, género F/M, el documento con su
 * código PeruRail, la fecha `dd/mm/aaaa` y la nacionalidad por su código (`MaestroPais::$codigoPeruRail`,
 * que coincide con la tabla NACIONALIDADES de la propia plantilla: 230-Peruvian, 176-Spanish…).
 *
 * Hoja «PASAJEROS», cabecera en la fila 1 y cien filas preparadas (2 a 101): más de cien, en partes.
 */
final readonly class ListaParaPeruRail implements ListaParaProveedorInterface
{
    private const int FILAS = 100;
    private const int PRIMERA_FILA = 2;
    private const string HOJA = 'xl/worksheets/sheet1.xml';

    /** La columna TIPO DOC de la plantilla: DNI, CEX, PAS, ID. */
    private const array TIPO_DOC = [
        'DNI' => 'DNI',
        'PASAPORTE' => 'PAS',
        'CE' => 'CEX',
        'CI' => 'ID',
    ];

    public function __construct(
        private RellenadorDePlantillaXlsx $rellenador,
        #[Autowire('%kernel.project_dir%/public/templates/perurail.xlsx')]
        private string $plantilla,
    ) {
    }

    public function formato(): string
    {
        return 'perurail';
    }

    public function generar(CotizacionFile $file, array $pasajeros): ArchivoDeLista
    {
        $avisos = [];
        $partes = [];

        foreach (array_chunk($pasajeros, self::FILAS) as $tanda) {
            $celdas = [];
            foreach ($tanda as $i => $pasajero) {
                $fila = self::PRIMERA_FILA + $i;
                $faltan = $this->fila($pasajero, $fila, $celdas);
                if ($faltan !== []) {
                    $avisos[] = sprintf('%s: falta %s', $this->nombre($pasajero), implode(', ', $faltan));
                }
            }
            $partes[] = $this->rellenador->rellenar($this->plantilla, self::HOJA, $celdas);
        }

        return ArchivoDeLista::dePartes($partes, 'PERURAIL_' . $file->getLocalizador(), $avisos);
    }

    /**
     * @param array<string, Celda> $celdas
     *
     * @return list<string> lo que falta, para el aviso
     */
    private function fila(CotizacionFilepasajero $pasajero, int $fila, array &$celdas): array
    {
        $faltan = [];
        $documento = DocumentoParaLista::de($pasajero);

        $tipoPax = $pasajero->getTipopaxperurail();
        $tipoPax !== null ? $celdas["A$fila"] = Celda::numero($tipoPax) : $faltan[] = 'la fecha de nacimiento';

        $sexo = $pasajero->getSexo()?->value;
        $sexo !== null ? $celdas["B$fila"] = Celda::texto($sexo) : $faltan[] = 'el sexo';

        if ($documento !== null && ($tipo = $documento->getTipo()) instanceof DocumentoTipoEnum && isset(self::TIPO_DOC[$tipo->value])) {
            $celdas["C$fila"] = Celda::texto(self::TIPO_DOC[$tipo->value]);
            // Sin tildes, como siempre: un número de documento no las lleva y PeruRail las rechaza.
            $celdas["D$fila"] = Celda::texto(self::sinTildes(trim((string) $documento->getNumero())));
        } else {
            $faltan[] = 'el documento';
        }

        $celdas["E$fila"] = Celda::texto(trim((string) $pasajero->getNombre()));
        $celdas["F$fila"] = Celda::texto(trim((string) $pasajero->getApellido()));

        $nacimiento = $pasajero->getFechanacimiento();
        if ($nacimiento !== null) {
            $celdas["G$fila"] = Celda::texto($nacimiento->format('d/m/Y'));
        }

        $codigo = $pasajero->getPais()?->getCodigoPeruRail();
        $codigo !== null ? $celdas["H$fila"] = Celda::numero($codigo) : $faltan[] = 'la nacionalidad';

        return $faltan;
    }

    private function nombre(CotizacionFilepasajero $pasajero): string
    {
        return trim(sprintf('%s %s', $pasajero->getNombre(), $pasajero->getApellido())) ?: 'Pasajero sin nombre';
    }

    private static function sinTildes(string $texto): string
    {
        $sin = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);

        return $sin === false ? $texto : $sin;
    }
}
