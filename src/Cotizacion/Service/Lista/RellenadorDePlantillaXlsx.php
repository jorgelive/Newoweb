<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Lista;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use ZipArchive;

/**
 * Rellena celdas de una plantilla .xlsx de un tercero SIN reescribir el libro.
 *
 * ── Por qué no PhpSpreadsheet ───────────────────────────────────────────────
 * Para un libro nuestro, PhpSpreadsheet está bien. Para la plantilla de OTRO —el Ministerio de
 * Cultura, PeruRail— no: al guardar **reconstruye el libro entero** desde su modelo, y lo que su
 * modelo no conoce se pierde o cambia. En la de Cultura eso es una consulta de Power Query
 * (`customXml` + `connections.xml`), una tabla con nombre, la cadena de cálculo y sus listas. Y las
 * columnas de código (idPais, idTarifa…) son FÓRMULAS cuyo resultado guardado queda en «0» si nadie
 * recalcula: el sistema que lee el archivo, que no abre Excel, ve un libro roto o incoherente. Es
 * lo que el Ministerio empezó a detectar (Jorge, 19/10/2026).
 *
 * Aquí se hace lo que haría alguien tecleando en Excel: **se copian todas las partes tal cual** y
 * sólo se reescriben las celdas pedidas de una hoja —con su estilo intacto—, los textos nuevos se
 * añaden a la tabla de textos compartidos como hace Excel, y a las celdas con fórmula se les deja
 * la fórmula y se les pone el RESULTADO que Excel guardaría (`Celda::calculada()`).
 *
 * ⚠️ Si la celda no existe —la plantilla de Cultura no prepara la columna Procedencia—, se crea
 * en su sitio dentro de la fila, con el estilo de su COLUMNA si lo tiene y sin estilo si no: es lo
 * que hace Excel al teclear ahí. Lo que sí tiene que existir es la FILA.
 */
final class RellenadorDePlantillaXlsx
{
    /**
     * @param array<string, Celda> $celdas referencia («K4») → valor
     *
     * @return string el .xlsx resultante, en binario
     */
    public function rellenar(string $plantilla, string $hoja, array $celdas): string
    {
        $origen = new ZipArchive();
        if ($origen->open($plantilla, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException(sprintf('No se pudo abrir la plantilla «%s».', $plantilla));
        }

        try {
            $xmlHoja = $this->leer($origen, $hoja);
            $xmlTextos = $origen->locateName('xl/sharedStrings.xml') !== false ? $this->leer($origen, 'xl/sharedStrings.xml') : null;
            $textos = new TextosCompartidos($xmlTextos);

            foreach ($celdas as $ref => $celda) {
                $xmlHoja = $this->escribirCelda($xmlHoja, $ref, $celda, $textos);
            }

            $cambiadas = [$hoja => $xmlHoja, 'xl/sharedStrings.xml' => $textos->xml()];

            if ($origen->locateName('docProps/core.xml') !== false) {
                $cambiadas['docProps/core.xml'] = $this->fechaDeModificacion($this->leer($origen, 'docProps/core.xml'));
            }

            return $this->empaquetar($origen, $cambiadas);
        } finally {
            $origen->close();
        }
    }

    /**
     * Los valores de unas columnas de una hoja, por fila: para leer las tablas de códigos que trae
     * la propia plantilla (la hoja oculta «Tablas» de Cultura). Los textos compartidos se resuelven.
     *
     * @param list<string> $columnas
     *
     * @return array<string, array<int, string|int|float>> columna → fila → valor
     */
    public function columnas(string $plantilla, string $hoja, array $columnas): array
    {
        $zip = new ZipArchive();
        if ($zip->open($plantilla, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException(sprintf('No se pudo abrir la plantilla «%s».', $plantilla));
        }

        try {
            $xml = $this->leer($zip, $hoja);
            $textos = new TextosCompartidos($zip->locateName('xl/sharedStrings.xml') !== false ? $this->leer($zip, 'xl/sharedStrings.xml') : null);
        } finally {
            $zip->close();
        }

        $salida = array_fill_keys($columnas, []);
        preg_match_all('#<c r="([A-Z]+)(\d+)"([^>]*?)(?:/>|>(.*?)</c>)#s', $xml, $m, PREG_SET_ORDER);

        foreach ($m as $c) {
            if (!in_array($c[1], $columnas, true)) {
                continue;
            }
            if (!preg_match('#<v>([^<]*)</v>#', $c[4] ?? '', $v)) {
                continue;
            }
            $crudo = html_entity_decode($v[1], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $salida[$c[1]][(int) $c[2]] = str_contains($c[3], 't="s"')
                ? $textos->texto((int) $crudo)
                : (is_numeric($crudo) ? $crudo + 0 : $crudo);
        }

        return $salida;
    }

    private function escribirCelda(string $xml, string $ref, Celda $celda, TextosCompartidos $textos): string
    {
        $patron = sprintf('#<c r="%s"([^>]*?)(/>|>(.*?)</c>)#s', preg_quote($ref, '#'));

        if (!preg_match($patron, $xml, $m, PREG_OFFSET_CAPTURE)) {
            if ($celda->tipo === TipoDeCelda::Calculada) {
                throw new RuntimeException(sprintf('La plantilla no tiene la celda %s, y una calculada necesita su fórmula.', $ref));
            }
            $xml = $this->crearCelda($xml, $ref);
            preg_match($patron, $xml, $m, PREG_OFFSET_CAPTURE);
        }

        // El estilo se queda; el tipo lo pone el valor nuevo.
        $atributos = trim((string) preg_replace('#\s*t="[^"]*"#', '', $m[1][0]));
        $atributos = $atributos === '' ? '' : ' ' . $atributos;
        $contenido = $m[3][0] ?? '';

        $nueva = match ($celda->tipo) {
            TipoDeCelda::Texto => sprintf('<c r="%s"%s t="s"><v>%d</v></c>', $ref, $atributos, $textos->indice((string) $celda->valor)),
            TipoDeCelda::Numero => sprintf('<c r="%s"%s><v>%s</v></c>', $ref, $atributos, $this->numero($celda->valor)),
            TipoDeCelda::Calculada => $this->conFormula($ref, $atributos, $contenido, $celda),
        };

        return substr_replace($xml, $nueva, (int) $m[0][1], strlen($m[0][0]));
    }

    /**
     * Mete `<c r="C4"/>` en su fila, en orden de columna, con el estilo de la columna si lo tiene.
     */
    private function crearCelda(string $xml, string $ref): string
    {
        if (!preg_match('#^([A-Z]+)(\d+)$#', $ref, $p)) {
            throw new RuntimeException(sprintf('Referencia de celda inválida: %s.', $ref));
        }
        [, $letras, $fila] = $p;
        $columna = self::indiceDeColumna($letras);

        $estilo = '';
        if (preg_match_all('#<col [^>]*min="(\d+)"[^>]*max="(\d+)"[^>]*>#', $xml, $cols, PREG_SET_ORDER)) {
            foreach ($cols as $col) {
                if ($columna >= (int) $col[1] && $columna <= (int) $col[2] && preg_match('#style="(\d+)"#', $col[0], $st)) {
                    $estilo = sprintf(' s="%s"', $st[1]);
                }
            }
        }
        $nueva = sprintf('<c r="%s"%s/>', $ref, $estilo);

        if (!preg_match(sprintf('#<row r="%s"([^>]*?)(/>|>(.*?)</row>)#s', $fila), $xml, $r, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException(sprintf('La plantilla no tiene la fila %s.', $fila));
        }

        if ($r[2][0] === '/>') {
            $filaNueva = sprintf('<row r="%s"%s>%s</row>', $fila, $r[1][0], $nueva);

            return substr_replace($xml, $filaNueva, (int) $r[0][1], strlen($r[0][0]));
        }

        // Antes de la primera celda de una columna posterior; si no hay, al final de la fila.
        $contenido = $r[3][0];
        $inicio = (int) $r[3][1];
        $posicion = $inicio + strlen($contenido);
        if (preg_match_all('#<c r="([A-Z]+)\d+"#', $contenido, $celdas, PREG_OFFSET_CAPTURE)) {
            foreach ($celdas[1] as $i => [$letrasCelda]) {
                if (self::indiceDeColumna($letrasCelda) > $columna) {
                    $posicion = $inicio + (int) $celdas[0][$i][1];
                    break;
                }
            }
        }

        return substr_replace($xml, $nueva, $posicion, 0);
    }

    private static function indiceDeColumna(string $letras): int
    {
        $n = 0;
        foreach (str_split($letras) as $letra) {
            $n = $n * 26 + (ord($letra) - 64);
        }

        return $n;
    }

    /** La fórmula se queda; su resultado guardado pasa a ser el que Excel calcularía. */
    private function conFormula(string $ref, string $atributos, string $contenido, Celda $celda): string
    {
        if (!preg_match('#<f[^>]*>.*?</f>|<f[^>]*/>#s', $contenido, $f)) {
            throw new RuntimeException(sprintf('La celda %s no tiene fórmula: no se le puede poner un resultado.', $ref));
        }

        return is_string($celda->valor)
            ? sprintf('<c r="%s"%s t="str">%s<v>%s</v></c>', $ref, $atributos, $f[0], htmlspecialchars($celda->valor, ENT_XML1 | ENT_QUOTES, 'UTF-8'))
            : sprintf('<c r="%s"%s>%s<v>%s</v></c>', $ref, $atributos, $f[0], $this->numero($celda->valor));
    }

    private function numero(string|int|float $valor): string
    {
        return is_string($valor) ? $valor : (string) (is_float($valor) ? rtrim(rtrim(sprintf('%.10F', $valor), '0'), '.') : $valor);
    }

    /** Excel la pone al guardar; un libro «tocado hoy» con la fecha de la plantilla es lo raro. */
    private function fechaDeModificacion(string $core): string
    {
        $ahora = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        return (string) preg_replace(
            '#(<dcterms:modified[^>]*>)[^<]*(</dcterms:modified>)#',
            '${1}' . $ahora . '${2}',
            $core
        );
    }

    /**
     * Un .xlsx nuevo con las MISMAS partes, en el MISMO orden; sólo cambian las de `$cambiadas`.
     *
     * @param array<string, string> $cambiadas
     */
    private function empaquetar(ZipArchive $origen, array $cambiadas): string
    {
        $temporal = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($temporal === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal.');
        }

        $destino = new ZipArchive();
        if ($destino->open($temporal, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo escribir el archivo.');
        }

        for ($i = 0; $i < $origen->numFiles; ++$i) {
            $nombre = $origen->getNameIndex($i);
            if ($nombre === false) {
                continue;
            }

            $destino->addFromString($nombre, $cambiadas[$nombre] ?? $this->leerIndice($origen, $i, $nombre));
            $destino->setCompressionName($nombre, ZipArchive::CM_DEFLATE);
        }

        $destino->close();
        $binario = file_get_contents($temporal);
        unlink($temporal);

        if ($binario === false) {
            throw new RuntimeException('No se pudo leer el archivo generado.');
        }

        return $binario;
    }

    private function leer(ZipArchive $zip, string $nombre): string
    {
        $contenido = $zip->getFromName($nombre);
        if ($contenido === false) {
            throw new RuntimeException(sprintf('La plantilla no tiene «%s».', $nombre));
        }

        return $contenido;
    }

    private function leerIndice(ZipArchive $zip, int $i, string $nombre): string
    {
        $contenido = $zip->getFromIndex($i);
        if ($contenido === false) {
            throw new RuntimeException(sprintf('No se pudo leer «%s» de la plantilla.', $nombre));
        }

        return $contenido;
    }
}
