<?php

namespace App\EventListener;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpKernel\Event\RequestEvent;

class KernelRequestKernelEventListener
{
    private ManagerRegistry $doctrine;

    public function __construct(ManagerRegistry $doctrine)
    {
        $this->doctrine = $doctrine;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $session = $request->getSession();

        if ($session->get('preferred_language') !== 'si') {
            $locale = $request->getPreferredLanguage();

            if (str_starts_with($locale, 'es')) {
                $session->set('_locale', 'es');
                $request->setLocale('es');
            } else {
                $session->set('_locale', 'en');
                $request->setLocale('en');
            }

            $session->set('preferred_language', 'si');
        }
    }
}