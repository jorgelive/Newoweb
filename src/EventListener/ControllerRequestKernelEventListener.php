<?php

namespace App\EventListener;

use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;


class ControllerRequestKernelEventListener
{
    public ManagerRegistry $doctrine;
    public RequestStack $requestStack;

    public function __construct(ManagerRegistry $doctrine, RequestStack $requestStack)
    {
        $this->doctrine = $doctrine;
        $this->requestStack = $requestStack;

    }

    public function onControllerRequest(ControllerEvent $event)
    {
        if (!$event->isMainRequest()) {
            return;
        }

    }
}