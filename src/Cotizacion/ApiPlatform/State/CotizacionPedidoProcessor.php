<?php

declare(strict_types=1);

namespace App\Cotizacion\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Cotizacion\Entity\CotizacionPedido;
use App\Entity\User;
use DateTimeImmutable;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Al marcar un pedido a mano desde el panel, firma QUIÉN y CUÁNDO.
 *
 * Mismo criterio que {@see \App\Pms\ApiPlatform\State\PmsPeticionProcessor}: el grupo de escritura
 * sólo admite `efectuadaAt`, así que el navegador puede decir «hecho» y nada más — el nombre y la
 * hora los pone el servidor con la sesión que tiene delante, nunca el cliente.
 *
 * Desmarcar limpia la firma, por el mismo motivo: dejar el nombre de quien lo cerró en un pedido
 * que ahora consta como pendiente diría que alguien comprobó algo que ya no está comprobado.
 *
 * @implements ProcessorInterface<CotizacionPedido, CotizacionPedido>
 */
final readonly class CotizacionPedidoProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<CotizacionPedido, CotizacionPedido> $persistidor El de Doctrine, decorado.
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        /** @var ProcessorInterface<CotizacionPedido, CotizacionPedido> */
        private ProcessorInterface $persistidor,
        private Security $security,
    ) {}

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = []
    ): mixed {
        if ($data instanceof CotizacionPedido) {
            $usuario = $this->security->getUser();

            $data->setEfectuadaPor(
                $data->getEfectuadaAt() !== null && $usuario instanceof User ? $usuario : null
            );

            if ($data->getEfectuadaAt() !== null) {
                $data->setEfectuadaAt(new DateTimeImmutable());
            }
        }

        return $this->persistidor->process($data, $operation, $uriVariables, $context);
    }
}
