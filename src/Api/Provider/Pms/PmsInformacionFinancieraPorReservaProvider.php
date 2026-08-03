<?php

declare(strict_types=1);

namespace App\Api\Provider\Pms;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Repository\PmsInformacionFinancieraRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Provider de `GET /pms/pms_informacion_financieras/por-reserva/{reservaId}`.
 *
 * Existe porque el `SearchFilter` sobre la relación `reserva` **no funciona** con estos
 * UUID binarios: no declara el tipo Doctrine al vincular el parámetro, así que compara
 * texto contra `BINARY(16)` y devuelve una colección vacía sin dar ningún error (§12.6).
 *
 * Delega en `PmsInformacionFinancieraRepository::findOneByReservaId()`, que sí tipa el
 * parámetro. Devolver el recurso en singular es además más honesto que una colección:
 * la relación es 1:1 con la reserva.
 */
final class PmsInformacionFinancieraPorReservaProvider implements ProviderInterface
{
    public function __construct(
        private readonly PmsInformacionFinancieraRepository $repository
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?PmsInformacionFinanciera
    {
        $reservaId = $uriVariables['reservaId'] ?? null;

        // 🔴 NO comprobar `is_string()`: al declarar la variable con un `Link` sobre el `id` de
        // PmsReserva, API Platform la castea al tipo del identificador y aquí llega un OBJETO
        // `Symfony\Component\Uid\Uuid`, no una cadena. Con la guarda de string el provider
        // devolvía null y la respuesta era un 404 idéntico al de "no existe" — aunque la fila
        // estuviera en la tabla. Se normaliza a texto y que decida el repositorio.
        $reservaId = match (true) {
            $reservaId instanceof Uuid => (string) $reservaId,
            is_string($reservaId)      => $reservaId,
            default                    => null,
        };

        if ($reservaId === null || $reservaId === '') {
            return null;
        }

        // null → API Platform responde 404, que es lo correcto: esa reserva no tiene cabecera.
        return $this->repository->findOneByReservaId($reservaId);
    }
}
