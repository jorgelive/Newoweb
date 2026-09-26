<?php

declare(strict_types=1);

namespace App\Tests\Api\Dto;

use App\Api\Dto\CuerpoDeSuscripcionPush;
use PHPUnit\Framework\TestCase;

final class CuerpoDeSuscripcionPushTest extends TestCase
{
    public function testLasClavesSeLeenTalCual(): void
    {
        $c = CuerpoDeSuscripcionPush::fromArray([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
            'p256dh' => 'BNc…=',
            'auth' => 'tBHI…',
        ]);

        self::assertSame('https://fcm.googleapis.com/fcm/send/abc', $c->endpoint);
        self::assertSame('BNc…=', $c->p256dh);
        self::assertSame('tBHI…', $c->auth);
    }

    /** Antes un array se guardaba como la palabra «Array»: una clave de cifrado que no descifra nada. */
    public function testLoQueNoEsTextoONoVieneEsQueFalta(): void
    {
        $c = CuerpoDeSuscripcionPush::fromArray(['endpoint' => ['x'], 'p256dh' => '', 'auth' => null]);

        self::assertNull($c->endpoint);
        self::assertNull($c->p256dh);
        self::assertNull($c->auth);
    }
}
