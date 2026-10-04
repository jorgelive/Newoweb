<?php

declare(strict_types=1);

namespace App\Tests\Message\Entity;

use App\Message\Entity\MessageTemplate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * La sincronización con Meta enciende `is_active` en toda plantilla que Meta devuelve: una
 * archivada que siguiera en Meta volvía a circular cada noche. La marca manda sobre los canales.
 */
#[CoversClass(MessageTemplate::class)]
final class MessageTemplateArchivadaTest extends TestCase
{
    public function testArchivadaNoCirculaAunqueUnCanalDigaActivo(): void
    {
        $plantilla = (new MessageTemplate())->setWhatsappMetaTmpl(['is_active' => true]);
        self::assertTrue($plantilla->estaEnCirculacion());

        $plantilla->setArchivada(true);
        self::assertFalse($plantilla->estaEnCirculacion());
    }
}
