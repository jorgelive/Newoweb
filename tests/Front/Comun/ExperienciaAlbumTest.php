<?php

declare(strict_types=1);

namespace App\Tests\Front\Comun;

use App\Front\Comun\Entity\ExperienciaAlbum;
use App\Front\Comun\Entity\ExperienciaMedio;
use App\Front\Comun\Enum\ExperienciaMedioTipoEnum;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Validation;

/**
 * Lo que decide un álbum de experiencias: cuándo se puede publicar y cuál es su portada. Ver
 * `ExperienciaAlbum`: son fotos de viajes escolares, con menores.
 */
final class ExperienciaAlbumTest extends TestCase
{
    /** @return list<string> Los mensajes del validador de publicación (sin `UniqueEntity`, que pide contenedor). */
    private function errores(ExperienciaAlbum $album): array
    {
        $soloPublicacion = new Callback(static function (mixed $a, ExecutionContextInterface $c): void {
            if ($a instanceof ExperienciaAlbum) {
                $a->validarPublicacion($c);
            }
        });

        $mensajes = [];
        foreach (Validation::createValidator()->validate($album, $soloPublicacion) as $v) {
            $mensajes[] = (string) $v->getMessage();
        }

        return $mensajes;
    }

    #[Test]
    public function sin_autorizacion_no_se_publica(): void
    {
        $album = (new ExperienciaAlbum())->setSlug('promo-2026')->setPublicado(true);

        self::assertCount(1, $this->errores($album));
        self::assertFalse($album->esVisibleEnLaWeb());
    }

    #[Test]
    public function sin_direccion_no_se_publica(): void
    {
        $album = (new ExperienciaAlbum())->setAutorizado(true)->setPublicado(true);

        self::assertCount(1, $this->errores($album));
    }

    #[Test]
    public function autorizado_y_con_direccion_se_publica(): void
    {
        $album = (new ExperienciaAlbum())->setAutorizado(true)->setSlug('promo-2026')->setPublicado(true);

        self::assertSame([], $this->errores($album));
        self::assertTrue($album->esVisibleEnLaWeb());
    }

    #[Test]
    public function un_borrador_no_pide_nada(): void
    {
        self::assertSame([], $this->errores(new ExperienciaAlbum()));
    }

    #[Test]
    public function la_portada_es_la_marcada_o_la_primera_foto(): void
    {
        $album = new ExperienciaAlbum();
        $video = (new ExperienciaMedio())->setTipo(ExperienciaMedioTipoEnum::VIDEO)->setVideoUrl('https://youtu.be/dQw4w9WgXcQ');
        $primera = (new ExperienciaMedio())->setImageName('a.webp');
        $segunda = (new ExperienciaMedio())->setImageName('b.webp');
        $album->addMedio($video)->addMedio($primera)->addMedio($segunda);

        self::assertSame($primera, $album->portada(), 'Sin marcar, la primera FOTO (el vídeo no cuenta).');

        $segunda->setEsPortada(true);
        self::assertSame($segunda, $album->portada());
    }
}
