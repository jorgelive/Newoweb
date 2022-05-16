<?php
namespace App\EventListener;

use Doctrine\ORM\Event\LifecycleEventArgs;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use App\Entity\CuentaMovimiento;

class MainAsignacionusuarioListener
{
    private $tokenStorage;

    public function __construct(TokenStorageInterface $tokenStorage)
    {
        $this->tokenStorage = $tokenStorage;

    }

    public function prePersist(LifecycleEventArgs $args)
    {
        $entity = $args->getEntity();
        if ($entity instanceof CuentaMovimiento && !$entity->getUser()) {
            $entity->setUser($this->tokenStorage->getToken()->getUser());
        }
    }
}