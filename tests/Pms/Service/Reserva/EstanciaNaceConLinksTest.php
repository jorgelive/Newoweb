<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Reserva;

use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsEstablecimientoVirtual;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsEventoEstadoPago;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Entity\PmsUnidadBeds24Map;
use App\Pms\Factory\PmsEventoCalendarioFactory;
use App\Pms\Service\Reserva\PmsDisponibilidadService;
use App\Pms\Service\Reserva\PmsEstanciaCreator;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Una estancia creada por el asistente nace con sus links de Beds24.
 *
 * Sin ellos el listener de la cola no encola nada y el rescate periódico —que busca por
 * `innerJoin` sobre los links— tampoco la ve: la casita seguía a la venta en las OTAs. Ver
 * docs/PmsBeds24ReservasSync.md §6.2.
 */
final class EstanciaNaceConLinksTest extends TestCase
{
    #[Test]
    public function la_estancia_nace_con_un_principal_y_un_espejo_sin_id_remoto(): void
    {
        $unidad = new PmsUnidad();
        foreach ([['INTI', true], ['SAPHY', false]] as [$codigo, $esPrincipal]) {
            $virtual = (new PmsEstablecimientoVirtual())->setCodigo($codigo)->setEsPrincipal($esPrincipal);
            $unidad->addBeds24Map((new PmsUnidadBeds24Map())->setVirtualEstablecimiento($virtual)->setActivo(true));
        }

        $evento = $this->creador()->crear(
            new PmsReserva(),
            $unidad,
            new DateTimeImmutable('2026-11-10'),
            new DateTimeImmutable('2026-11-12'),
            2
        );

        $links = $evento->getLinksDeEstancia();
        self::assertCount(2, $links, 'un link por mapa activo de la casita');

        $principales = array_values(array_filter($links, static fn ($l) => $l->isEsPrincipal()));
        self::assertCount(1, $principales);
        self::assertSame('INTI', $principales[0]->getUnidadBeds24Map()?->getVirtualEstablecimiento()?->getCodigo());

        foreach ($links as $link) {
            self::assertNull($link->getBeds24BookId(), 'el Push le sella el id al crearla en Beds24');
        }
    }

    private function creador(): PmsEstanciaCreator
    {
        $repos = [
            PmsEventoEstado::class => new PmsEventoEstado(PmsEventoEstado::CODIGO_PENDIENTE),
            PmsEventoEstadoPago::class => new PmsEventoEstadoPago(),
            PmsChannel::class => new PmsChannel(PmsChannel::CODIGO_DIRECTO),
        ];

        $result = $this->createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);
        $conexion = $this->createStub(Connection::class);
        $conexion->method('executeQuery')->willReturn($result);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conexion);
        $em->method('getRepository')->willReturnCallback(function (string $clase) use ($repos) {
            $repo = $this->createStub(EntityRepository::class);
            $repo->method('find')->willReturn($repos[$clase] ?? null);

            return $repo;
        });

        return new PmsEstanciaCreator($em, new PmsDisponibilidadService($em), new PmsEventoCalendarioFactory($em));
    }
}
