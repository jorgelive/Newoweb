<?php

declare(strict_types=1);

namespace App\Cotizacion\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Cotizacion\ApiPlatform\Dto\HallazgoCoherencia;
use App\Cotizacion\ApiPlatform\Dto\InformeCoherencia;
use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Service\CoherenciaCatalogoChecker;

/**
 * Revisa —y opcionalmente repara— la coherencia de UNA cotización.
 *
 * Dos operaciones distintas y no un parámetro, a propósito: mirar y escribir merecen permisos y
 * rastro distintos, y un `?reparar=1` acaba disparándose desde un enlace copiado.
 *
 * @implements ProcessorInterface<Cotizacion, InformeCoherencia>
 */
final class RevisarCoherenciaProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly CoherenciaCatalogoChecker $checker,
        private readonly bool $reparar = false,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): InformeCoherencia
    {
        // `read: true` garantiza la entidad; el id de la URI no hace falta como respaldo.
        $id = (string) $data->getId();

        $hallazgos = $this->checker->revisar($this->reparar, $id);

        $reparables = [];
        $manuales = [];
        foreach ($hallazgos as $h) {
            $hallazgo = new HallazgoCoherencia($h['clave'], $h['titulo'], $h['detalle'], $h['filas']);

            if ($h['reparable']) {
                $reparables[] = $hallazgo;
            } else {
                $manuales[] = $hallazgo;
            }
        }

        return new InformeCoherencia($reparables, $manuales, $this->reparar);
    }
}
