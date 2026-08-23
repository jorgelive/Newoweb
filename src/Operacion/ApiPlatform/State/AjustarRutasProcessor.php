<?php

declare(strict_types=1);

namespace App\Operacion\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Operacion\ApiPlatform\Dto\AjustarRutasInput;
use App\Operacion\Entity\OperacionOrdenServicio;
use App\Operacion\Entity\OperacionOrdenServicioItem;
use App\Operacion\Enum\EstadoOrdenServicioEnum;
use App\Operacion\Enum\VisibilidadPuntoEnum;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

/**
 * Cambia qué extremos ve el proveedor **en una orden ya emitida**, sin reemitirla.
 *
 * ── Por qué esto no rompe la regla del documento inmutable ──────────────────
 * Porque la regla nunca fue «el ítem no se toca», sino **«el pacto no se toca»**. El módulo ya
 * distinguía tres clases de cambio y ya editaba ítems emitidos:
 *
 * ```
 * PACTO         importes, fechas, pax, qué servicios   →  anular + reemitir
 * COMPLETADO    lo que era NULO y ahora se sabe        →  aplicarCambiosMenores()
 * PRESENTACIÓN  qué renglones se imprimen              →  esto
 * ```
 *
 * Ocultar un renglón **no afirma nada falso: dice menos**. Cambiar el TEXTO de un punto sí sería
 * pacto, y eso sigue pasando por anular y emitir la sucesora.
 *
 * ── ⚠️ Escribe el ítem Y la fila viva ───────────────────────────────────────
 * El ítem manda sobre ESTE documento; la fila viva es la semilla del siguiente. Si sólo se tocara
 * el ítem, **reemitir resucitaría el renglón que el operador acaba de ocultar** — y lo haría en
 * silencio, semanas después, en un documento que nadie va a comparar con el anterior.
 *
 * ── Puerta dedicada, no un PATCH ────────────────────────────────────────────
 * El ítem no es `ApiResource` y no debe serlo: abrirlo a PATCH genérico expondría toda la línea
 * congelada —importes incluidos— a una escritura sin reglas. Es la misma doctrina que el cambio
 * de estado: con dos puertas a la misma transición, las reglas se escapan por la que no mira nadie.
 *
 * @implements ProcessorInterface<AjustarRutasInput, OperacionOrdenServicio>
 */
final readonly class AjustarRutasProcessor implements ProcessorInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): OperacionOrdenServicio
    {
        $orden = $this->em->find(OperacionOrdenServicio::class, (string) ($uriVariables['id'] ?? ''));

        if (!$orden instanceof OperacionOrdenServicio) {
            throw new DomainException('La orden no existe. Recarga y vuelve a intentarlo.');
        }

        // En borrador no hay ítems: lo que se edita es la fila viva, como cualquier otro override.
        // Y una anulada es terminal — retocarle la presentación a un documento que ya no vale sólo
        // sirve para dudar de si sigue vigente.
        if (!in_array($orden->getEstadoOs(), [EstadoOrdenServicioEnum::EMITIDA, EstadoOrdenServicioEnum::CONFIRMADA], true)) {
            throw new DomainException(sprintf(
                'La orden %s está %s: esto se ajusta sobre una orden emitida.',
                $orden->getNumeroOs(),
                mb_strtolower($orden->getEstadoOs()->name)
            ));
        }

        /** @var array<string, OperacionOrdenServicioItem> $porId */
        $porId = [];

        foreach ($orden->getItems() as $item) {
            $id = $item->getId()?->toRfc4122();

            if ($id !== null) {
                $porId[$id] = $item;
            }
        }

        foreach ($data->visibilidad as $itemId => $lados) {
            // Lista blanca: el ítem tiene que ser DE ESTA orden. Sin esto, un id de otra orden
            // pasaría — y editar la presentación de un documento ajeno desde su hermana es la clase
            // de puerta que nadie vuelve a encontrar.
            $item = $porId[(string) $itemId] ?? null;

            if ($item === null) {
                throw new DomainException('Una de las líneas no pertenece a esta orden. Recarga y vuelve a intentarlo.');
            }

            $servicio = $this->vivoDe($orden, $item);

            if (isset($lados['recojo'])) {
                $v = $this->leer($lados['recojo']);
                $item->setVisibilidadRecojo($v);
                $servicio?->setVisibilidadRecojo($v);
            }

            if (isset($lados['entrega'])) {
                $v = $this->leer($lados['entrega']);
                $item->setVisibilidadEntrega($v);
                $servicio?->setVisibilidadEntrega($v);
            }
        }

        $this->em->flush();

        return $orden;
    }

    /**
     * La fila viva de la que salió este ítem, si sigue enlazada.
     *
     * Puede no estarlo: una orden anulada suelta sus servicios. Aquí no debería pasar —sólo se
     * entra en emitida o confirmada— pero devolver `null` en vez de reventar deja el ajuste hecho
     * en el documento, que es lo que se estaba pidiendo.
     */
    private function vivoDe(OperacionOrdenServicio $orden, OperacionOrdenServicioItem $item): ?\App\Operacion\Entity\OperacionServicio
    {
        foreach ($orden->getOperacionServicios() as $servicio) {
            if ((string) $servicio->getId() === (string) $item->getOperacionServicioId()) {
                return $servicio;
            }
        }

        return null;
    }

    private function leer(string $valor): VisibilidadPuntoEnum
    {
        return VisibilidadPuntoEnum::tryFrom($valor)
            ?? throw new DomainException(sprintf('«%s» no es una visibilidad válida.', $valor));
    }
}
