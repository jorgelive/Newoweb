<?php

namespace App\Panel\EventListener;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Listener de Navegación Circular Inteligente.
 * Versión Corregida: Soporte total para Pretty URLs.
 */
class ReturnToNavigationListener
{
    private string $panelHost;

    public function __construct(string $panelHost)
    {
        $this->panelHost = $panelHost;
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 30)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) return;

        $request = $event->getRequest();

        // 1. Filtro de Host (Seguridad básica)
        if ($request->getHost() !== $this->panelHost) return;

        // 2. Ignorar rutas técnicas de Symfony/Security
        $route = $request->attributes->get('_route');
        if (in_array($route, ['app_login', 'app_logout', '_wdt', '_profiler'], true)) return;

        // 3. Ignorar llamadas AJAX (opcional, pero recomendado para no ensuciar XHR)
        if ($request->isXmlHttpRequest()) return;

        // 4. Si ya trae pasaporte explícito, confiamos en él y salimos.
        if ($request->query->has('returnTo')) return;

        // --- LÓGICA DE AUTO-GENERACIÓN ---

        // Si detectamos que es una página INDEX, forzamos la inyección.
        if ($this->isIndexPage($request)) {
            $currentUrl = $request->getUri();
            // INYECCIÓN: Modificamos la query del request actual.
            // Esto no cambia la URL del navegador, pero el BaseCrudController lo verá.
            $request->query->set('returnTo', base64_encode($currentUrl));
            return;
        }

        // Fallback: Si no es Index (ej: entré directo a Edit), intento rescatar el Referer.
        $referer = $request->headers->get('referer');
        if ($referer) {
            $refererParts = parse_url($referer);
            // Solo si viene del mismo dominio
            if (($refererParts['host'] ?? '') === $this->panelHost) {
                // Evitamos bucles: Si el referer es la misma página, no lo usamos
                if (($refererParts['path'] ?? '') !== $request->getPathInfo()) {
                    $request->query->set('returnTo', base64_encode($referer));
                }
            }
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) return;
        $request = $event->getRequest();

        $encodedReturnTo = $request->query->get('returnTo');
        if (empty($encodedReturnTo)) return;

        $response = $event->getResponse();

        if ($response instanceof RedirectResponse) {
            // Detectamos qué botón se pulsó
            $eaRequest = $request->request->all('ea');
            $btn = $eaRequest['newForm']['btn'] ?? $eaRequest['editForm']['btn'] ?? null;

            // CASO A: Guardar y Continuar (Loop) -> Perpetuamos el token
            if (in_array($btn, ['saveAndAddAnother', 'saveAndContinue'])) {
                $targetUrl = $response->getTargetUrl();
                // Verificamos si ya lo tiene para no duplicar
                if (!str_contains($targetUrl, 'returnTo=')) {
                    $sep = (parse_url($targetUrl, PHP_URL_QUERY) ? '&' : '?');
                    $response->setTargetUrl($targetUrl . $sep . 'returnTo=' . $encodedReturnTo);
                }
                return;
            }

            // CASO B: Guardar y Salir -> Usamos el token para volver
            $decodedUrl = base64_decode((string) $encodedReturnTo, true);
            // Validación de seguridad para evitar Open Redirects
            if ($decodedUrl && filter_var($decodedUrl, FILTER_VALIDATE_URL)) {
                // Verificamos que sea del mismo dominio
                $parts = parse_url($decodedUrl);
                if (($parts['host'] ?? '') === $this->panelHost) {
                    $response->setTargetUrl($decodedUrl);
                }
            }
        }
    }

    /**
     * Detecta si es un Listado (Index) soportando Pretty URLs y parámetros EA.
     */
    private function isIndexPage(Request $request): bool
    {
        // 1. Detección por parámetro explícito (EasyAdmin estándar)
        $crudAction = $request->query->get(EA::CRUD_ACTION);
        if ($crudAction === Action::INDEX) return true;

        // Si hay una acción explícita que NO es index, retornamos false.
        if (in_array($crudAction, [Action::DETAIL, Action::EDIT, Action::NEW, Action::BATCH_DELETE], true)) {
            return false;
        }

        $path = $request->getPathInfo();

        // 2. 🔥 CORRECCIÓN: Excluir explícitamente la Raíz y el Admin base
        // Si la URL es exactamente "/" o "/admin" o /panel por silas, es el Dashboard, NO un listado.
        if ($path === '/' || $path === '/admin' || $path === '/panel') {
            return false;
        }

        // 3. Detección por URL (Pretty URLs)
        // Filtros negativos: Si contiene estas palabras, NO es un index.
        if (str_ends_with($path, '/new') ||
            str_contains($path, '/edit') ||
            str_contains($path, '/batch') ||
            str_contains($path, '/login') ||
            str_contains($path, '/logout')) {
            return false;
        }

        // Filtro de ID al final: Si termina en número o UUID, es un DETALLE.
        if (preg_match('/\/(?:\d+|[a-f0-9-]{20,})$/i', $path)) {
            return false;
        }

        // Si ha pasado todos los filtros, asumimos que es un Index.
        return true;
    }
}