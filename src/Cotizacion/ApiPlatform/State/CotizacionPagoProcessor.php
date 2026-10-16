<?php

declare(strict_types=1);

namespace App\Cotizacion\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Cotizacion\Entity\CotizacionPago;
use App\Cotizacion\Service\Message\CotizacionVinculoDelExpediente;
use App\Entity\User;
use DateTimeImmutable;
use DomainException;
use Doctrine\ORM\EntityManagerInterface;
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
 * En los dos casos, después, el vínculo comercial de las conversaciones del expediente: pagar es
 * lo que convierte a alguien en cliente para el agente ({@see CotizacionVinculoDelExpediente}).
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
        private CotizacionVinculoDelExpediente $vinculo,
        private EntityManagerInterface $em,
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

        $resultado = $this->persistProcessor->process($data, $operation, $uriVariables, $context);

        $file = $data->getFile();
        if ($file !== null) {
            // La colección inversa no se entera sola de un pago recién creado en esta petición.
            if (!$file->getPagos()->contains($data)) {
                $file->getPagos()->add($data);
            }
            $this->vinculo->aplicar($file);
            $this->em->flush();
        }

        return $resultado;
    }
}
