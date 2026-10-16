<?php

declare(strict_types=1);

namespace App\Tests\Front\Comun;

use App\Front\Comun\Entity\ExperienciaAlbum;
use App\Front\Comun\Entity\ExperienciaMedio;
use App\Front\Comun\Enum\ExperienciaMedioTipoEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Lo que un medio de álbum no puede ser: huérfano, o un vídeo sin enlace reproducible. */
#[CoversClass(ExperienciaMedio::class)]
final class ExperienciaMedioValidacionTest extends TestCase
{
    /**
     * Sin esto, la columna `album_id NOT NULL` lo paraba en MySQL y el alta devolvía un 500 en
     * vez de decir qué falta. Mismo caso que `CotizacionPago::$file`.
     */
    public function testUnMedioSinAlbumNoPasa(): void
    {
        self::assertSame(['album'], $this->rutasConError(new ExperienciaMedio()));

        $medio = (new ExperienciaMedio())->setAlbum(new ExperienciaAlbum());
        self::assertSame([], $this->rutasConError($medio));
    }

    public function testUnVideoSinEnlaceDeYoutubeOVimeoNoPasa(): void
    {
        $medio = (new ExperienciaMedio())
            ->setAlbum(new ExperienciaAlbum())
            ->setTipo(ExperienciaMedioTipoEnum::VIDEO);

        self::assertSame(['videoUrl'], $this->rutasConError($medio));

        $medio->setVideoUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        self::assertSame([], $this->rutasConError($medio));
    }

    /** @return list<string> */
    private function rutasConError(ExperienciaMedio $medio): array
    {
        $rutas = [];
        foreach ($this->validador()->validate($medio) as $violacion) {
            $rutas[] = $violacion->getPropertyPath();
        }

        return array_values(array_unique($rutas));
    }

    private function validador(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }
}
