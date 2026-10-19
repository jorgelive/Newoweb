<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Lista;

/**
 * Lo que se descarga, y lo que hay que decirle a quien lo baja.
 *
 * `avisos` son los pasajeros que salieron incompletos o se quedaron fuera, con el motivo: una celda
 * vacía en la lista de PeruRail no da error aquí, da un pasajero sin billete en la estación.
 */
final readonly class ArchivoDeLista
{
    /** @param list<string> $avisos */
    public function __construct(
        public string $contenido,
        public string $nombre,
        public string $tipoMime,
        public array $avisos,
    ) {
    }

    /**
     * Una parte sola va tal cual; varias, en un ZIP con «_Parte_N». Los terceros limitan las filas
     * por archivo (100 PeruRail, 10 Cultura), así que un grupo grande son varios.
     *
     * @param list<string> $partes cada una, un .xlsx en binario
     * @param list<string> $avisos
     */
    public static function dePartes(array $partes, string $base, array $avisos): self
    {
        $xlsx = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        if (count($partes) === 1) {
            return new self($partes[0], $base . '.xlsx', $xlsx, $avisos);
        }

        $temporal = tempnam(sys_get_temp_dir(), 'lista');
        if ($temporal === false) {
            throw new \RuntimeException('No se pudo crear el archivo temporal.');
        }

        $zip = new \ZipArchive();
        $zip->open($temporal, \ZipArchive::OVERWRITE);
        foreach ($partes as $i => $parte) {
            $zip->addFromString(sprintf('%s_Parte_%d.xlsx', $base, $i + 1), $parte);
        }
        $zip->close();

        $binario = (string) file_get_contents($temporal);
        unlink($temporal);

        return new self($binario, $base . '.zip', 'application/zip', $avisos);
    }
}
