<?php

namespace App\Repository;
use App\Entity\UserUser;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ReservaReservaRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class ReservaReservaRepository extends \Doctrine\ORM\EntityRepository
{
    public function findCalendartodas($data)
    {
        if (!$data['user'] instanceof UserUser) {
            throw new HttpException(500, 'El dato de usuario no es instancia de la clase App:Entity:UserUser.');
        } else {
            $user = $data['user'];
        }

        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('rr')
            ->from('App:ReservaReserva', 'rr')
            //->innerJoin('cs.cotizacion', 'cot')
            ->where('rr.fechahorainicio BETWEEN :firstDate AND :lastDate');
            //->andWhere('cot.estadocotizacion = 3');


        $qb->setParameter('firstDate', $data['from'])
            ->setParameter('lastDate', $data['to']);

        return $qb;

    }

}
