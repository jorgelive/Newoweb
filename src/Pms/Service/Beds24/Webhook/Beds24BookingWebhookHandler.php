<?php
declare(strict_types=1);

namespace App\Pms\Service\Beds24\Webhook;

use App\Pms\Dto\Beds24BookingDto;
use App\Pms\Entity\Beds24Config;
use App\Pms\Service\Beds24\Sync\Pull\Resolver\Beds24BookingResolverInterface;
use App\Pms\Service\Beds24\Sync\SyncContext;
use Doctrine\ORM\EntityManagerInterface;

final class Beds24BookingWebhookHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Beds24BookingResolverInterface $resolver,
        private readonly SyncContext $syncContext,
    ) {}

    /**
     * Ejecuta un callback dentro de un source y restaura el anterior al finalizar.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function runInSource(string $source, callable $fn): mixed
    {
        $previous = $this->syncContext->getSource();
        $this->syncContext->setSource($source);

        try {
            return $fn();
        } finally {
            $this->syncContext->setSource($previous);
        }
    }

    /**
     * Procesa un webhook que YA trae el booking (API v2).
     *
     * Reglas:
     * - No consulta fechas.
     * - No resuelve roomIds.
     * - Hace upsert 1 booking.
     * - Controla el SyncContext para que listeners sepan que es fuente externa.
     * - Resuelve la Beds24Config usando un token (no viene configId en el webhook).
     *
     * Nota: Este handler espera **solo el payload del booking**, no el sobre completo del webhook.
     *
     * @param string $token Token secreto asociado a la Beds24Config (webhookToken).
     * @param array<string,mixed> $bookingArr Payload del booking (API v2).
     * @return array<string,mixed>
     */
    public function handle(string $token, array $bookingArr): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new \RuntimeException('Webhook Beds24 inválido: falta token.');
        }

        /** @var Beds24Config|null $config */
        $config = $this->em->getRepository(Beds24Config::class)->findOneBy(['webhookToken' => $token]);
        if (!$config instanceof Beds24Config) {
            throw new \RuntimeException('Beds24Config no existe para el token recibido.');
        }

        return $this->runInSource(SyncContext::SOURCE_PULL_BEDS24, function () use ($config, $bookingArr): array {
            $conn = $this->em->getConnection();
            $conn->beginTransaction();

            try {
                $dto = Beds24BookingDto::fromArray($bookingArr);

                // Upsert 1 booking (sin flush interno)
                $this->resolver->upsert($config, $dto);

                $this->em->flush();
                $conn->commit();

                return [
                    'processed' => 1,
                    'bookingId' => $bookingArr['id'] ?? ($bookingArr['bookingId'] ?? null),
                ];
            } catch (\Throwable $e) {
                $conn->rollBack();

                try {
                    $this->em->clear();
                } catch (\Throwable) {
                    // ignore
                }

                throw $e;
            }
        });
    }
}