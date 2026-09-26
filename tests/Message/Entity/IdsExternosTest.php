<?php

declare(strict_types=1);

namespace App\Tests\Message\Entity;

use App\Message\Entity\Message;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Los ids externos de un mensaje son un mapa de TEXTO: poner uno a `null` lo quita.
 *
 * Antes quedaba `"beds24": null` dentro del JSON, contradiciendo el tipo del propio mapa. Nadie lo
 * leía distinto —los getters usan `??` y `JSON_MERGE_PATCH` ya borra las claves nulas—, así que
 * quitarla no cambia ninguna lectura; sólo deja de mentir. Salió al subir PHPStan al nivel 8.
 */
#[CoversClass(Message::class)]
final class IdsExternosTest extends TestCase
{
    public function testUnIdNuloQuitaLaClaveEnVezDeGuardarNull(): void
    {
        $mensaje = (new Message())->setBeds24ExternalId('123')->setWhatsappMetaExternalId('wamid.X');

        $mensaje->setBeds24ExternalId(null);

        self::assertSame(['whatsapp_meta' => 'wamid.X'], $mensaje->getExternalIds());
        self::assertNull($mensaje->getBeds24ExternalId());
    }

    public function testPonerYQuitarElDeWhatsappDejaElMapaVacio(): void
    {
        $mensaje = (new Message())->setWhatsappMetaExternalId('wamid.X')->setWhatsappMetaExternalId(null);

        self::assertSame([], $mensaje->getExternalIds());
    }

    /** Un mensaje guardado siempre tiene hilo; uno a medio construir lo dice con nombre. */
    public function testSinConversacionFallaDiciendoloPorSuNombre(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Mensaje sin conversación/');

        (new Message())->getConversationOrFail();
    }
}
