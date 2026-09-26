<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Dto;

use App\Exchange\Dto\Meta\RespuestaGraphMeta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RespuestaGraphMeta::class)]
final class RespuestaGraphMetaTest extends TestCase
{
    public function testUnEnvioAceptadoTraeElWamid(): void
    {
        $r = RespuestaGraphMeta::fromArray([
            'messaging_product' => 'whatsapp',
            'contacts' => [['input' => '51900000000', 'wa_id' => '51900000000']],
            'messages' => [['id' => 'wamid.ABC']],
        ]);

        self::assertFalse($r->hayError);
        self::assertSame('wamid.ABC', $r->idMensaje);
    }

    public function testUnRechazoTraeMensajeCodigoYElTextoLegible(): void
    {
        $r = RespuestaGraphMeta::fromArray(['error' => [
            'message' => '(#132005) Translated text too long',
            'code' => 132005,
            'error_user_msg' => 'El texto es demasiado largo',
            'error_data' => ['details' => 'body > 1024'],
        ]]);

        self::assertTrue($r->hayError);
        self::assertSame('(#132005) Translated text too long', $r->errorMensaje);
        // Sin convertir: va a la fila normalizada como número, como siempre.
        self::assertSame(132005, $r->errorCodigo);
        self::assertSame('El texto es demasiado largo', $r->errorMensajeUsuario);
        self::assertSame('body > 1024', $r->errorDetalles);
        self::assertNull($r->idMensaje);
    }

    public function testUnCuerpoQueNoEsJsonNoTraeNada(): void
    {
        $r = RespuestaGraphMeta::deCuerpo('<html>502 Bad Gateway</html>');

        self::assertFalse($r->hayError);
        self::assertNull($r->errorMensaje);
        self::assertSame([], $r->crudo);
    }
}
