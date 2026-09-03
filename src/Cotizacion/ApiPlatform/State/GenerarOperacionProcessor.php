<?php

declare(strict_types=1);

namespace App\Cotizacion\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use App\Operacion\Service\BibliaSnapshotService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Arma el cuadro de operación de una cotización: una fila de La Biblia por componente.
 *
 * ── ⚠️ Es una DECISIÓN, no un efecto de confirmar (02/09/2026) ──────────────
 * Hasta hoy esto ocurría solo al pasar a CONFIRMADO. Se separó a petición del operador, y el
 * motivo es que son dos actos distintos que ocurren en momentos distintos: **confirmar es
 * comercial** —el cliente aceptó— y puede pasar semanas antes de que la operación esté lista para
 * armarse. Encadenadas, el cuadro nacía con lo que hubiera ese día y había que corregirlo después.
 *
 * Separadas, el operador arma cuando tiene con qué.
 *
 * ⚠️ Lo que **sí** sigue siendo automático es **reactivar**: si una cotización se cancela por
 * error y se vuelve a confirmar, sus filas vuelven de `cancelado` solas. Eso no crea nada — ver
 * `CotizacionConfirmadaEventListener::reactivar()`.
 *
 * ── Idempotente, y por eso el botón no es peligroso ─────────────────────────
 * `generarParaCotizacion()` salta el componente que ya tiene su fila, así que pulsarlo dos veces
 * no duplica nada y pulsarlo tras añadir servicios **completa lo que falta sin tocar lo demás**.
 * Es la forma de uso esperada, no un accidente.
 *
 * ⚠️ Y no revive lo cancelado a propósito: una fila que el operador apagó se queda apagada. Si se
 * regenerara al completar el cuadro, cada uso del botón desharía decisiones suyas.
 *
 * ── Dónde se puede armar ────────────────────────────────────────────────────
 * Sólo donde la operación puede vivir: una CONFIRMADA o una OPERATIVA. En una propuesta que aún
 * se está vendiendo, el cuadro no significa nada — y en un histórico, menos: es una foto.
 *
 * @implements ProcessorInterface<Cotizacion, Cotizacion>
 */
final readonly class GenerarOperacionProcessor implements ProcessorInterface
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
        if (!in_array($data->getEstado(), [CotizacionEstadoEnum::CONFIRMADO, CotizacionEstadoEnum::OPERATIVA], true)) {
            throw new UnprocessableEntityHttpException(
                'La operación sólo se arma sobre una propuesta confirmada o su operativa: '
                . 'en una que todavía se está vendiendo, el cuadro no significa nada.'
            );
        }

        $this->snapshot->generarParaCotizacion($data);
        $this->em->flush();

        return $data;
    }
}
