<?php

namespace App\EventListener;

use App\Controller\Shop\HomepageController;
use App\Entity\SeoUrl;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ControllerEvent;


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

        $request = $this->requestStack->getMainRequest();

        $changeLocale = $request->get('_changelocale');
        //todo limpier el parametro _changelocale despues del cambio de idioma
        //$referer = $request->headers->get('referer');

        if($changeLocale === 'en'){
            $request->getSession()->set('_locale', $changeLocale);
            $request->setLocale($changeLocale);
            //$locale = $request->getSession()->get('_locale');
        }
        //if(str_contains($this->requestStack->getMainRequest()->getPathInfo(), '/admin')) {
        //    return;
        //}

        //$em = $this->doctrine->getManager();
        //$pathInfo = $this->requestStack->getMainRequest()->getPathInfo();

    }
}