<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Enum;

use App\Cotizacion\Enum\ArchivoTipoEnum;
use PHPUnit\Framework\TestCase;

/**
 * El espejo PHP ↔ TypeScript de «qué documentos se le pueden pedir al pasajero».
 *
 * ⚠️ **Este test existe porque el espejo ya estaba roto y nadie se enteró.** `autorizacion` era
 * pedible en el panel —salía en el selector de «Qué se pide»— y NO estaba en el catálogo de `pax`.
 * `documentosPedidos()` filtra el catálogo, así que pedirla no producía ningún error: producía una
 * casilla que el pasajero nunca veía, un «Falta documento» en el manifiesto para las 133 personas,
 * y ninguna forma de resolverlo desde la app.
 *
 * ⚠️ Se lee el `.vue` como TEXTO a propósito. La alternativa —confiar en que alguien se acuerde de
 * tocar los dos archivos— es justo lo que falló, y montar Node desde PHPUnit para esto cuesta más
 * que lo que protege. Si el catálogo cambia de forma, este test se cae y se lee: es lo que se
 * quiere, porque entonces hay que revisar el espejo de todas formas.
 */
final class ArchivoTipoPedibleEspejoTest extends TestCase
{
    private const string CATALOGO = __DIR__.'/../../../pax/src/components/cotizacion/MisDocumentos.vue';

    public function testTodoLoPedibleTieneCasillaEnLaAppDelPasajero(): void
    {
        $fuente = file_get_contents(self::CATALOGO);

        self::assertIsString($fuente, 'No se pudo leer el catálogo de pax.');

        foreach (ArchivoTipoEnum::pedibles() as $tipo) {
            self::assertStringContainsString(
                sprintf("tipo: '%s'", $tipo),
                $fuente,
                sprintf(
                    'El panel puede pedir «%s» y el catálogo de `pax` no lo tiene: el pasajero no '
                    .'vería la casilla y no podría mandarlo nunca.',
                    $tipo,
                ),
            );
        }
    }

    /**
     * Y al revés: una entrada del catálogo que el back no considere pedible es una casilla que
     * nadie puede marcar. No rompe nada, pero es trabajo pintado para nada y se nota antes aquí.
     */
    public function testElCatalogoNoOfreceNadaQueNoSePuedaPedir(): void
    {
        $fuente = (string) file_get_contents(self::CATALOGO);

        // Sólo el bloque del catálogo: más abajo hay `PEDIDOS_POR_DEFECTO` y la plantilla.
        $desde = strpos($fuente, 'export const CATALOGO_DOCUMENTOS');
        $hasta = strpos($fuente, 'export type TipoDoc');

        self::assertIsInt($desde);
        self::assertIsInt($hasta);

        preg_match_all("/tipo: '([a-z_]+)'/", substr($fuente, $desde, $hasta - $desde), $encontrados);

        self::assertNotEmpty($encontrados[1], 'El catálogo no tiene ni una entrada: cambió de forma.');

        foreach ($encontrados[1] as $tipo) {
            self::assertContains($tipo, ArchivoTipoEnum::pedibles(), sprintf('«%s» no es pedible.', $tipo));
        }
    }
}
