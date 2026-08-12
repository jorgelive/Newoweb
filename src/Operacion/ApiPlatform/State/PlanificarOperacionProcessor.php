<?php

declare(strict_types=1);

namespace App\Operacion\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use App\Operacion\ApiPlatform\Dto\PlanReconciliacion;
use App\Operacion\Service\BibliaReconciliacionService;
use DomainException;

/**
 * Calcula el diff entre la cotización y La Biblia. **No escribe nada.**
 *
 * Es un POST y no un GET porque el cálculo recorre el árbol entero de la cotización y
 * no queremos que ninguna capa de caché HTTP lo sirva viejo: un plan obsoleto es
 * exactamente lo que la firma existe para impedir.
 *
 * @implements ProcessorInterface<Cotizacion, PlanReconciliacion>
 */
final class PlanificarOperacionProcessor implements ProcessorInterface
{
    public function __construct(private readonly BibliaReconciliacionService $reconciliacion)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlanReconciliacion
    {
        if (!$data instanceof Cotizacion) {
            throw new DomainException('No se encontró la cotización.');
        }

        if ($data->getCatalogo() !== null) {
            throw new DomainException('Un tour de catálogo no genera operación: sus fechas son nominales y no tiene expediente.');
        }

        if ($data->getEstado() !== CotizacionEstadoEnum::CONFIRMADO) {
            throw new DomainException(sprintf(
                'Sólo se genera operación desde una cotización confirmada; ésta está en «%s». Cámbiala a Confirmado y guarda antes de revisar los cambios.',
                $data->getEstado()->value
            ));
        }

        return $this->reconciliacion->planificar($data);
    }
}
