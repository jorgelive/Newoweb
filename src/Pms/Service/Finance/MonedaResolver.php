<?php

declare(strict_types=1);

namespace App\Pms\Service\Finance;

use App\Entity\Maestro\MaestroMoneda;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resuelve un código de moneda entrante contra el maestro `MaestroMoneda`.
 * Regla de negocio: si no llega moneda (o el código no existe), el default es dólar (USD).
 */
final class MonedaResolver
{
    /** @var array<string, MaestroMoneda> Cache de instancia para evitar N+1 en lotes. */
    private array $cache = [];

    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    public function resolve(?string $codigo): MaestroMoneda
    {
        $id = strtoupper(trim((string) $codigo));
        if ($id === '') {
            $id = MaestroMoneda::DB_ID_USD;
        }

        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }

        $moneda = $this->em->find(MaestroMoneda::class, $id)
            ?? $this->em->getReference(MaestroMoneda::class, MaestroMoneda::DB_ID_USD);

        return $this->cache[$id] = $moneda;
    }
}
