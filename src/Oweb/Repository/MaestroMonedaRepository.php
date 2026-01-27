<?php

namespace App\Oweb\Repository;

use App\Oweb\Entity\MaestroMoneda;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MaestroMonedaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MaestroMoneda::class);
    }

    /**
     * Devuelve la moneda USD por código.
     */
    public function findUsd(): ?MaestroMoneda
    {
        return $this->findOneBy(['codigo' => 'USD']);
    }

    /**
     * Devuelve una moneda por su código ISO.
     */
    public function findByCodigo(string $codigo): ?MaestroMoneda
    {
        return $this->findOneBy(['codigo' => $codigo]);
    }
}