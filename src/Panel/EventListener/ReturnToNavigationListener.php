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
 * Versión Final: Excluye explícitamente assets, cargas y LiipImagine.
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
        $path = $request->getPathInfo();
        $route = $request->attributes->get('_route');

        // =====================================================================
        // 0. IGNORAR ASSETS Y RUTAS DE SISTEMA (Anti-Bucle)
        // =====================================================================

        // A. Rutas de LiipImagine (Generación de miniaturas)
        // Usualmente son 'liip_imagine_filter'
        if ($route && (str_starts_with($route, 'liip_') || str_contains($route, 'imagine'))) {
            return;
        }

        // B. Exclusión por Carpetas Físicas (Basado en tu estructura 'public')
        // Si la URL empieza por cualquiera de estas, NO es una página del admin.
        if (str_starts_with($path, '/media/') ||   // Caché de imágenes
            str_starts_with($path, '/carga/') ||   // Tus subidas originales
            str_starts_with($path, '/assets/') ||  // JS/CSS estáticos
            str_starts_with($path, '/bundles/') || // EasyAdmin y otros bundles
            str_starts_with($path, '/app/') ||     // Otros recursos
            str_starts_with($path, '/_wdt') ||     // Web Debug Toolbar
            str_starts_with($path, '/_profiler')) { // Symfony Profiler
            return;
        }

        // C. Exclusión por Formato
        // Si piden una imagen (.jpg, .webp, etc), no interceptar.
        // request_format suele ser 'html' para páginas normales.
        if ($request->getRequestFormat() !== 'html') {
            return;
        }

        // =====================================================================
        // 1. FILTROS DE SEGURIDAD
        // =====================================================================
        if ($request->getHost() !== $this->panelHost) return;
        if ($request->isXmlHttpRequest()) return;

        if (in_array($route, ['app_login', 'app_logout'], true)) return;

        // 2. IMPORTANTE: Si es el Dashboard, NO hacer nada.
        $controller = $request->query->get(EA::CRUD_CONTROLLER_FQCN);
        if ($controller && str_contains($controller, 'DashboardController')) {
            return;
        }

        // 3. Si ya trae pasaporte, no lo tocamos.
        if ($request->query->has('returnTo')) return;

        // --- LÓGICA DE AUTO-GENERACIÓN ---

        if ($this->isIndexPage($request)) {
            $currentUrl = $request->getUri();
            $request->query->set('returnTo', base64_encode($currentUrl));
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) return;

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // VALIDACIÓN EXTRA EN RESPUESTA:
        // Si por alguna razón pasamos el filtro de Request pero es una imagen, abortar.
        if (str_starts_with($path, '/media/') ||
            str_starts_with($path, '/carga/') ||
            str_starts_with((string)$request->attributes->get('_route'), 'liip_')) {
            return;
        }

        $encodedReturnTo = $request->query->get('returnTo');

        if (empty($encodedReturnTo)) return;

        $response = $event->getResponse();

        if ($response instanceof RedirectResponse) {
            $eaRequest = $request->request->all('ea');
            $btn = $eaRequest['newForm']['btn'] ?? $eaRequest['editForm']['btn'] ?? null;

            // CASO A: Botones de "Guardar y..."
            if (in_array($btn, ['saveAndAddAnother', 'saveAndContinue'])) {
                $targetUrl = $response->getTargetUrl();
                if (!str_contains($targetUrl, 'returnTo=')) {
                    $sep = (parse_url($targetUrl, PHP_URL_QUERY) ? '&' : '?');
                    $response->setTargetUrl($targetUrl . $sep . 'returnTo=' . $encodedReturnTo);
                }
                return;
            }

            // CASO B: Guardar y Salir
            if ($btn === 'saveAndReturn') {
                $decodedUrl = base64_decode((string) $encodedReturnTo, true);
                if ($decodedUrl && filter_var($decodedUrl, FILTER_VALIDATE_URL)) {
                    $parts = parse_url($decodedUrl);
                    if (($parts['host'] ?? '') === $this->panelHost) {
                        $response->setTargetUrl($decodedUrl);
                    }
                }
            }
        }
    }

    private function isIndexPage(Request $request): bool
    {
        $path = $request->getPathInfo();

        if ($path === '/' || rtrim($path, '/') === '/admin' || rtrim($path, '/') === '/panel') {
            return false;
        }

        $crudAction = $request->query->get(EA::CRUD_ACTION);
        if ($crudAction) {
            return $crudAction === Action::INDEX;
        }

        if (str_contains($path, '/new') ||
            str_contains($path, '/edit') ||
            str_contains($path, '/batch') ||
            str_contains($path, '/render-filters') ||
            str_contains($path, '/autocomplete')) {
            return false;
        }

        if (preg_match('/\/(?:\d+|[a-f0-9-]{20,})$/i', $path)) {
            return false;
        }

        return true;
    }
}