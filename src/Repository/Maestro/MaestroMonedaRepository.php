<?php

namespace App\Repository\Maestro;

use App\Entity\Maestro\MaestroMoneda;
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
        return $this->find('USD');
    }

    /**
     * Devuelve una moneda por su código ISO.
     */
    public function findById(string $id): ?MaestroMoneda
    {
        return $this->find($id);
    }
}