<?php

declare(strict_types=1);

namespace App\Operacion\ApiPlatform\State;

use App\Api\VariableDeRuta;
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
 * Dos casos hoy. El proveedor confirma la hora de recojo —no es descuido de nadie, es él quien la
 * dice al confirmar— y la tarifa que el documento nunca llegó a decir, desde el 04/10/2026. En los
 * dos la orden sigue siendo válida: se actualiza el documento y ya está.
 *
 * ⚠️ **ESTO NO AVISA A NADIE, y la pantalla llegó a decir que sí.** El botón se llamaba
 * «Actualizar y avisar» y debajo ponía «Se confirma la hora al cliente y al proveedor», en
 * presente. Lo único que ocurre es el `flush()` y la línea de log de abajo. Un operador que lo
 * pulsara se quedaba convencido de que el proveedor ya lo sabía — sin un solo error por ningún
 * lado, que es la familia de fallo que este proyecto persigue. Corregido en la vista el
 * 04/10/2026: ahora dice «Actualizar la orden» y «No se manda ningún aviso».
 *
 * **Si algún día se implementa el envío, el aviso NO puede ser el mismo que el de una
 * modificación.** Al cliente se le confirma la hora para su programa y al proveedor se le acusa
 * recibo; nadie está corrigiendo nada. Mandar un «cambio de horario» donde hubo una confirmación
 * siembra dudas sobre un servicio que va bien. Y va aparte y en asíncrono, porque una caída del
 * correo no puede deshacer una actualización ya aplicada.
 *
 * ⚠️ Y al hacerlo hay que mirar el texto de la vista en el mismo cambio, o quedará diciendo que no
 * avisa cuando ya avise — el mismo fallo girado del revés.
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
        $id = VariableDeRuta::texto($uriVariables, 'id');

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
