<?php

declare(strict_types=1);

namespace App\Tests\Message\Service\Exchange\Tasks\WhatsappMetaSend;

use App\Exchange\Service\Client\WhatsappMetaClient;
use App\Message\Entity\WhatsappMetaSendQueue;
use App\Message\Service\Exchange\Tasks\WhatsappMetaSend\WhatsappMetaSendHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Qué se reintenta cuando un envío falla. Un rechazo de Meta no envió nada: reintentarlo no
 * duplica. Un corte o una respuesta ilegible no dicen si salió: reintentar puede duplicarle el
 * mensaje al huésped, así que se agotan los intentos y decide el operador.
 */
#[CoversClass(WhatsappMetaSendHandler::class)]
final class WhatsappMetaSendHandlerTest extends TestCase
{
    public function testUnRechazoDeMetaSeReintenta(): void
    {
        $cola = new WhatsappMetaSendQueue();
        $this->handler()->handleFailure(new \RuntimeException('[Meta 131000] Something went wrong'), $cola);

        self::assertSame(1, $cola->getRetryCount());
        self::assertLessThan($cola->getMaxAttempts(), $cola->getRetryCount());
    }

    public function testLoQueNoSeSabeSiSalioNoSeReintenta(): void
    {
        $cola = new WhatsappMetaSendQueue();
        $this->handler()->handleFailure(new \RuntimeException(WhatsappMetaClient::SIN_CONFIRMACION . 'HTTP Exception: timeout'), $cola);

        self::assertSame($cola->getMaxAttempts(), $cola->getRetryCount());
    }

    private function handler(): WhatsappMetaSendHandler
    {
        // Sin mensaje en la cola, `handleFailure()` no toca sus dependencias.
        return (new \ReflectionClass(WhatsappMetaSendHandler::class))->newInstanceWithoutConstructor();
    }
}
