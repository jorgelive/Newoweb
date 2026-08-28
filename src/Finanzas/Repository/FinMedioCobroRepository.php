<?php

declare(strict_types=1);

namespace App\Finanzas\Repository;

use App\Finanzas\Entity\FinMedioCobro;
use App\Finanzas\Enum\FinAudienciaCobro;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FinMedioCobro>
 */
class FinMedioCobroRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FinMedioCobro::class);
    }

    /**
     * Los medios que se le pueden ofrecer a alguien, ya ordenados.
     *
     * El filtro por audiencia se hace **en PHP y no en el WHERE** a propósito: la regla vive en
     * {@see FinAudienciaCobro::aplicaA()}, que es la que también consulta el panel, y duplicarla
     * en un DQL es garantizar que un día digan cosas distintas. Son doce filas: no hay nada que
     * optimizar aquí.
     *
     * @param bool|null $desdePeru `null` = no se sabe, y entonces pasan todos. Ver
     *                              `ConsultarMediosPagoSkill::esDePeru()`.
     * @param int|null  $dias       Días que faltan para la llegada. `null` = no se sabe (una
     *                              cotización sin fechas), y entonces tampoco se esconde nada.
     *                              Es lo que retira Western Union cuando ya no da tiempo
     *                              ({@see FinMedioCobro::llegaATiempo()}).
     *
     * @return list<FinMedioCobro>
     */
    public function ofrecibles(?bool $desdePeru = null, ?int $dias = null): array
    {
        $medios = $this->createQueryBuilder('m')
            ->where('m.activo = true')
            ->orderBy('m.orden', 'ASC')
            ->addOrderBy('m.banco', 'ASC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $medios,
            static fn (FinMedioCobro $m) => $m->esOfrecible()
                && $m->getAudiencia()->aplicaA($desdePeru)
                && $m->llegaATiempo($dias)
        ));
    }
}
