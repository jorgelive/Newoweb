<?php

declare(strict_types=1);

namespace App\Tests\Message\Factory;

use App\Contract\MapaDeHitos;
use App\Contract\VinculoComercial;
use App\Entity\Maestro\MaestroIdioma;
use App\Message\Contract\MessageContextInterface;
use App\Message\Entity\MessageConversation;
use App\Message\Factory\MessageConversationFactory;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use App\Message\Service\Conversacion\ResolutorDeHilo;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * 🔥 20/10/2026: Doctrine dispara `prePersist` dentro de `persist()`, e `IdiomaDeTrabajoListener`
 * lee ahí el idioma. La fábrica persistía la conversación nueva y le ponía el idioma después, así
 * que cada hilo nuevo tumbaba el guardado entero —reservas del panel, bookings de Beds24—.
 */
final class IdiomaAntesDePersistTest extends TestCase
{
    #[Test]
    public function la_conversacion_nueva_ya_tiene_idioma_al_persistirla(): void
    {
        $ingles = $this->createStub(MaestroIdioma::class);
        $idiomaAlPersistir = null;

        $repositorio = $this->createStub(EntityRepository::class);
        $repositorio->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repositorio);
        $em->method('getReference')->willReturn($ingles);
        $em->expects(self::atLeastOnce())->method('persist')->willReturnCallback(
            static function (object $entidad) use (&$idiomaAlPersistir): void {
                if ($entidad instanceof MessageConversation) {
                    // Lo mismo que hace el listener: si no está, esto revienta.
                    $idiomaAlPersistir = $entidad->getIdioma();
                }
            }
        );

        $contexto = $this->createStub(MessageContextInterface::class);
        $contexto->method('getContextType')->willReturn('pms_reserva');
        $contexto->method('getContextId')->willReturn('r-1');
        $contexto->method('getContextLanguage')->willReturn('en_US');
        $contexto->method('getIdentificadores')->willReturn([]);
        $contexto->method('getVinculo')->willReturn(VinculoComercial::cases()[0]);
        $contexto->method('getMilestones')->willReturn(MapaDeHitos::vacio());

        $fabrica = new MessageConversationFactory($em, new ResolutorDeHilo($em, new NullLogger()), new EnlacesDeConversacion([]), new NullLogger());
        $fabrica->upsertFromContext($contexto);

        self::assertSame($ingles, $idiomaAlPersistir);
    }
}
