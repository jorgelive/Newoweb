<?php

namespace App\EventListener;

use Gedmo\Translatable\TranslatableListener;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

#[AsEventListener(
    event: 'kernel.request',
    method: 'onKernelRequest',
    priority: 101
)]
class KernelRequestKernelEventListener
{
    public function __construct(
        private TranslatableListener $translatableListener
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $session = $request->hasSession() ? $request->getSession() : null;

        $fromSwitcher = false;

        // 1) Switcher explícito (?_locale=es / ruta)
        $locale = $request->attributes->get('_locale')
            ?? $request->query->get('_locale');

        if ($locale) {
            $fromSwitcher = true;
        }

        // 2) Sesión (si ya eligió antes)
        if (!$locale && $session && $session->has('_locale')) {
            $locale = $session->get('_locale');
            $fromSwitcher = true;
        }

        // 3) Detección automática (primera visita)
        if (!$locale) {
            $preferred = $request->getPreferredLanguage(['es', 'en']) ?: 'es';
            $locale = str_starts_with($preferred, 'es') ? 'es' : 'en';
        }

        // 4) Aplicar locale activo (Symfony)
        $request->setLocale($locale);

        // 5) ✅ Aplicar SIEMPRE a Gedmo
        $this->translatableListener->setTranslatableLocale($locale);
        $this->translatableListener->setDefaultLocale($locale);

        // 6) Persistir SOLO si fue elección del usuario
        if ($fromSwitcher && $session) {
            $session->set('_locale', $locale);
        }
    }
}