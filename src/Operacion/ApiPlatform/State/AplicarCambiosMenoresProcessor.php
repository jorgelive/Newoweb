<?php

declare(strict_types=1);

namespace App\Operacion\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Operacion\Entity\OperacionOrdenServicio;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Aplica a la Orden lo que **no** obliga a reemitir.
 *
 * Hoy es un solo caso, y es el más común de todos: el proveedor confirma la hora de recojo. Que
 * aparezca no es un descuido de nadie —es él quien la dice al confirmar— así que la orden sigue
 * siendo válida: se actualiza el documento y se avisa.
 *
 * ⚠️ **El aviso NO es el mismo que el de una modificación.** Al cliente se le confirma la hora
 * para su programa y al proveedor se le acusa recibo; nadie está corrigiendo nada. Mandar un
 * «cambio de horario» donde hubo una confirmación siembra dudas sobre un servicio que va bien.
 *
 * De aquí cuelgan esas acciones. Hoy queda registrado en el log: el envío va aparte y en
 * asíncrono, porque una caída del correo no puede deshacer una actualización ya aplicada.
 */
/**
 * ⚠️ Genérico en `mixed`: API Platform le pasa lo que sea y esto delega lo que no reconoce.
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
final readonly class AplicarCambiosMenoresProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $id = (string) ($uriVariables['id'] ?? '');

        if (!Uuid::isValid($id)) {
            throw new DomainException('Falta la orden sobre la que actuar.');
        }

        $orden = $this->em->find(OperacionOrdenServicio::class, Uuid::fromString($id));

        if (!$orden instanceof OperacionOrdenServicio) {
            throw new DomainException('Esa orden ya no existe.');
        }

        $aplicados = $orden->aplicarCambiosMenores();

        if ($aplicados === []) {
            throw new DomainException('Esta orden no tiene cambios menores que aplicar.');
        }

        $this->em->flush();

        // El punto donde colgar la confirmación al cliente y el acuse al proveedor.
        $this->logger->info(sprintf(
            'Orden %s: aplicados %d cambio(s) menor(es) — %s',
            $orden->getNumeroOs(),
            count($aplicados),
            implode(' · ', $aplicados)
        ));

        return $orden;
    }
}
