<?php

declare(strict_types=1);

namespace App\Operacion\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Operacion\ApiPlatform\Dto\CambiarEstadoOrdenInput;
use App\Operacion\Entity\OperacionOrdenServicio;
use App\Operacion\Enum\EstadoOrdenServicioEnum;
use App\Operacion\Service\OperacionOrdenEmision;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\Uid\Uuid;

/**
 * Mueve una Orden de estado, con lo que cada transición significa de verdad.
 *
 *     borrador  →  emitida     se CONGELA el contenido: pasa a ser un documento
 *     cualquiera → cancelada   se SUELTAN las filas: quedan libres para otra Orden
 *
 * Es una **acción** y no un campo editable a propósito: `estadoOs` no está en `operacion:write`.
 * Con dos puertas a la misma transición, las reglas se escapan por la que no mira nadie — un
 * `PATCH` genérico no sabe distinguir «emitir» de «corregir el número de la orden».
 *
 * Y es el sitio donde colgar lo que pase al emitir: avisar al proveedor, generar el PDF. Eso va
 * **después del flush y en asíncrono**; si fuera dentro, una caída del correo tumbaría una
 * orden que ya está bien emitida.
 */
/**
 * ⚠️ Genérico en `mixed`: API Platform le pasa lo que sea y esto delega lo que no reconoce.
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
final readonly class CambiarEstadoOrdenProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private OperacionOrdenEmision $emision,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof CambiarEstadoOrdenInput) {
            throw new DomainException('Entrada no reconocida para cambiar el estado de una orden.');
        }

        $id = (string) ($uriVariables['id'] ?? '');

        if (!Uuid::isValid($id)) {
            throw new DomainException('Falta la orden sobre la que actuar.');
        }

        $orden = $this->em->find(OperacionOrdenServicio::class, Uuid::fromString($id));

        if (!$orden instanceof OperacionOrdenServicio) {
            throw new DomainException('Esa orden ya no existe.');
        }

        $destino = EstadoOrdenServicioEnum::from($data->estado);
        $this->emision->validarTransicion($orden->getEstadoOs(), $destino);

        if ($destino === EstadoOrdenServicioEnum::CANCELADA) {
            $this->emision->anular($orden);
        } elseif ($orden->getEstadoOs() === EstadoOrdenServicioEnum::BORRADOR) {
            // Cualquier salida del borrador es la emisión: pasar de golpe a «confirmada» sin
            // congelar dejaría un documento sin contenido propio.
            $this->emision->emitir($orden);
            $orden->setEstadoOs($destino);
        } else {
            $orden->setEstadoOs($destino);
        }

        $this->em->flush();

        return $orden;
    }
}
