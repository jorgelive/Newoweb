<?php

declare(strict_types=1);

namespace App\Cotizacion\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionCotservicio;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use App\Operacion\Entity\OperacionServicio;
use App\Operacion\Enum\EstadoOperacionEnum;
use App\Operacion\Service\BibliaSnapshotService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Abre la propuesta OPERATIVA de una confirmada: lo que de verdad se va a operar.
 *
 * ── El clon mira hacia ADELANTE ─────────────────────────────────────────────
 * Misma forma que {@see GuardarHistoricoProcessor}, sentido contrario: el histórico congela el
 * pasado y esto abre el futuro. Las dos son otra fila de la **misma propuesta** —mismo número,
 * distinguidas por estado— y las dos cuelgan de la viva por `derivadaDe`.
 *
 * ── ⚠️ La operación se TRASPASA, y por eso va en una sola transacción ───────
 * Las filas de operación cuelgan de un `cotservicio`, o sea de una cotización concreta. Si la
 * operativa generase las suyas mientras la confirmada conserva las suyas activas, **el cuadro
 * mostraría los mismos días dos veces** — el escenario que `CotizacionConfirmadaEventListener`
 * describe como «riesgo de pedirle y pagarle dos veces lo mismo al proveedor».
 *
 * Aquí se cancelan las de la confirmada y nacen las de la operativa **en el mismo flush**: no hay
 * ningún instante con las dos vivas.
 *
 * ── ⚠️ Por qué es una acción explícita y no un efecto de confirmar ──────────
 * El plan lo describía naciendo sola al confirmar. Se hizo explícita a propósito, y el motivo es
 * mecánico: crear y persistir una entidad **dentro del `onFlush`** que confirma obliga a gimnasia
 * con la unidad de trabajo, y ahí es donde se rompen las cosas de forma difícil de ver. Con una
 * acción propia el traspaso queda contenido en una transacción legible.
 *
 * El resultado para el operador es el mismo —la confirmada se congela, la operación pasa a la
 * operativa— con una diferencia que además se pidió: **decide él cuándo abrirla**.
 *
 * ── Idempotente ─────────────────────────────────────────────────────────────
 * Si ya hay una operativa para esa propuesta, se devuelve. Abrir dos sería tener dos sitios donde
 * mirar qué se va a operar.
 *
 * @implements ProcessorInterface<Cotizacion, Cotizacion>
 */
final readonly class AbrirOperativaProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private BibliaSnapshotService $snapshot,
    ) {
    }

    /**
     * @param Cotizacion $data
     *
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Cotizacion
    {
        if ($data->getEstado() !== CotizacionEstadoEnum::CONFIRMADO) {
            throw new UnprocessableEntityHttpException(
                'Sólo se abre la operativa de una propuesta confirmada: es lo vendido, y es lo que se congela al abrirla.'
            );
        }

        $padre = $data->getFile() ?? $data->getCatalogo();

        if ($padre === null) {
            throw new UnprocessableEntityHttpException('La propuesta no cuelga de ningún expediente.');
        }

        $yaExiste = $this->em->getRepository(Cotizacion::class)->findOneBy([
            $data->getFile() !== null ? 'file' : 'catalogo' => $padre,
            'propuesta' => $data->getPropuesta(),
            'estado' => CotizacionEstadoEnum::OPERATIVA,
        ]);

        if ($yaExiste !== null) {
            return $yaExiste;
        }

        $operativa = $data->duplicar();
        $operativa->setPropuesta($data->getPropuesta());   // ⚠️ el MISMO número, a propósito
        $operativa->setEstado(CotizacionEstadoEnum::OPERATIVA);
        $operativa->setDerivadaDe($data);

        // ⚠️ **Nace SIN publicar**, y eso es lo que la convierte en borrador: mientras se
        // reorganiza, el cliente sigue viendo el itinerario de la confirmada en vez de una página
        // a medias. Se publica cuando esté lista.
        $operativa->setPublicado(false);

        // ⚠️ **El financiero del cliente se HEREDA tal cual.** Ya se vendió y ya se cobró: lo que
        // pase en la operación es un tema proveedor–agencia. El interno sí se recalculará con las
        // cantidades reales, y de ahí saldrán las órdenes.
        $operativa->setClasificacionFinancieraCliente($data->getClasificacionFinancieraCliente());

        // ⚠️ **Dos flushes, y por eso una transacción explícita.** Las filas de operación cuelgan
        // de los `cotservicio` de la operativa, que no tienen id hasta que el primer flush los
        // inserta. Entre los dos flushes hay un instante con la confirmada ya apagada y la
        // operativa todavía sin generar: fuera de una transacción, un fallo ahí dejaría el
        // expediente **sin ninguna fila de operación viva** y sin nada que lo denunciara.
        $this->em->wrapInTransaction(function () use ($operativa, $data): void {
            $this->em->persist($operativa);

            // Se apagan las de la confirmada ANTES de generar las nuevas: nunca hay un instante
            // con las dos vivas, que es el escenario del «pedirle y pagarle dos veces al
            // proveedor».
            $this->cancelarOperacionDe($data);

            $this->em->flush();

            // 🔥 **Explícito, y no por el listener.** `CotizacionConfirmadaEventListener` sólo
            // recorre `getScheduledEntityUpdates()`, y la operativa nace como **inserción**: su
            // `case OPERATIVA` —añadido el 02/09/2026— no llega a pisarse nunca por esta vía. Se
            // comprobó con datos reales: la operativa nacía con **cero filas** y ni un error.
            //
            // El `case` del listener se queda igualmente, porque sí cubre la otra puerta: mover
            // a OPERATIVA una fila que ya existe. Y `generarParaCotizacion()` es idempotente
            // —salta el componente que ya tiene su fila—, así que las dos vías no se pisan.
            $this->snapshot->generarParaCotizacion($operativa);

            $this->em->flush();
        });

        return $operativa;
    }

    /**
     * Cancela las filas de operación de una cotización.
     *
     * ⚠️ DQL y no hidratación: son decenas de filas con varios JSON grandes dentro, y cargarlas
     * enteras para tocarles un enum es cómo se llega a un «Out of sort memory». Mismo motivo por
     * el que `$historicos` es `EXTRA_LAZY`.
     *
     * 🔥 **En DOS consultas, y no en una con subconsulta.** La forma natural
     * —`WHERE os.cotizacionServicio IN (SELECT cs.id ...)`— **se cuelga**: MySQL la degrada a un
     * escaneo por fila y se midió **más de 45 segundos** sobre una cotización real de 17
     * servicios, dejando la transacción abierta y bloqueando la tabla. No da error: tarda.
     *
     * 🔥 **Y los ids van en BINARIO, con `ArrayParameterType::BINARY`.** Es la tercera vez que
     * esta trampa muerde en este módulo y la primera en que muerde dentro de un `IN`: un `Uuid`
     * pasado tal cual **no casa ninguna fila y no da error**. La consulta decía haber cancelado y
     * las 47 filas seguían activas — se vio sólo porque el sondeo con datos reales las contaba en
     * SQL crudo. Con `UuidType` basta para un valor suelto (lo hace
     * `CotizacionPublicadaEventListener`); para una lista hace falta además el tipo de array.
     *
     * ⚠️ **Lo COMPLETADO no se toca.** Un servicio que se operó el martes se operó, y abrir la
     * operativa el viernes no lo deshace: pisarlo borraría el registro de lo que de verdad
     * ocurrió. Es la misma regla que ya aplica `CotizacionConfirmadaEventListener`, y aquí hay
     * que repetirla porque este camino no pasa por él.
     */
    private function cancelarOperacionDe(Cotizacion $cotizacion): void
    {
        /** @var list<array{id: \Symfony\Component\Uid\Uuid}> $filas */
        $filas = $this->em->createQuery(sprintf(
            'SELECT cs.id FROM %s cs WHERE cs.cotizacion = :cotizacion',
            CotizacionCotservicio::class,
        ))
            ->setParameter('cotizacion', $cotizacion->getId(), UuidType::NAME)
            ->getArrayResult();

        $ids = array_map(static fn (array $f): string => $f['id']->toBinary(), $filas);

        if ($ids === []) {
            return;
        }

        $this->em->createQuery(sprintf(
            'UPDATE %s os SET os.estadoOperacion = :cancelado
             WHERE os.cotizacionServicio IN (:servicios) AND os.estadoOperacion != :completado',
            OperacionServicio::class,
        ))
            ->setParameter('cancelado', EstadoOperacionEnum::CANCELADO->value)
            ->setParameter('completado', EstadoOperacionEnum::COMPLETADO->value)
            ->setParameter('servicios', $ids, ArrayParameterType::BINARY)
            ->execute();
    }
}
