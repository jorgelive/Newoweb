<?php

declare(strict_types=1);

namespace App\Tests\Message\Entity;

use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageIdentidad;
use App\Message\Enum\IdentidadTipo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Una conversación con varios identificadores: el eje que faltaba. */
final class MessageIdentidadTest extends TestCase
{
    #[Test]
    public function elValorSeGuardaYaNormalizado(): void
    {
        $identidad = new MessageIdentidad(IdentidadTipo::EMAIL, '  Nune@Ejemplo.COM ');

        // Normalizar al construir es lo que impide que entre un valor crudo por descuido: no
        // hay forma de crear una identidad sin pasar por aquí.
        self::assertSame('nune@ejemplo.com', $identidad->getValor());
    }

    #[Test]
    public function unaPersonaPuedeTenerTelefonoYCorreo(): void
    {
        $hilo = new MessageConversation('manual', '+51984123456');

        $hilo->addIdentidad(new MessageIdentidad(IdentidadTipo::TELEFONO, '+51 984 123 456'));
        $hilo->addIdentidad(new MessageIdentidad(IdentidadTipo::EMAIL, 'nune@ejemplo.com'));

        self::assertCount(2, $hilo->getIdentidades());
    }

    /** Y varios del mismo tipo: «a veces contestan desde otro correo». */
    #[Test]
    public function unaPersonaPuedeTenerDosCorreos(): void
    {
        $hilo = new MessageConversation('manual', 'x');

        $hilo->addIdentidad(new MessageIdentidad(IdentidadTipo::EMAIL, 'nune@ejemplo.com'));
        $hilo->addIdentidad(new MessageIdentidad(IdentidadTipo::EMAIL, 'nune.asatryan@trabajo.com'));

        self::assertCount(2, $hilo->getIdentidades());
    }

    /**
     * Idempotente: se llama en cada mensaje entrante. Si no lo fuera, un hilo activo acumularía
     * una fila por mensaje y el índice único reventaría al guardar.
     */
    #[Test]
    public function anadirDosVecesElMismoNoDuplica(): void
    {
        $hilo = new MessageConversation('manual', 'x');

        $hilo->addIdentidad(new MessageIdentidad(IdentidadTipo::TELEFONO, '+51984123456'));
        $hilo->addIdentidad(new MessageIdentidad(IdentidadTipo::TELEFONO, '+51984123456'));

        self::assertCount(1, $hilo->getIdentidades());
    }

    /** Y tampoco si llega escrito de otra forma: se compara ya normalizado. */
    #[Test]
    public function elMismoValorEscritoDistintoTampocoDuplica(): void
    {
        $hilo = new MessageConversation('manual', 'x');

        $hilo->addIdentidad(new MessageIdentidad(IdentidadTipo::EMAIL, 'nune@ejemplo.com'));
        $hilo->addIdentidad(new MessageIdentidad(IdentidadTipo::EMAIL, '  NUNE@Ejemplo.com  '));

        self::assertCount(1, $hilo->getIdentidades());
    }

    /** El mismo valor con otro tipo sí es otra identidad: no se comparan entre sí. */
    #[Test]
    public function mismoValorEnTiposDistintosSonDos(): void
    {
        $hilo = new MessageConversation('manual', 'x');

        $hilo->addIdentidad(new MessageIdentidad(IdentidadTipo::TELEFONO, '123456789'));
        $hilo->addIdentidad(new MessageIdentidad(IdentidadTipo::EMAIL, '123456789'));

        self::assertCount(2, $hilo->getIdentidades());
    }

    #[Test]
    public function laIdentidadApuntaASuHilo(): void
    {
        $hilo = new MessageConversation('manual', 'x');
        $identidad = new MessageIdentidad(IdentidadTipo::TELEFONO, '+51984123456');

        $hilo->addIdentidad($identidad);

        self::assertSame($hilo, $identidad->getConversacion());
    }
}
