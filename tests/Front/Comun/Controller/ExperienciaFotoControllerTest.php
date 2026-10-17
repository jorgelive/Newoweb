<?php

declare(strict_types=1);

namespace App\Tests\Front\Comun\Controller;

use App\Entity\User;
use App\Front\Comun\Controller\ExperienciaFotoController;
use App\Front\Comun\Entity\ExperienciaAlbum;
use App\Front\Comun\Entity\ExperienciaMedio;
use App\Front\Comun\Service\FotosDeExperiencia;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Liip\ImagineBundle\Imagine\Filter\FilterManager;
use Liip\ImagineBundle\Model\Binary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Quién ve una foto de álbum de experiencias.
 *
 * Desde el 17/10/2026 viven fuera de `public/` y sólo las entrega este controlador: el público ve
 * las de un álbum publicado y autorizado; el operador con sesión, todas; el resto, 404. Lo que
 * importa es que revocar la autorización de las familias corte la foto en el acto.
 * Ver docs/WebPublica.md §2.1.a.
 */
final class ExperienciaFotoControllerTest extends TestCase
{
    private string $raiz;

    protected function setUp(): void
    {
        $this->raiz = sys_get_temp_dir() . '/fotos_exp_' . bin2hex(random_bytes(4));
        mkdir($this->raiz . '/var/documentos/experiencias', 0775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->raiz));
    }

    /** @return iterable<string, array{bool, bool, bool, int, ?string}> */
    public static function casos(): iterable
    {
        //                                 publicado autorizado operador  código  caché
        yield 'publicado y autorizado'   => [true,  true,  false, 200, 'max-age=300, public'];
        yield 'sin autorización'         => [true,  false, false, 404, null];
        yield 'sin publicar'             => [false, true,  false, 404, null];
        yield 'operador, sin publicar'   => [false, false, true,  200, 'no-cache, private'];
    }

    #[Test]
    #[DataProvider('casos')]
    public function decide_quien_la_ve(bool $publicado, bool $autorizado, bool $operador, int $codigo, ?string $cache): void
    {
        $album = (new ExperienciaAlbum())->setSlug('viaje')->setPublicado($publicado)->setAutorizado($autorizado);
        $medio = (new ExperienciaMedio())->setAlbum($album)->setImageName('TOKEN_foto.webp');
        file_put_contents($this->raiz . '/var/documentos/experiencias/TOKEN_foto.webp', 'original');

        $respuesta = $this->controlador($medio, $operador)->__invoke((string) $medio->getId(), 'miniatura');

        self::assertSame($codigo, $respuesta->getStatusCode());
        if ($codigo === 200) {
            self::assertSame('/_privado/experiencias/tamanos/miniatura/TOKEN_foto.webp', $respuesta->headers->get('X-Accel-Redirect'));
            self::assertSame('image/webp', $respuesta->headers->get('Content-Type'));
            self::assertSame($cache, $respuesta->headers->get('Cache-Control'));
            self::assertFileExists($this->raiz . '/var/documentos/experiencias/tamanos/miniatura/TOKEN_foto.webp', 'el tamaño se genera en la carpeta privada');
        } else {
            self::assertNull($respuesta->headers->get('X-Accel-Redirect'));
        }
    }

    #[Test]
    public function sin_original_en_disco_da_404_y_no_404_de_nginx(): void
    {
        $album = (new ExperienciaAlbum())->setSlug('viaje')->setPublicado(true)->setAutorizado(true);
        $medio = (new ExperienciaMedio())->setAlbum($album)->setImageName('NO_EXISTE.webp');

        self::assertSame(404, $this->controlador($medio, false)->__invoke((string) $medio->getId(), 'grande')->getStatusCode());
    }

    private function controlador(ExperienciaMedio $medio, bool $operador): ExperienciaFotoController
    {
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('find')->willReturn($medio);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $filtros = $this->createStub(FilterManager::class);
        $filtros->method('applyFilter')->willReturn(new Binary('reducida', 'image/webp', 'webp'));

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($operador ? new User() : null);

        return new ExperienciaFotoController($em, new FotosDeExperiencia($filtros, $this->raiz), $security);
    }
}
