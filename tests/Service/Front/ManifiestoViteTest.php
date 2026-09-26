<?php

declare(strict_types=1);

namespace App\Tests\Service\Front;

use App\Service\Front\ManifiestoVite;
use PHPUnit\Framework\TestCase;

final class ManifiestoViteTest extends TestCase
{
    public function testLeeElFicheroYElCssDeUnaEntrada(): void
    {
        $m = ManifiestoVite::desdeJson((string) json_encode([
            'src/main.ts' => ['file' => 'assets/main-abc123.js', 'src' => 'src/main.ts', 'isEntry' => true, 'css' => ['assets/main-def.css']],
            '_vendor.js' => ['file' => 'assets/vendor-999.js'],
        ]));

        self::assertSame(['file' => 'assets/main-abc123.js', 'css' => ['assets/main-def.css']], $m->entrada('src/main.ts'));
        self::assertSame(['file' => 'assets/vendor-999.js', 'css' => []], $m->entrada('_vendor.js'));
        self::assertSame(['src/main.ts', '_vendor.js'], $m->claves());
    }

    /** Una entrada sin `file` era un `null` en la plantilla: una página en blanco sin error. */
    public function testUnaEntradaSinFicheroNoExiste(): void
    {
        $m = ManifiestoVite::desdeJson('{"src/main.ts": {"css": ["a.css"]}}');

        self::assertNull($m->entrada('src/main.ts'));
        self::assertSame([], $m->claves());
    }

    public function testUnManifestIlegibleEstaVacio(): void
    {
        self::assertSame([], ManifiestoVite::desdeJson('no es json')->claves());
    }
}
