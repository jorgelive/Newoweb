<?php

declare(strict_types=1);

namespace App\Tests\Message\Entity;

use App\Contract\ConversationMilestoneInterface as Hito;
use App\Message\Entity\MessageRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * El desfase mínimo de las reglas colgadas del hito de CREACIÓN.
 *
 * Con 0, `MessageRuleEngine` programa el mensaje con `runAt = now` en el mismo `postFlush` en que
 * entra la reserva, y la bienvenida compite con lo que arregla sus datos —el nombre en
 * mayúsculas, el nombre y el apellido cruzados—. Un minuto convierte esa carrera en un horario.
 *
 * Unitario puro: el contexto de validación va simulado, sin contenedor.
 */
final class MessageRuleOffsetTest extends TestCase
{
    /** @return iterable<string, array{string, int, bool}> */
    public static function reglas(): iterable
    {
        yield 'creación con 0 se rechaza'    => [Hito::CREATED, 0, true];
        yield 'creación en negativo también' => [Hito::CREATED, -30, true];
        yield 'creación con el mínimo pasa'  => [Hito::CREATED, 1, false];
        yield 'creación con 5 pasa'          => [Hito::CREATED, 5, false];

        // Los demás hitos cuelgan de fechas que existían mucho antes: no compiten con nada.
        yield 'inicio con 0 pasa'            => [Hito::START, 0, false];
        yield 'inicio muy anticipado pasa'   => [Hito::START, -1800, false];
        yield 'fin con 60 pasa'              => [Hito::END, 60, false];
        yield 'cancelación con 0 pasa'       => [Hito::CANCELLED, 0, false];
    }

    #[Test]
    #[DataProvider('reglas')]
    public function solo_el_hito_de_creacion_exige_desfase(string $hito, int $offset, bool $rechaza): void
    {
        $constructor = $this->createMock(ConstraintViolationBuilderInterface::class);
        $constructor->method('setParameter')->willReturnSelf();
        $constructor->method('atPath')->willReturnSelf();
        $constructor->expects($rechaza ? self::once() : self::never())->method('addViolation');

        $contexto = $this->createMock(ExecutionContextInterface::class);
        $contexto->expects($rechaza ? self::once() : self::never())
            ->method('buildViolation')
            ->willReturn($constructor);

        (new MessageRule())
            ->setMilestone($hito)
            ->setOffsetMinutes($offset)
            ->validarDesfaseDeCreacion($contexto);
    }

    #[Test]
    public function el_minimo_es_un_minuto(): void
    {
        // La migración Version20260819100000 escribe este mismo número. Si cambia aquí, hay que
        // decidir qué pasa con las reglas ya guardadas.
        self::assertSame(1, MessageRule::OFFSET_MINIMO_EN_CREACION);
    }
}
