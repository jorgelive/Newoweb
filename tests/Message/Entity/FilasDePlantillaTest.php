<?php

declare(strict_types=1);

namespace App\Tests\Message\Entity;

use App\Message\Entity\MessageTemplate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Lo que el CollectionField de EasyAdmin le da a los setters de `MessageTemplate`, guardado con la
 * forma que el tipo promete. Antes era un `(array) $item` sin mirar.
 */
#[CoversClass(MessageTemplate::class)]
final class FilasDePlantillaTest extends TestCase
{
    /**
     * `origenHash` es la huella de la autotraducción: el tipo no la declaraba, y un lector que
     * se quedara sólo con lo declarado la habría borrado al guardar — y retraducido todo.
     */
    public function testUnaFilaTraducidaConservaSuHuella(): void
    {
        $plantilla = new MessageTemplate();
        $plantilla->setWhatsappMetaBodies([
            (object) ['language' => 'es', 'content' => 'Hola', 'origenHash' => 'abc', 'status' => 'auto'],
            ['language' => 'en', 'content' => null],
        ]);

        self::assertSame(
            [
                ['language' => 'es', 'content' => 'Hola', 'status' => 'auto', 'origenHash' => 'abc'],
                ['language' => 'en', 'content' => null],
            ],
            $plantilla->getWhatsappMetaTmpl()['body'] ?? null,
        );
    }

    public function testUnBotonConservaSuIndiceYSusTextos(): void
    {
        $plantilla = new MessageTemplate();
        $plantilla->setWhatsappMetaButtonsMap([
            ['type' => 'url', 'index' => 0, 'resolver_key' => 'enlace_pago', 'content' => null, 'button_text' => [['language' => 'es', 'content' => 'Pagar']]],
        ]);

        self::assertSame(
            [['type' => 'url', 'resolver_key' => 'enlace_pago', 'content' => null, 'index' => 0, 'button_text' => [['language' => 'es', 'content' => 'Pagar']]]],
            $plantilla->getWhatsappMetaTmpl()['buttons_map'] ?? null,
        );
    }
}
