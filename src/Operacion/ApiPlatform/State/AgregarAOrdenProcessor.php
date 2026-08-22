<?php

declare(strict_types=1);

namespace App\Operacion\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Operacion\ApiPlatform\Dto\AgregarAOrdenInput;
use App\Operacion\Entity\OperacionOrdenServicio;
use App\Operacion\Entity\OperacionServicio;
use App\Operacion\Enum\EstadoOrdenServicioEnum;
use App\Operacion\Service\OperacionOrdenEmision;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\Uid\Uuid;

/**
 * Suma servicios a una Orden que se está componiendo.
 *
 * ── Por qué hace falta ──────────────────────────────────────────────────────
 * Componer una orden no siempre ocurre de una sentada: se marcan los servicios de un día, y al
 * revisar el siguiente aparecen dos más del mismo proveedor. Sin esto había que anular y rehacer,
 * que además cambia el número de la orden.
 *
 * ── ⚠️ SÓLO sobre borradores ────────────────────────────────────────────────
 * Una orden emitida **es un documento que el proveedor ya tiene**. Añadirle una línea por detrás
 * la cambiaría sin que él se entere, y es exactamente lo que el resto del módulo se esfuerza en
 * impedir: para eso está reemitir —anular y crear la sucesora—, que deja el rastro.
 *
 * ── Las mismas reglas, no unas parecidas ────────────────────────────────────
 * Se reutiliza {@see OperacionOrdenEmision::validar()} en vez de reescribir las comprobaciones:
 * comprable, no atado a otra orden, un solo expediente y un solo comprador. Y encima de eso, lo
 * que sólo aplica aquí: que el expediente y el comprador de lo que entra coincidan con **los de
 * la orden**, que ya están decididos.
 *
 * @implements ProcessorInterface<AgregarAOrdenInput, OperacionOrdenServicio>
 */
final readonly class AgregarAOrdenProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private OperacionOrdenEmision $emision,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): OperacionOrdenServicio
    {
        // Sin `instanceof`: el tipo lo garantiza el `input:` de la operación y la genérica de
        // ProcessorInterface, y comprobarlo otra vez sólo añade una rama que no puede ocurrir.
        $orden = $this->em->find(OperacionOrdenServicio::class, (string) ($uriVariables['id'] ?? ''));

        if (!$orden instanceof OperacionOrdenServicio) {
            throw new DomainException('La orden no existe. Recarga y vuelve a intentarlo.');
        }

        if ($orden->getEstadoOs() !== EstadoOrdenServicioEnum::BORRADOR) {
            throw new DomainException(sprintf(
                'La orden %s ya está %s: el proveedor la tiene. Para añadir algo hay que reemitirla.',
                $orden->getNumeroOs(),
                mb_strtolower($orden->getEstadoOs()->name)
            ));
        }

        $servicios = $this->cargar($data->servicioIds);

        // Las de siempre: comprable, libre, un expediente, un comprador.
        $this->emision->validar($servicios);

        // Y las de aquí: lo que entra tiene que ser del mismo viaje y del mismo destinatario que
        // la orden. Sin esto, una orden dirigida a PeruRail acabaría con una línea de Consettur
        // dentro y el proveedor recibiría un encargo que no es suyo.
        $this->exigirMismoDestino($orden, $servicios);

        foreach ($servicios as $servicio) {
            $servicio->setOrdenServicio($orden);
            $orden->addOperacionServicio($servicio);
        }

        $this->em->flush();

        return $orden;
    }

    /** @param list<OperacionServicio> $servicios */
    private function exigirMismoDestino(OperacionOrdenServicio $orden, array $servicios): void
    {
        $expedienteOrden = (string) $orden->getFile()?->getId();
        $compradorOrden = (string) $orden->getCompradorMaestroId();

        foreach ($servicios as $servicio) {
            if ((string) $servicio->getFile()?->getId() !== $expedienteOrden) {
                throw new DomainException(sprintf(
                    '«%s» es de otro expediente. La orden %s es sobre «%s».',
                    $servicio->getDescripcionServicio(),
                    $orden->getNumeroOs(),
                    $orden->getFile()?->getNombreGrupo() ?? '—'
                ));
            }

            // Por ID y no por nombre: «Futurismo» tecleado y «Futurismo Jonathan» del catálogo se
            // leen parecido y son dos proveedores distintos.
            if ((string) $servicio->getCompradorEfectivoMaestroId() !== $compradorOrden) {
                throw new DomainException(sprintf(
                    '«%s» se le compra a %s, y la orden %s va dirigida a %s.',
                    $servicio->getDescripcionServicio(),
                    $servicio->getCompradorEfectivoNombre() ?? '—',
                    $orden->getNumeroOs(),
                    $orden->getCompradorNombre() ?? '—'
                ));
            }
        }
    }

    /**
     * @param list<string> $ids
     *
     * @return list<OperacionServicio>
     */
    private function cargar(array $ids): array
    {
        $servicios = [];

        foreach ($ids as $id) {
            $servicio = Uuid::isValid($id) ? $this->em->find(OperacionServicio::class, $id) : null;

            if (!$servicio instanceof OperacionServicio) {
                throw new DomainException('Uno de los servicios elegidos ya no existe en La Biblia. Recarga y vuelve a intentarlo.');
            }

            $servicios[] = $servicio;
        }

        return $servicios;
    }
}
