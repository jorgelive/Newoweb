<?php

declare(strict_types=1);

namespace App\Cotizacion\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Cotizacion\Entity\CotizacionFile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Borrar un expediente, salvo que tenga pagos.
 *
 * La FK de `cotizacion_pago` al expediente va sin `ON DELETE` a propósito —un pago es un documento
 * contable—, así que borrar uno con pagos ya fallaba. Pero fallaba con un 500 y una violación de FK
 * que el operador lee como «se rompió algo». Aquí se dice qué pasa y qué hacer. Cuentan también los
 * anulados: siguen siendo historia del expediente.
 *
 * @implements ProcessorInterface<CotizacionFile, null>
 */
final readonly class CotizacionFileBorradoProcessor implements ProcessorInterface
{
    /** @param ProcessorInterface<CotizacionFile, null> $removeProcessor */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private ProcessorInterface $removeProcessor,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof CotizacionFile && !$data->getPagos()->isEmpty()) {
            throw new ConflictHttpException(
                'Este expediente tiene pagos registrados y un pago no se borra. Ciérralo («no venta») en vez de borrarlo.',
            );
        }

        return $this->removeProcessor->process($data, $operation, $uriVariables, $context);
    }
}
