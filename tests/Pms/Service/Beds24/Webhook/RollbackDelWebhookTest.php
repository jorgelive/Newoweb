<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Beds24\Webhook;

use App\Exchange\Entity\Beds24Config;
use App\Exchange\Service\Context\SyncContext;
use App\Pms\Factory\PmsEventoCalendarioFactory;
use App\Pms\Service\Beds24\Webhook\Beds24WebhookBookingFastTrackService;
use App\Pms\Service\Exchange\Tasks\BookingsPull\BookingPullPersister;
use App\Service\Nombre\NombreSanitizer;
use App\Service\Phone\PhoneSanitizer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Si una reserva del webhook falla, lo que quedó a medias en memoria se descarta.
 *
 * El rollback deshace la base, no el UnitOfWork. Quien llama atrapa el error y sigue hasta un
 * `flush()` final, que insertaría la reserva a medio construir. Ver
 * docs/PmsBeds24ReservasSync.md §3.2.
 */
final class RollbackDelWebhookTest extends TestCase
{
    #[Test]
    public function al_fallar_vacia_el_unit_of_work_y_relanza(): void
    {
        $config = (new Beds24Config())->setActivo(true);

        $repoConfig = $this->createStub(EntityRepository::class);
        $repoConfig->method('findOneBy')->willReturn($config);
        $repoMapa = $this->createStub(EntityRepository::class);
        $repoMapa->method('findOneBy')->willReturn(null);           // sin mapeo: upsert() lanza

        $conexion = $this->createMock(Connection::class);
        $conexion->expects(self::once())->method('rollBack');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conexion);
        $em->method('isOpen')->willReturn(true);
        $em->method('getRepository')->willReturnCallback(
            static fn (string $clase): EntityRepository => $clase === Beds24Config::class ? $repoConfig : $repoMapa
        );
        $em->expects(self::once())->method('clear');
        $em->expects(self::never())->method('flush');

        $persister = new BookingPullPersister(
            $em,
            $this->createStub(PmsEventoCalendarioFactory::class),
            new PhoneSanitizer(),
            new NombreSanitizer(),
            new NullLogger(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No existe mapeo PMS');

        (new Beds24WebhookBookingFastTrackService($em, $persister, new SyncContext()))
            ->process('token', ['id' => 93900001, 'roomId' => 999999]);
    }
}
