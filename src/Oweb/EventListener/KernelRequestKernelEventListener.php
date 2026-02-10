<?php

declare(strict_types=1);

namespace App\Oweb\EventListener;

use Gedmo\Translatable\TranslatableListener;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 101)]
final class KernelRequestKernelEventListener
{
    public function __construct(
        private readonly TranslatableListener $translatableListener,
        private readonly string $owebHost,
        private readonly string $defaultAppLocale = 'es',
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // ✅ Solo Oweb
        if ($request->getHost() !== $this->owebHost) {
            return;
        }

        // ✅ Solo HTML (no API, no assets)
        if ($request->getRequestFormat() !== 'html') {
            return;
        }

        $session = $request->hasSession() ? $request->getSession() : null;

        $locale = null;

        // 1️⃣ Switch explícito ?lang=
        $lang = (string) $request->query->get('lang', '');
        if ($lang === 'es' || $lang === 'en') {
            $locale = $lang;

            if ($session) {
                $session->set('_locale', $locale);
            }
        }

        // 2️⃣ Sesión
        if (!$locale && $session && $session->has('_locale')) {
            $stored = (string) $session->get('_locale');
            if ($stored === 'es' || $stored === 'en') {
                $locale = $stored;
            }
        }

        // 3️⃣ Navegador
        if (!$locale) {
            $preferred = $request->getPreferredLanguage(['es', 'en']);
            $locale = str_starts_with((string) $preferred, 'en') ? 'en' : 'es';
        }

        // 4️⃣ Aplicar locale a Symfony
        $request->setLocale($locale);

        // 5️⃣ Gedmo (lectura correcta de traducciones)
        $this->translatableListener->setTranslatableLocale($locale);
        $this->translatableListener->setDefaultLocale($this->defaultAppLocale);
        $this->translatableListener->setTranslationFallback(true);
    }
}