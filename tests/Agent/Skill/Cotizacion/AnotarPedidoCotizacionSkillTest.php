<?php

declare(strict_types=1);

namespace App\Tests\Agent\Skill\Cotizacion;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\RestriccionCanal;
use App\Agent\Skill\Cotizacion\AnotarPedidoCotizacionSkill;
use App\Contract\VinculoComercial;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Las dos comprobaciones que no tocan base de datos: un pedido vacío y una conversación que
 * todavía no existe. Lo que sí escribe —el alta real— se verifica con el flujo real, como el
 * resto de lo que toca base de datos (ver `CLAUDE.md`).
 */
#[CoversClass(AnotarPedidoCotizacionSkill::class)]
final class AnotarPedidoCotizacionSkillTest extends TestCase
{
    public function testUnPedidoVacioNoLlegaAPersistNiSePasaPorAlto(): void
    {
        // El stub no espera ninguna llamada: si la skill tocara el EntityManager en esta rama,
        // el test fallaría por una llamada inesperada, no habría que comprobarlo a mano.
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $skill = new AnotarPedidoCotizacionSkill($em);
        $resultado = $skill->ejecutar(['pedido' => '   '], $this->actor());

        self::assertTrue($resultado->esError());
    }

    public function testSinConversacionGuardadaNoHayDondeAnotar(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $skill = new AnotarPedidoCotizacionSkill($em);
        $resultado = $skill->ejecutar(
            ['pedido' => 'Valle Sagrado lunes 5 para 4'],
            $this->actor(conversacionId: null),
        );

        self::assertTrue($resultado->esError());
    }

    public function testNombreYMetadatosDeLaSkill(): void
    {
        $skill = new AnotarPedidoCotizacionSkill($this->createStub(EntityManagerInterface::class));

        self::assertSame('anotar_pedido_cotizacion', $skill->nombre());
        self::assertSame(['turistico'], $skill->dominios());

        $parametros = $skill->definicion()->parametros;
        self::assertCount(1, $parametros);
        self::assertSame('pedido', $parametros[0]->nombre);
        self::assertTrue($parametros[0]->requerido);
    }

    private function actor(?string $conversacionId = 'conv-1'): ActorInterface
    {
        return new class ($conversacionId) implements ActorInterface {
            public function __construct(private readonly ?string $conversacionId) {}

            public function roles(): array { return []; }
            public function origen(): string { return 'test'; }
            public function contextoTipo(): ?string { return null; }
            public function contextoId(): ?string { return null; }
            public function conversacionId(): ?string { return $this->conversacionId; }
            public function vinculo(): VinculoComercial { return VinculoComercial::Ninguno; }
            public function restriccion(): RestriccionCanal { return RestriccionCanal::Ninguna; }
            public function dominios(): array { return []; }
            public function esDelEquipo(): bool { return false; }
            public function esProspecto(): bool { return false; }
            public function usuario(): ?User { return null; }
            public function tieneRol(string $rol): bool { return false; }
            public function tieneAlguno(array $roles): bool { return $roles === []; }
            public function etiqueta(): string { return 'doble'; }
        };
    }
}
