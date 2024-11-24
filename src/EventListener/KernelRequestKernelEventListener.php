<?php

namespace App\EventListener;

use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;


class KernelRequestKernelEventListener
{
    public ManagerRegistry $doctrine;
    public RequestStack $requestStack;

    public function __construct(ManagerRegistry $doctrine, RequestStack $requestStack)
    {
        $this->doctrine = $doctrine;
        $this->requestStack = $requestStack;

        //$request = $this->requestStack->getMainRequest();

        //$selectedLocale = $request->get('locale');


    }

    public function onKernelRequest(KernelEvent $event)
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $this->requestStack->getMainRequest();

        if($request->getSession()->get('preferredlanguage') != 'si'){
            $locale = $request->getPreferredLanguage();

            if(str_starts_with($locale, 'es')){
                $request->getSession()->set('_locale', 'es');
                $request->setLocale('es');
            }else{
                $request->getSession()->set('_locale', 'en');
                $request->setLocale('en');
            }

            $request->getSession()->set('preferredlanguage', 'si');
        }
    }
}