<?php

declare(strict_types=1);

namespace App\Tests\Front\Comun;

use App\Front\Comun\Dto\VideoExterno;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Los enlaces de vídeo que acepta un álbum de experiencias, y lo que la web hace con ellos. */
final class VideoExternoTest extends TestCase
{
    #[Test]
    public function lee_todas_las_formas_de_un_enlace_de_youtube(): void
    {
        foreach ([
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtube.com/watch?feature=share&v=dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ?si=abc123',
            'https://www.youtube.com/shorts/dQw4w9WgXcQ',
            'https://m.youtube.com/watch?v=dQw4w9WgXcQ&t=42s',
            'https://www.youtube.com/live/dQw4w9WgXcQ',
        ] as $url) {
            $v = VideoExterno::desdeUrl($url);
            self::assertNotNull($v, $url);
            self::assertSame('youtube', $v->proveedor);
            self::assertSame('dQw4w9WgXcQ', $v->id, $url);
        }
    }

    #[Test]
    public function youtube_se_reproduce_sin_cookies_y_tiene_miniatura(): void
    {
        $v = VideoExterno::desdeUrl('https://youtu.be/dQw4w9WgXcQ');

        self::assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0', $v?->reproductor());
        self::assertSame('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $v?->miniatura());
    }

    #[Test]
    public function vimeo_publico_y_oculto_con_su_hash(): void
    {
        $publico = VideoExterno::desdeUrl('https://vimeo.com/76979871');
        $oculto = VideoExterno::desdeUrl('https://vimeo.com/76979871/8a7c9d1e2f');

        self::assertSame('https://player.vimeo.com/video/76979871?autoplay=1', $publico?->reproductor());
        self::assertSame('https://player.vimeo.com/video/76979871?autoplay=1&h=8a7c9d1e2f', $oculto?->reproductor(), 'Un vídeo oculto de Vimeo no se reproduce sin su hash.');
        self::assertNull($publico?->miniatura(), 'Vimeo no da miniatura sin llamar a su API.');
    }

    #[Test]
    public function lo_demas_no_es_un_video(): void
    {
        foreach (['', 'no es un enlace', 'https://www.instagram.com/reel/abc', 'https://www.youtube.com/', 'https://youtube.com/watch?v=corto', 'javascript:alert(1)', 'https://evil.com/youtu.be/dQw4w9WgXcQ'] as $url) {
            self::assertNull(VideoExterno::desdeUrl($url), $url);
        }
    }
}
