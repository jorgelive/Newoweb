<?php

declare(strict_types=1);

namespace App\Pms\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Pms\ApiPlatform\Dto\PmsTarifaMasivaInput;
use App\Pms\ApiPlatform\Dto\PmsTarifaMasivaResult;
use App\Pms\Service\Tarifa\Dto\GeneradorTarifaMasivaDto;
use App\Pms\Service\Tarifa\Generator\GeneradorTarifaMasivaService;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Processor de POST /pms/pms_tarifa_rangos/generar-masivo.
 *
 * No duplica lógica: traduce el input HTTP al DTO interno y delega en
 * GeneradorTarifaMasivaService, el mismo servicio que usa la acción
 * "Generar Masivo" de EasyAdmin. El push a Beds24 lo dispara después
 * Beds24RatesPushQueueListener con el flush del servicio.
 */
/** @implements ProcessorInterface<PmsTarifaMasivaInput, PmsTarifaMasivaResult> */
final class PmsTarifaMasivaProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly GeneradorTarifaMasivaService $generador,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PmsTarifaMasivaResult
    {
        if (!$data instanceof PmsTarifaMasivaInput) {
            throw new LogicException('PmsTarifaMasivaProcessor solo procesa instancias de PmsTarifaMasivaInput.');
        }

        $inicio = $this->parseFecha($data->fechaInicio, 'inicio');
        $fin = $this->parseFecha($data->fechaFin, 'fin');

        if ($fin <= $inicio) {
            throw new UnprocessableEntityHttpException('La fecha de fin debe ser posterior a la fecha de inicio.');
        }

        $dto = new GeneradorTarifaMasivaDto();
        $dto->fechaInicio = $inicio;
        $dto->fechaFin = $fin;
        $dto->porcentaje = $data->porcentaje;
        $dto->minStay = $data->minStay;
        $dto->prioridad = $data->prioridad;
        $dto->importante = $data->importante;

        $creadas = $this->generador->procesar($dto);

        return new PmsTarifaMasivaResult(
            creadas: $creadas,
            mensaje: sprintf('Proceso finalizado con éxito: %d tarifas generadas.', $creadas),
        );
    }

    private function parseFecha(?string $value, string $campo): DateTimeImmutable
    {
        if (!$value) {
            throw new UnprocessableEntityHttpException(sprintf('La fecha de %s es obligatoria.', $campo));
        }

        try {
            // Solo importa el día: el generador crea rangos con columnas `date`.
            return (new DateTimeImmutable($value))->setTime(0, 0, 0);
        } catch (\Exception) {
            throw new UnprocessableEntityHttpException(sprintf('La fecha de %s no es válida.', $campo));
        }
    }
}
