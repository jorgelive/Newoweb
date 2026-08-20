<?php

declare(strict_types=1);

namespace App\Operacion\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Operacion\ApiPlatform\Dto\EmitirOrdenInput;
use App\Operacion\Entity\OperacionOrdenServicio;
use App\Operacion\Entity\OperacionServicio;
use App\Operacion\Service\OperacionOrdenEmision;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\Uid\Uuid;

/**
 * Crea una Orden de Servicio entera **en una sola llamada**.
 *
 * Antes esto eran *N* `PATCH` para atar cada fila más un `POST` para la cabecera, orquestados
 * desde el navegador. Si la pestaña se caía en medio quedaban filas atadas a una orden que no
 * llegó a existir, y las reglas de coherencia vivían la mitad en `conflictoSeleccion` del
 * front: dos pestañas abiertas podían armar lo que la vista impedía.
 *
 * Aquí se valida **antes de tocar nada**, se crea, se enlaza y se congela dentro de la misma
 * transacción, y se devuelve la orden ya completa para que el front no tenga que recargar a
 * ciegas.
 *
 * ⚠️ El aviso al proveedor **no va aquí**. Un fallo del correo tumbaría una orden que ya está
 * bien creada; se despacha aparte, después de que esto confirme.
 */
/**
 * ⚠️ Genérico en `mixed`: API Platform le pasa lo que sea y esto delega lo que no reconoce.
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
final readonly class EmitirOrdenProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private OperacionOrdenEmision $emision,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof EmitirOrdenInput) {
            throw new DomainException('Entrada no reconocida para emitir una orden.');
        }

        $servicios = $this->cargar($data->servicioIds);

        // ⚠️ La anulación va ANTES de validar, y el orden no es cosmético: `validar()` rechaza
        // un servicio que ya esté en otra orden, y al reemitir las filas siguen atadas a la
        // que se sustituye. Validando primero, reemitir fallaba SIEMPRE con «ya está en la
        // orden OS-014» — la orden que uno mismo acaba de pedir que se anule.
        $anterior = $this->anteriorAAnular($data->reemplazaAId);
        $anterior !== null && $this->emision->anular($anterior);

        $this->emision->validar($servicios);

        $orden = new OperacionOrdenServicio();
        $orden->setNumeroOs(trim($data->numeroOs));
        $orden->setFile($servicios[0]->getFile());

        // El comprador viaja explícito porque el operador puede cambiarlo en el modal; si no
        // lo manda, se toma el efectivo de las filas, que `validar()` ya comprobó que es uno.
        $orden->setCompradorMaestroId($data->compradorMaestroId ?: $servicios[0]->getCompradorEfectivoMaestroId());
        $orden->setCompradorNombre($data->compradorNombre ?: $servicios[0]->getCompradorEfectivoNombre());

        foreach ($servicios as $servicio) {
            $servicio->setOrdenServicio($orden);
            $orden->addOperacionServicio($servicio);
        }

        // Reemitir = anular la anterior y crear la sucesora. Nunca reescribir el documento
        // que el proveedor ya tiene. La anulación ya ocurrió arriba; aquí sólo se deja escrita
        // la cadena, que es lo que se consulta cuando un proveedor reclama.
        $orden->setReemplazaA($anterior);

        if (!$data->soloBorrador) {
            $this->emision->emitir($orden);
        }

        $this->em->persist($orden);
        $this->em->flush();

        return $orden;
    }

    /** La orden que se sustituye, si se está reemitiendo. */
    private function anteriorAAnular(?string $id): ?OperacionOrdenServicio
    {
        if ($id === null || !Uuid::isValid($id)) {
            return null;
        }

        $anterior = $this->em->find(OperacionOrdenServicio::class, Uuid::fromString($id));

        return $anterior instanceof OperacionOrdenServicio ? $anterior : null;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<OperacionServicio>
     */
    private function cargar(array $ids): array
    {
        $servicios = [];

        // Los ids ya vienen validados como UUID por `EmitirOrdenInput`: aquí sólo queda
        // comprobar que la fila siga existiendo.
        foreach ($ids as $id) {
            $servicio = $this->em->find(OperacionServicio::class, Uuid::fromString($id));

            if (!$servicio instanceof OperacionServicio) {
                throw new DomainException('Uno de los servicios elegidos ya no existe en La Biblia. Recarga y vuelve a intentarlo.');
            }

            $servicios[] = $servicio;
        }

        return $servicios;
    }
}
