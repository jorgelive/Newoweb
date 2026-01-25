<?php
namespace App\Oweb\EventListener;

use App\Oweb\Entity\CuentaMovimiento;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class CuentaMovimientoDoctrineEventListener
{
    private $tokenStorage;

    public function __construct(TokenStorageInterface $tokenStorage)
    {
        $this->tokenStorage = $tokenStorage;

    }

    public function prePersist(PrePersistEventArgs $args)
    {
        $entity = $args->getObject();
        if($entity instanceof CuentaMovimiento){
            if(!$entity->getUser()) {
                $entity->setUser($this->tokenStorage->getToken()->getUser());
            }
        }
    }
}