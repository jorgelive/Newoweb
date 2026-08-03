<?php

declare(strict_types=1);

namespace App\Api\Processor\Pms;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Service\Finance\PmsMonedaBaseService;
use DomainException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Processor de `POST /pms/pms_informacion_financieras/{id}/moneda-base`.
 *
 * NO es un PATCH sobre `moneda` a propósito: cambiar la moneda base no es escribir un campo,
 * es una operación con efectos (reescribe los cargos, rellena tipos de cambio y recalcula los
 * totales). Un PATCH normal daría la falsa impresión de que se puede revertir cambiando el
 * campo de vuelta, y además necesita un tipo de cambio que no es un atributo de la cabecera.
 *
 * @implements ProcessorInterface<PmsInformacionFinanciera, PmsInformacionFinanciera>
 */
final class PmsCambiarMonedaBaseProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly PmsMonedaBaseService $monedaBaseService,
    ) {}

    /**
     * @param PmsInformacionFinanciera $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PmsInformacionFinanciera
    {
        $payload = $context['request']?->toArray() ?? [];

        $moneda = $payload['moneda'] ?? null;
        if (!is_string($moneda) || $moneda === '') {
            throw new UnprocessableEntityHttpException('Falta la moneda base de destino.');
        }

        $tipoCambio = $payload['tipoCambio'] ?? null;
        $tipoCambio = is_string($tipoCambio) || is_numeric($tipoCambio) ? (string) $tipoCambio : null;

        try {
            $this->monedaBaseService->cambiar($data, $moneda, $tipoCambio ?: null);
        } catch (DomainException $e) {
            // 422 y no 500: es una regla de negocio, y el mensaje se le enseña al operador.
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return $data;
    }
}
