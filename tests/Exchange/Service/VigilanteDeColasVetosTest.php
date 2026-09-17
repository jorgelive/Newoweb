<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Service;

use App\Exchange\Service\VigilanteDeColas;
use App\Repository\UserRepository;
use App\Service\WebPushNotificationService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Un veto avisa: la barrera protegió al huésped, pero detrás hay una cascada que falló.
 */
final class VigilanteDeColasVetosTest extends TestCase
{
    #[Test]
    public function una_cola_vetada_sale_en_el_informe(): void
    {
        $lineas = $this->vigilante(static function (string $sql, array $params): int {
            if (str_contains($sql, 'msg_beds24_send_queue') && str_contains($sql, "status = 'cancelled'")) {
                // El prefijo es lo que distingue un veto de una cancelación normal.
                self::assertSame('[veto] %', $params['prefijo'] ?? null);

                return 2;
            }

            return 0;
        })->revisar(24);

        self::assertSame(['2 vetada(s) en la cola de mensajes Beds24 (envío): una cancelación no llegó a su cola'], $lineas);
    }

    #[Test]
    public function la_cola_de_correo_tambien_se_vigila(): void
    {
        $lineas = $this->vigilante(static fn (string $sql): int => str_contains($sql, 'msg_email_send_queue') && str_contains($sql, "status = 'failed'") ? 1 : 0)
            ->revisar(24);

        self::assertSame(['1 en la cola de correo (envío)'], $lineas);
    }

    #[Test]
    public function sin_base_no_dice_que_todo_va_bien(): void
    {
        // Con una consulta más por cola, el recuento de «no pude mirar NADA» tiene que seguir cuadrando.
        $lineas = $this->vigilante(static fn (): int => throw new RuntimeException('sin conexión'))->revisar(24);

        self::assertSame(['no se pudo consultar NINGUNA cola: revisa la conexión a la base'], $lineas);
    }

    /**
     * @param callable(string, array<string, mixed>): int $recuento
     */
    private function vigilante(callable $recuento): VigilanteDeColas
    {
        $conexion = $this->createStub(Connection::class);
        $conexion->method('fetchOne')->willReturnCallback(
            static fn (string $sql, array $params = []): int => $recuento($sql, $params)
        );

        return new VigilanteDeColas(
            $conexion,
            $this->createStub(WebPushNotificationService::class),
            $this->createStub(UserRepository::class),
            $this->createStub(RoleHierarchyInterface::class),
            new NullLogger(),
        );
    }
}
