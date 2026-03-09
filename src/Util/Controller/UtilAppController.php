<?php

declare(strict_types=1);

namespace App\Util\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\KernelInterface;

class UtilAppController extends AbstractController
{
    /**
     * Controlador principal para la aplicación SPA de utilidades internas (Chat, etc.).
     * * Esta ruta atrapa /chat y cualquier subruta generada por Vue Router (prioridad -1 para no pisar rutas de API).
     * Su objetivo es servir el "App Shell" HTML. Delega la seguridad inicial al firewall de Symfony
     * y la carga de módulos a Vite dependiendo del entorno (HMR en dev, compilado en prod).
     *
     * @param Request $request La petición HTTP actual, usada para forzar modo build en dev.
     * @param KernelInterface $kernel Interfaz para acceder a parámetros del núcleo (entorno y rutas físicas).
     * * @return Response Documento HTML con los assets (CSS/JS) inyectados para inicializar la SPA.
     */
    #[Route('/chat/{route}', name: 'util_chat_entry', requirements: ['route' => '.*'], defaults: ['route' => ''], priority: -1)]
    public function index(Request $request, KernelInterface $kernel): Response
    {
        // Seguridad: Asegura que solo usuarios autenticados (staff/host) puedan cargar la herramienta interna.
        // Si el usuario no tiene sesión activa válida por el firewall, será redirigido al login.
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $env = $kernel->getEnvironment();

        // Permite probar el build compilado en local añadiendo ?mode=build a la URL
        $forceBuild = $request->query->get('mode') === 'build';

        // 1) MODO DESARROLLO (Vite HMR)
        // Carga la plantilla Twig instruyéndole que levante el cliente Vite local.
        if ($env === 'dev' && !$forceBuild) {
            return $this->render('util/app.html.twig', [
                'is_dev' => true,
                'vite_entry' => 'src/main.ts',
            ]);
        }

        // 2) MODO PRODUCCIÓN / BUILD
        $projectDir = $kernel->getProjectDir();

        // Vite 5/6 suele generar el manifest dentro de la carpeta .vite en el directorio de salida
        $manifestPath = $projectDir . '/public/app_util/.vite/manifest.json';

        // Fallback estructural para setups de Vite más antiguos
        if (!file_exists($manifestPath)) {
            $manifestPath = $projectDir . '/public/app_util/manifest.json';
        }

        // Validamos la existencia para evitar errores silenciosos en despliegues automatizados
        if (!file_exists($manifestPath)) {
            return new Response(
                '<body>
                    <h1>Error Crítico: Build no encontrado</h1>
                    <p>No se encuentra el archivo <code>manifest.json</code> en <code>public/app_util</code>.</p>
                    <p>Asegúrate de compilar los assets ejecutando: <code>npm run build</code> en el directorio de util.</p>
                </body>',
                500
            );
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        // Punto de entrada principal esperado por Vite
        $entryPoint = 'src/main.ts';

        // Prevención de errores si el entry point cambió de extensión (ej. a .js)
        if (!isset($manifest[$entryPoint])) {
            if (isset($manifest['src/main.js'])) {
                $entryPoint = 'src/main.js';
            } else {
                $keys = implode(', ', array_keys($manifest));
                throw $this->createNotFoundException("Entrada '$entryPoint' no encontrada en manifest.json. Claves disponibles: [$keys]");
            }
        }

        // Renderiza el App Shell enviando los chunks y CSS ya hasheados y cacheados
        return $this->render('util/app.html.twig', [
            'is_dev' => false,
            'js_file' => $manifest[$entryPoint]['file'],
            'css_files' => $manifest[$entryPoint]['css'] ?? [],
        ]);
    }
}