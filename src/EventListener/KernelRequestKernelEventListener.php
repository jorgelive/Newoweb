<?php

namespace App\EventListener;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpKernel\Event\RequestEvent;

class KernelRequestKernelEventListener
{
    public function __construct(private ManagerRegistry $doctrine) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $session = $request->getSession();

        // 1) Lee locale del switcher (ruta o query)
        $locale = $request->attributes->get('_locale') ?? $request->query->get('_locale');

        // 2) Si no viene, usa lo de sesión
        if (!$locale && $session->has('_locale')) {
            $locale = $session->get('_locale');
        }

        // 3) Fallback a Accept-Language o 'es'
        if (!$locale) {
            $preferred = $request->getPreferredLanguage(['es', 'en']) ?: 'es';
            $locale = str_starts_with($preferred, 'es') ? 'es' : 'en';
        }

        // 4) Persistir y fijar SIEMPRE (elimina la bandera preferred_language)
        $session->set('_locale', $locale);
        $request->setLocale($locale);
    }
}
