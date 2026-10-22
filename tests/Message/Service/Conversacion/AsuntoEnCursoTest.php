<?php

declare(strict_types=1);

namespace App\Tests\Message\Service\Conversacion;

use App\Contract\ConversationMilestoneInterface;
use App\Contract\MapaDeHitos;
use App\Contract\MomentoDeHito;
use App\Contract\VinculoComercial;
use App\Message\Contract\ConversacionEnlaceInterface;
use App\Message\Contract\ProveedorDeEnlacesInterface;
use App\Message\Entity\MessageConversation;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use App\Pms\Entity\PmsConversacionEnlace;
use App\Pms\Entity\PmsReserva;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * De qué reserva habla el hilo AHORA (`EnlacesDeConversacion::asuntoEnCurso()`).
 *
 * El caso que lo motivó: José Antonio Álvarez (21/10/2026), dos reservas en el mismo hilo —la del
 * 17→19, que es la cabecera, y la del 22→25—. Avisó de su llegada de mañana y el agente la buscó
 * en la que ya había terminado.
 */
final class AsuntoEnCursoTest extends TestCase
{
    private MessageConversation $hilo;

    protected function setUp(): void
    {
        $this->hilo = new MessageConversation('pms_reserva', 'cabecera');
    }

    #[Test]
    public function con_una_pasada_y_otra_proxima_habla_de_la_proxima(): void
    {
        $pasada = $this->enlace('-4 days 16:30', '-2 days 06:30');
        $proxima = $this->enlace('+1 day 14:00', '+4 days 10:00');

        self::assertSame($proxima, $this->servicio($pasada, $proxima)->asuntoEnCurso($this->hilo));
    }

    #[Test]
    public function la_que_esta_en_curso_gana_a_la_que_viene_despues(): void
    {
        $enCurso = $this->enlace('-1 day 14:00', '+1 day 10:00');
        $despues = $this->enlace('+10 days 14:00', '+12 days 10:00');

        self::assertSame($enCurso, $this->servicio($despues, $enCurso)->asuntoEnCurso($this->hilo));
    }

    #[Test]
    public function si_todas_pasaron_la_ultima(): void
    {
        $antigua = $this->enlace('-40 days 14:00', '-38 days 10:00');
        $reciente = $this->enlace('-5 days 14:00', '-3 days 10:00');

        self::assertSame($reciente, $this->servicio($antigua, $reciente)->asuntoEnCurso($this->hilo));
    }

    #[Test]
    public function un_asunto_terminado_o_de_acompanante_no_cuenta(): void
    {
        $pasada = $this->enlace('-4 days 14:00', '-2 days 10:00');
        $cancelada = $this->enlace('+1 day 14:00', '+3 days 10:00')->setVinculo(VinculoComercial::Terminado);
        $deOtro = $this->enlace('+2 days 14:00', '+4 days 10:00');
        $deOtro->marcarTitular(false);

        self::assertSame($pasada, $this->servicio($pasada, $cancelada, $deOtro)->asuntoEnCurso($this->hilo));
    }

    #[Test]
    public function sin_enlaces_manda_la_cabecera(): void
    {
        self::assertNull($this->servicio()->asuntoEnCurso($this->hilo));
    }

    private function enlace(string $inicio, string $fin): PmsConversacionEnlace
    {
        $reserva = new PmsReserva();
        $reserva->initializeId();

        return (new PmsConversacionEnlace($this->hilo, $reserva))
            ->setVinculo(VinculoComercial::Cliente)
            ->setMilestones(MapaDeHitos::de([
                ConversationMilestoneInterface::START => MomentoDeHito::de(new DateTimeImmutable($inicio)),
                ConversationMilestoneInterface::END => MomentoDeHito::de(new DateTimeImmutable($fin)),
            ]));
    }

    private function servicio(ConversacionEnlaceInterface ...$enlaces): EnlacesDeConversacion
    {
        $proveedor = new class (array_values($enlaces)) implements ProveedorDeEnlacesInterface {
            /** @param list<ConversacionEnlaceInterface> $enlaces */
            public function __construct(private readonly array $enlaces) {}

            public function getNegocio(): string { return 'prueba'; }

            public function paraConversacion(MessageConversation $conversacion): array { return $this->enlaces; }

            public function titularDeAsunto(string $contextType, string $contextId): ?ConversacionEnlaceInterface { return null; }

            /** @return list<ConversacionEnlaceInterface> */
            public function acompanantesDeAsunto(string $contextType, string $contextId): array { return []; }

            public function enlaceDeAsunto(MessageConversation $conversacion, string $contextType, string $contextId): ?ConversacionEnlaceInterface { return null; }
        };

        return new EnlacesDeConversacion([$proveedor]);
    }
}
