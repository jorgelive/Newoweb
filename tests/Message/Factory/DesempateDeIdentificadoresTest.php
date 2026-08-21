<?php

declare(strict_types=1);

namespace App\Tests\Message\Factory;

use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageIdentidad;
use App\Message\Enum\IdentidadTipo;
use App\Message\Factory\MessageConversationFactory;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use App\Message\Service\Conversacion\ResolutorDeHilo;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Cuando los identificadores de una reserva apuntan a personas distintas.
 *
 * Pasa al crear: se teclea un teléfono que ya es de alguien y un correo que es de otra persona.
 *
 * ── Por qué NO se puede dejar sin elegir ────────────────────────────────────
 * Era lo que hacía antes, y el resultado era peor que cualquiera de las dos opciones: nacía un
 * hilo **sin identidades** —las dos eran ajenas, y `vincular()` no se las quita a su dueño— pero
 * **con `guestPhone`**. Los envíos salían por ese número y las respuestas aterrizaban en el hilo
 * del dueño: conversación partida y de una sola dirección en cada mitad, sin que nada lo dijera.
 *
 * Manda el TELÉFONO, que es el canal que de verdad lleva conversación. El correo se descarta.
 */
final class DesempateDeIdentificadoresTest extends TestCase
{
    #[Test]
    public function con_telefono_de_uno_y_correo_de_otro_manda_el_telefono(): void
    {
        $deSusan = $this->hilo('Susan');

        self::assertSame($deSusan, $this->resolver(
            [IdentidadTipo::TELEFONO->value => '51984111111', IdentidadTipo::EMAIL->value => 'jorge@ejemplo.com'],
            ['51984111111' => $deSusan, 'jorge@ejemplo.com' => $this->hilo('Jorge')],
        ));
    }

    #[Test]
    public function el_orden_en_que_lleguen_no_cambia_el_resultado(): void
    {
        // El mapa lo compone cada dominio y su orden no es un contrato: si dependiera de él, la
        // misma reserva caería en un hilo u otro según quién lo construyó.
        $deSusan = $this->hilo('Susan');

        self::assertSame($deSusan, $this->resolver(
            [IdentidadTipo::EMAIL->value => 'jorge@ejemplo.com', IdentidadTipo::TELEFONO->value => '51984111111'],
            ['51984111111' => $deSusan, 'jorge@ejemplo.com' => $this->hilo('Jorge')],
        ));
    }

    #[Test]
    public function sin_telefono_en_el_empate_no_se_elige_ninguno(): void
    {
        // Un correo contra un `bookId`: no hay una señal más fuerte que otra, y unir historiales
        // a ciegas es lo que este módulo lleva una semana deshaciendo.
        self::assertNull($this->resolver(
            [IdentidadTipo::EMAIL->value => 'jorge@ejemplo.com', IdentidadTipo::BEDS24->value => '88591163'],
            ['jorge@ejemplo.com' => $this->hilo('Jorge'), '88591163' => $this->hilo('Otro')],
        ));
    }

    #[Test]
    public function sin_empate_la_reserva_se_suma_a_quien_ya_tiene_ese_telefono(): void
    {
        // El caso normal, y el que más importa: el teléfono ya es de alguien y el correo es
        // nuevo. La reserva entra en su conversación, con su historial.
        $suyo = $this->hilo('Susan');

        self::assertSame($suyo, $this->resolver(
            [IdentidadTipo::TELEFONO->value => '51984111111', IdentidadTipo::EMAIL->value => 'nuevo@ejemplo.com'],
            ['51984111111' => $suyo],
        ));
    }

    #[Test]
    public function si_no_conoce_a_nadie_no_devuelve_nada(): void
    {
        self::assertNull($this->resolver([IdentidadTipo::TELEFONO->value => '51984111111'], []));
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function hilo(string $nombre): MessageConversation
    {
        return new MessageConversation('pms_reserva', 'r-' . $nombre)->setGuestName($nombre);
    }

    /**
     * @param array<string, string>              $identificadores tipo => valor
     * @param array<string, MessageConversation> $duenios         valor normalizado => hilo dueño
     */
    private function resolver(array $identificadores, array $duenios): ?MessageConversation
    {
        // Se dobla el REPOSITORIO, no el resolutor: así el desempate se prueba contra el camino
        // real —normalización incluida— y no contra una imitación que podría divergir.
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findOneBy')->willReturnCallback(
            function (array $criterios) use ($duenios): ?MessageIdentidad {
                $hilo = $duenios[$criterios['valor'] ?? ''] ?? null;

                if ($hilo === null) {
                    return null;
                }

                $identidad = new MessageIdentidad($criterios['tipo'], (string) $criterios['valor']);
                $hilo->addIdentidad($identidad);

                return $identidad;
            }
        );

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $factory = new MessageConversationFactory(
            $em,
            new ResolutorDeHilo($em, new NullLogger()),
            new EnlacesDeConversacion([]),
            new NullLogger(),
        );

        return new ReflectionMethod(MessageConversationFactory::class, 'porIdentificadores')
            ->invoke($factory, $identificadores);
    }
}
