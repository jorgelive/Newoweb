<?php

declare(strict_types=1);

namespace App\Finanzas\Repository;

use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Enum\FinEnlacePagoEstado;
use App\Finanzas\Enum\FinOrigenCobro;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<FinEnlacePago>
 */
class FinEnlacePagoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FinEnlacePago::class);
    }

    public function porToken(string $token): ?FinEnlacePago
    {
        return $this->findOneBy(['token' => $token]);
    }

    public function porOrdenId(string $ordenId): ?FinEnlacePago
    {
        return $this->findOneBy(['ordenId' => $ordenId]);
    }

    /**
     * Enlaces de un documento, del más nuevo al más viejo.
     *
     * El UUID se pasa por `setParameter` con el tipo `uuid` EXPLÍCITO. Sin él, Doctrine
     * manda la representación canónica contra una columna binaria y la consulta devuelve
     * vacío **sin error** — la misma trampa documentada en §12.6 de
     * `docs/PmsBeds24ReservasSync.md` para los SearchFilter de API Platform. Aquí muerde
     * igual porque `origen_id` no es una relación, es una columna uuid suelta.
     *
     * @return FinEnlacePago[]
     */
    public function porOrigen(FinOrigenCobro $tipo, Uuid $origenId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.origenTipo = :tipo')
            ->andWhere('e.origenId = :origenId')
            ->setParameter('tipo', $tipo->value)
            ->setParameter('origenId', $origenId, 'uuid')
            ->orderBy('e.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Búsqueda de la vista global de cobros (pestaña "Cobros" del módulo Finanzas).
     *
     * Filtra por la fecha de CREACIÓN y no por la de pago: la pregunta que se hace desde
     * esta pantalla es "qué cobros emití esta semana y en qué han quedado", y filtrando
     * por fecha de pago desaparecerían justo los que interesan — los que nadie pagó.
     *
     * El texto busca en el localizador del documento, el `orderId` de la pasarela y el
     * concepto: los tres datos con los que alguien llega preguntando por un cobro.
     *
     * @param string[] $estados Vacío = todos.
     * @return FinEnlacePago[]
     */
    public function buscar(
        array $estados = [],
        ?\DateTimeImmutable $desde = null,
        ?\DateTimeImmutable $hasta = null,
        ?string $texto = null,
        int $limite = 500,
    ): array {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.moneda', 'm')->addSelect('m')
            ->orderBy('e.createdAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limite);

        if ($estados !== []) {
            $qb->andWhere('e.estado IN (:estados)')->setParameter('estados', $estados);
        }

        if ($desde !== null) {
            $qb->andWhere('e.createdAt >= :desde')->setParameter('desde', $desde);
        }

        if ($hasta !== null) {
            $qb->andWhere('e.createdAt <= :hasta')->setParameter('hasta', $hasta);
        }

        if ($texto !== null && trim($texto) !== '') {
            $qb->andWhere($qb->expr()->orX(
                'e.origenReferencia LIKE :texto',
                'e.ordenId LIKE :texto',
                'e.concepto LIKE :texto',
                'e.clienteNombre LIKE :texto',
            ))->setParameter('texto', '%' . trim($texto) . '%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Enlaces caducados que siguen figurando como pendientes.
     *
     * La expiración no la aplica ningún cron: `FinEnlacePago::estaVigente()` ya cierra la
     * puerta al cliente en el momento de leer. Esto existe sólo para que el listado del
     * panel no muestre como "pendiente" algo que nadie puede pagar ya.
     *
     * @return FinEnlacePago[]
     */
    public function pendientesCaducados(): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.estado = :estado')
            ->andWhere('e.expiraEn IS NOT NULL')
            ->andWhere('e.expiraEn < :ahora')
            ->setParameter('estado', FinEnlacePagoEstado::PENDIENTE->value)
            ->setParameter('ahora', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }
}
