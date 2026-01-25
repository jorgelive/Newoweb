<?php

namespace App\Oweb\EventListener;

use Gedmo\Translatable\TranslatableListener;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Gestiona el locale y sincroniza con Gedmo sin anular el idioma por defecto.
 */
#[AsEventListener(
    event: 'kernel.request',
    method: 'onKernelRequest',
    priority: 101
)]
class KernelRequestKernelEventListener
{
    public function __construct(
        private TranslatableListener $translatableListener,
        private string $owebHost,
        private string $defaultAppLocale = 'es' // Inyectado desde framework.default_locale
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ($request->getHost() !== $this->owebHost) {
            return;
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        $fromSwitcher = false;

        $locale = $request->attributes->get('_locale') ?? $request->query->get('_locale');

        if ($locale) {
            $fromSwitcher = true;
        }

        if (!$locale && $session && $session->has('_locale')) {
            $locale = $session->get('_locale');
            $fromSwitcher = true;
        }

        if (!$locale) {
            $preferred = $request->getPreferredLanguage(['es', 'en']) ?: $this->defaultAppLocale;
            $locale = str_starts_with($preferred, 'es') ? 'es' : 'en';
        }

        // 1. Aplicar Locale a la Request de Symfony
        $request->setLocale($locale);

        // 2. 🔥 CONFIGURACIÓN CORRECTA DE GEDMO
        // Establece el idioma que queremos VER
        $this->translatableListener->setTranslatableLocale($locale);

        // Mantenemos el 'es' como base para que el Fallback funcione
        $this->translatableListener->setDefaultLocale($this->defaultAppLocale);

        // Si el idioma actual es el default, Gedmo NO debe buscar en la tabla de traducciones,
        // debe leer directamente de la entidad principal.
        $this->translatableListener->setTranslationFallback(true);

        if ($fromSwitcher && $session) {
            $session->set('_locale', $locale);
        }
    }
}