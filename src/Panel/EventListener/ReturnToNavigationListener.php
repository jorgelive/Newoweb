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
 * MODO SEGURO: Ya no contamina el Request, solo intercepta el Response.
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
        // Ya no generamos nada aquí para no romper el Paginador de EasyAdmin.
        // Solo hacemos early returns por si es una ruta que no nos interesa.
        if (!$event->isMainRequest()) return;

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        $route = $request->attributes->get('_route');

        if ($route && (str_starts_with($route, 'liip_') || str_contains($route, 'imagine'))) return;

        if (str_starts_with($path, '/media/') ||
            str_starts_with($path, '/carga/') ||
            str_starts_with($path, '/assets/') ||
            str_starts_with($path, '/bundles/') ||
            str_starts_with($path, '/app/') ||
            str_starts_with($path, '/_wdt') ||
            str_starts_with($path, '/_profiler')) {
            return;
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

        // Leemos el pasaporte. Si existe, procedemos.
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

            // CASO B: Guardar y Salir → redirigir al returnTo
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
}