<?php

declare(strict_types=1);

namespace App\Operacion\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use App\Operacion\ApiPlatform\Dto\AplicarPlanInput;
use App\Operacion\ApiPlatform\Dto\ResultadoAplicacion;
use App\Operacion\Service\BibliaReconciliacionService;
use ApiPlatform\Metadata\Util\ClassInfoTrait;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\Uid\Uuid;

/**
 * Aplica ÚNICAMENTE los cambios aprobados de un plan vigente.
 *
 * El `$data` que llega aquí es el `AplicarPlanInput` deserializado del body, no la
 * cotización: con `input:` API Platform sustituye el objeto. La cotización se recupera
 * del `{id}` de la URL.
 *
 * @implements ProcessorInterface<AplicarPlanInput, ResultadoAplicacion>
 */
final class AplicarPlanOperacionProcessor implements ProcessorInterface
{
    use ClassInfoTrait;

    public function __construct(
        private readonly BibliaReconciliacionService $reconciliacion,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ResultadoAplicacion
    {
        if (!$data instanceof AplicarPlanInput) {
            throw new DomainException('Cuerpo de la petición inválido: falta la firma del plan.');
        }

        $id = $uriVariables['id'] ?? null;
        if ($id === null || !Uuid::isValid((string) $id)) {
            throw new DomainException('Falta el identificador de la cotización.');
        }

        $cotizacion = $this->em->getRepository(Cotizacion::class)->find(Uuid::fromString((string) $id));
        if ($cotizacion === null) {
            throw new DomainException('No existe la cotización.');
        }

        // Se revalidan las mismas guardas que el plan: entre planificar y aplicar
        // alguien pudo des-confirmar la cotización.
        if ($cotizacion->getEstado() !== CotizacionEstadoEnum::CONFIRMADO) {
            throw new DomainException(sprintf(
                'La cotización ya no está confirmada (está en «%s»): no se aplica nada.',
                $cotizacion->getEstado()->value
            ));
        }

        return $this->reconciliacion->aplicar($cotizacion, $data);
    }
}
