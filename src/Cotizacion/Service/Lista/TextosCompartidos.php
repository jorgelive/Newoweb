<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Lista;

/**
 * La tabla `xl/sharedStrings.xml` de una plantilla, a la que se añaden textos como lo hace Excel.
 *
 * Excel no repite un texto: si «Perú» ya está en la tabla —en la hoja de códigos, por ejemplo—, la
 * celda nueva apunta a ése. Y lleva dos cuentas en la cabecera: `count` (cuántas celdas apuntan a
 * algún texto) y `uniqueCount` (cuántos textos distintos hay). Las dos se actualizan.
 */
final class TextosCompartidos
{
    /** @var list<string> el contenido de cada `<si>`, tal cual */
    private array $si = [];

    /** @var array<string, int> texto plano → índice, sólo de los `<si>` simples */
    private array $indices = [];

    private int $nuevasReferencias = 0;

    private string $cabecera = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    private string $apertura = '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="0" uniqueCount="0">';

    public function __construct(?string $xml)
    {
        if ($xml === null) {
            return;
        }

        if (preg_match('#^(.*?)(<sst\b[^>]*>)#s', $xml, $m)) {
            $this->cabecera = $m[1];
            $this->apertura = $m[2];
        }

        preg_match_all('#<si>(.*?)</si>#s', $xml, $todos);
        foreach ($todos[1] as $i => $contenido) {
            $this->si[] = $contenido;
            // Sólo los simples (`<t>…</t>`): un texto con formato enriquecido no es «el mismo».
            if (preg_match('#^<t(?: [^>]*)?>([^<]*)</t>$#', $contenido, $t)) {
                $this->indices[html_entity_decode($t[1], ENT_XML1 | ENT_QUOTES, 'UTF-8')] ??= $i;
            }
        }
    }

    /**
     * El texto listo para ir dentro de un elemento XML del `.xlsx`.
     *
     * ⚠️ `htmlspecialchars()` escapa `<>&"'`, pero XML 1.0 **no admite** los caracteres de control
     * (`\x00`–`\x08`, `\x0B`, `\x0C`, `\x0E`–`\x1F`), y un nombre que llegue así de un OCR o de un
     * pegado deja un fichero que Excel declara dañado (revisión del 19/10/2026). Se quitan; y un
     * byte que no sea UTF-8 válido se sustituye en vez de vaciar la celda entera, que es lo que
     * hace `htmlspecialchars()` sin `ENT_SUBSTITUTE`.
     */
    public static function paraXml(string $texto): string
    {
        $limpio = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $texto);

        return htmlspecialchars($limpio, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function indice(string $texto): int
    {
        ++$this->nuevasReferencias;

        if (isset($this->indices[$texto])) {
            return $this->indices[$texto];
        }

        $espacio = $texto !== trim($texto) ? ' xml:space="preserve"' : '';
        $this->si[] = sprintf('<t%s>%s</t>', $espacio, self::paraXml($texto));

        return $this->indices[$texto] = count($this->si) - 1;
    }

    public function texto(int $indice): string
    {
        preg_match_all('#<t(?: [^>]*)?>([^<]*)</t>#', $this->si[$indice] ?? '', $t);

        return html_entity_decode(implode('', $t[1]), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public function xml(): string
    {
        $count = (int) (preg_match('#count="(\d+)"#', $this->apertura, $c) ? $c[1] : 0) + $this->nuevasReferencias;

        $apertura = (string) preg_replace('#\bcount="\d+"#', sprintf('count="%d"', $count), $this->apertura);
        $apertura = (string) preg_replace('#\buniqueCount="\d+"#', sprintf('uniqueCount="%d"', count($this->si)), $apertura);

        return $this->cabecera . $apertura
            . implode('', array_map(static fn (string $s): string => '<si>' . $s . '</si>', $this->si))
            . '</sst>';
    }
}
