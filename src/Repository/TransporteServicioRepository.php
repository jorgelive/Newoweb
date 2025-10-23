<?php


namespace App\Repository;

use Doctrine\ORM\EntityRepository;
use App\Entity\UserUser;
use Symfony\Component\HttpKernel\Exception\HttpException as HttpException;

class TransporteServicioRepository extends EntityRepository
{
    public function findCalendarConductorColored($data)
    {

        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('s')
            ->from('App\Entity\TransporteServicio', 's')
            ->where('s.fechahorafin >= :firstDate AND s.fechahorainicio <= :lastDate');

/*        if($user && $user->getDependencia() && $user->getDependencia()->getId() != 1) {
            $qb->andWhere('me.dependencia = :dependencia')
                ->setParameter('dependencia', $user->getDependencia()->getId());
        }*/

        $qb->setParameter('firstDate', $data['from'])
            ->setParameter('lastDate', $data['to'])
        ;

        /** @var UserUser|null $user */
        $user = $data['user'] ?? null;
        if ($user instanceof UserUser) {
            // Ejemplos (ajusta a tu modelo):
            /*
            $depId = $user->getDependencia()?->getId();
            if ($depId !== null && $depId !== 1) {
                $qb->andWhere('IDENTITY(me.dependencia) = :depId')
                    ->setParameter('depId', $depId);
            }

            $conductorId = $user->getConductor()?->getId();
            if ($conductorId !== null) {
                $qb->andWhere('IDENTITY(me.conductor) = :conductorId')
                    ->setParameter('conductorId', $conductorId);
            }
            */
        }

        return $qb;

    }

    public function findCalendarClienteColored($data)
    {

        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('s')
            ->from('App\Entity\TransporteServicio', 's')
            ->where('s.fechahorafin >= :firstDate AND s.fechahorainicio <= :lastDate');

        $qb->setParameter('firstDate', $data['from'])
            ->setParameter('lastDate', $data['to'])
        ;

        /** @var UserUser|null $user */
        $user = $data['user'] ?? null;
        if ($user instanceof UserUser) {
            // Ejemplos (ajusta a tu modelo):
            /*
            $depId = $user->getDependencia()?->getId();
            if ($depId !== null && $depId !== 1) {
                $qb->andWhere('IDENTITY(me.dependencia) = :depId')
                    ->setParameter('depId', $depId);
            }

            $conductorId = $user->getConductor()?->getId();
            if ($conductorId !== null) {
                $qb->andWhere('IDENTITY(me.conductor) = :conductorId')
                    ->setParameter('conductorId', $conductorId);
            }
            */
        }

        return $qb;

    }


    public function findCalendarUnidadColored($data)
    {

        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('s')
            ->from('App\Entity\TransporteServicio', 's')
            ->where('s.fechahorafin >= :firstDate AND s.fechahorainicio <= :lastDate');


        $qb->setParameter('firstDate', $data['from'])
            ->setParameter('lastDate', $data['to'])
        ;

        /** @var UserUser|null $user */
        $user = $data['user'] ?? null;
        if ($user instanceof UserUser) {
            // Ejemplos (ajusta a tu modelo):
            /*
            $depId = $user->getDependencia()?->getId();
            if ($depId !== null && $depId !== 1) {
                $qb->andWhere('IDENTITY(me.dependencia) = :depId')
                    ->setParameter('depId', $depId);
            }

            $conductorId = $user->getConductor()?->getId();
            if ($conductorId !== null) {
                $qb->andWhere('IDENTITY(me.conductor) = :conductorId')
                    ->setParameter('conductorId', $conductorId);
            }
            */
        }

        return $qb;
    }
}