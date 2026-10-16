<?php

declare(strict_types=1);

namespace App\Cotizacion\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Cotizacion\Entity\CotizacionPago;
use App\Entity\User;
use DateTimeImmutable;
use DomainException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Registrar y anular un pago de expediente.
 *
 * - **Alta**: quién lo registró sale de la sesión, nunca del cuerpo de la petición.
 * - **Anular** (`PATCH /cotizacion_pagos/{id}/anular`): el cuerpo sólo trae el motivo, y es
 *   {@see CotizacionPago::anular()} quien cambia el estado — de ida, y con motivo obligatorio.
 *
 * El vínculo comercial de las conversaciones del expediente —pagar hace cliente— NO se toca aquí:
 * lo resincroniza `CotizacionFileConversacionListener` en el mismo flush, que es el sitio por el
 * que pasará también el cobro por pasarela.
 *
 * @implements ProcessorInterface<CotizacionPago, CotizacionPago|null>
 */
final readonly class CotizacionPagoProcessor implements ProcessorInterface
{
    /** @param ProcessorInterface<CotizacionPago, CotizacionPago|null> $persistProcessor */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private Security $security,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        // Frontera del framework: lo que llega es el cuerpo de una petición ya denormalizado.
        if (!$data instanceof CotizacionPago) {
            return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        }

        $usuario = $this->security->getUser();
        $usuario = $usuario instanceof User ? $usuario : null;

        if ($operation instanceof Post) {
            $data->setRegistradoPor($usuario);
        } else {
            try {
                $data->anular((string) $data->getMotivoAnulacion(), $usuario, new DateTimeImmutable());
            } catch (DomainException $e) {
                throw new UnprocessableEntityHttpException($e->getMessage(), $e);
            }
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
