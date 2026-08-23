<?php

declare(strict_types=1);

namespace App\Cotizacion\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Congela una foto de la cotización ANTES de tocarla.
 *
 * ## Por qué clona hacia atrás, y no hacia adelante como «Nueva propuesta»
 *
 * {@see CloneCotizacionProcessor} hace que la copia sea **la nueva**: v2, pendiente, y la v1 se
 * queda como estaba. Eso vale mientras se está vendiendo, cuando el cliente elige entre opciones.
 *
 * **Después de vender rompe la operación.** Las órdenes cuelgan de `OperacionServicio`, que cuelga
 * de los COMPONENTES de la cotización. Para que La Biblia no muestre los mismos días dos veces hay
 * que sacar la v1 de `confirmado`, y eso cancela sus filas — justo a las que están enganchadas las
 * órdenes ya emitidas. La v2 nace con componentes de UUID nuevo, filas nuevas y **ninguna orden**:
 * hay que reemitirlo todo, incluidos los servicios que no cambiaron.
 *
 * Clonando hacia atrás no se mueve nada de eso. **La copia es el pasado** y la cotización viva
 * conserva su id, sus componentes, sus filas y sus órdenes; lo que cambie después lo denuncia
 * {@see \App\Operacion\Entity\OperacionOrdenServicio::getDivergencias()}, que ya distingue lo que
 * obliga a reemitir de lo que sólo hay que completar.
 *
 * ## El histórico NO consume número de versión
 *
 * El histórico de la v1 sigue siendo v1, distinguido por su fecha. Las versiones son *propuestas*
 * —lo que el cliente eligió entre varias—; esto es el rastro de lo que ya se le vendió, y gastar
 * un número haría que la siguiente propuesta real se llamara v3 sin que existiera ninguna v2.
 *
 * ⚠️ Como consecuencia, `(file_id, version)` **no puede ser único** y quien busque por versión
 * tiene que filtrar el estado en la misma consulta. Ver `CotizacionFilePublicProvider`.
 *
 * @implements ProcessorInterface<Cotizacion, Cotizacion>
 */
final class GuardarHistoricoProcessor implements ProcessorInterface
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Cotizacion
    {
        // Sin guarda de nulo: con `read: true`, API Platform devuelve 404 antes de llegar aquí si
        // el id no existe.
        //
        // Un histórico de un histórico no dice nada nuevo: la foto ya está congelada, y encadenar
        // copias sólo consigue que nadie sepa cuál era la viva.
        if ($data->getEstado()->esHistorico()) {
            throw new UnprocessableEntityHttpException('Esto ya es un histórico: no se le puede sacar otra foto.');
        }

        $foto = $data->duplicar();
        $foto->setVersion($data->getVersion());   // ⚠️ el MISMO número, a propósito
        $foto->setEstado(CotizacionEstadoEnum::HISTORICO);
        $foto->setDerivadaDe($data);

        $this->em->persist($foto);
        $this->em->flush();

        return $foto;
    }
}
