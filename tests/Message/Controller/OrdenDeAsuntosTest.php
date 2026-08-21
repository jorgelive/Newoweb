<?php

declare(strict_types=1);

namespace App\Tests\Message\Controller;

use App\Contract\ConversationMilestoneInterface;
use App\Contract\Frente;
use App\Contract\MomentoDeFrente;
use App\Contract\VinculoComercial;
use App\Message\Contract\ConversacionEnlaceInterface;
use App\Message\Controller\Api\AsuntosDeConversacionController;
use App\Message\Entity\MessageConversation;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Cuál de los asuntos de una persona se propone primero.
 *
 * Es lo que decide, en un hilo con varias reservas, por qué `bookId` de Beds24 sale el mensaje:
 * el asunto elegido lleva a su reserva, y de ahí al `beds24_book_id`.
 *
 * ⚠️ Antes se ordenaba por `esTitular`, y eso significa «este hilo atiende este asunto», no
 * «éste es el principal»: en un hilo fusionado TODOS lo son, así que el defecto acababa siendo
 * el orden de creación del enlace — arbitrario, y acertaba por casualidad.
 */
final class OrdenDeAsuntosTest extends TestCase
{
    private const string HOY = '2026-08-20';

    #[Test]
    public function la_estancia_en_curso_va_primero(): void
    {
        // Aunque otra llegue antes en el calendario: de lo que se está hablando es de donde el
        // huésped está ahora mismo.
        self::assertSame(
            ['en curso', 'llega pronto', 'llega tarde', 'pasada'],
            $this->ordenadas([
                'llega tarde'  => ['2027-03-08', '2027-03-10'],
                'pasada'       => ['2026-06-29', '2026-07-30'],
                'en curso'     => ['2026-08-18', '2026-08-25'],
                'llega pronto' => ['2026-09-01', '2026-09-05'],
            ])
        );
    }

    #[Test]
    public function sin_ninguna_en_curso_manda_la_proxima_a_llegar(): void
    {
        // El caso real de Susan: tres reservas y ninguna hoy. Febrero antes que marzo.
        self::assertSame(
            ['febrero', 'marzo', 'la del verano'],
            $this->ordenadas([
                'marzo'         => ['2027-03-08', '2027-03-10'],
                'la del verano' => ['2026-06-29', '2026-07-30'],
                'febrero'       => ['2027-02-02', '2027-02-04'],
            ])
        );
    }

    #[Test]
    public function entre_pasadas_gana_la_mas_reciente(): void
    {
        self::assertSame(
            ['la de julio', 'la de enero'],
            $this->ordenadas([
                'la de enero' => ['2026-01-10', '2026-01-12'],
                'la de julio' => ['2026-07-01', '2026-07-03'],
            ])
        );
    }

    #[Test]
    public function un_asunto_sin_fechas_va_entre_lo_futuro_y_lo_pasado(): void
    {
        // Un expediente de viaje sin itinerario todavía: ni urgente ni terminado.
        self::assertSame(
            ['futura', 'sin fechas', 'pasada'],
            $this->ordenadas([
                'pasada'     => ['2026-01-10', '2026-01-12'],
                'sin fechas' => [null, null],
                'futura'     => ['2026-12-01', '2026-12-05'],
            ])
        );
    }

    #[Test]
    public function una_fecha_rota_no_tumba_el_listado(): void
    {
        // Cuenta como «sin fechas». Si la excepción subiera, el operador se quedaría sin
        // selector y sin saber por qué.
        self::assertSame(
            ['buena', 'rota'],
            $this->ordenadas(['rota' => ['no-es-una-fecha', null], 'buena' => ['2026-12-01', null]])
        );
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, array{?string, ?string}> $asuntos etiqueta => [inicio, fin]
     * @return list<string>
     */
    private function ordenadas(array $asuntos): array
    {
        $relevancia = new ReflectionMethod(AsuntosDeConversacionController::class, 'relevancia');
        $hoy = new DateTimeImmutable(self::HOY);

        $enlaces = [];
        foreach ($asuntos as $etiqueta => [$inicio, $fin]) {
            $enlaces[] = $this->enlace((string) $etiqueta, $inicio, $fin);
        }

        usort(
            $enlaces,
            static fn (ConversacionEnlaceInterface $a, ConversacionEnlaceInterface $b): int
                => $relevancia->invoke(null, $a, $hoy) <=> $relevancia->invoke(null, $b, $hoy)
        );

        return array_map(static fn (ConversacionEnlaceInterface $e): string => $e->getEtiqueta(), $enlaces);
    }

    private function enlace(string $etiqueta, ?string $inicio, ?string $fin): ConversacionEnlaceInterface
    {
        $hitos = [];

        if ($inicio !== null) {
            $hitos[ConversationMilestoneInterface::START] = $inicio;
        }

        if ($fin !== null) {
            $hitos[ConversationMilestoneInterface::END] = $fin;
        }

        return new class ($etiqueta, $hitos) implements ConversacionEnlaceInterface {
            /** @param array<string, string> $hitos */
            public function __construct(private readonly string $etiqueta, private readonly array $hitos) {}

            public function getConversacion(): ?MessageConversation { return null; }
            public function getNegocio(): string { return 'prueba'; }
            public function getContextType(): string { return 'pms_reserva'; }
            public function getContextId(): string { return $this->etiqueta; }
            public function getVinculo(): VinculoComercial { return VinculoComercial::Ninguno; }
            public function getMomento(): MomentoDeFrente { return MomentoDeFrente::Venta; }
            public function getMilestones(): array { return $this->hitos; }
            public function getOrigen(): ?string { return null; }
            public function getAgencia(): ?string { return null; }
            public function procedenciaParaElPrompt(): ?string { return null; }
            public function getCreatedAt(): ?DateTimeImmutable { return null; }
            public function getEtiqueta(): string { return $this->etiqueta; }
            public function correoDeContacto(): ?string { return null; }
            public function correoEsExclusivo(): bool { return false; }
            public function esTitular(): bool { return true; }
            public function marcarTitular(bool $esTitular): self { return $this; }
            public function canalesPosibles(): array { return []; }
            public function comoFrente(): Frente { return new Frente('prueba', MomentoDeFrente::Venta, $this->etiqueta); }
        };
    }
}
