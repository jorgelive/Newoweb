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
     * Punto de entrada único para la SPA (Single Page Application) en Vue.
     * * Explicación del Regex en 'requirements':
     * ^(?!api|platform|ajax_login|_profiler|_wdt).* * Esto significa: "Atrapa cualquier URL, EXCEPTO si empieza con /api, /platform,
     * /ajax_login, o las herramientas de desarrollo de Symfony".
     * La prioridad -1 asegura que si creas una ruta específica en Symfony, esa tenga prioridad.
     */
    #[Route('/{route}', name: 'app_entry', requirements: ['route' => '^(?!api|platform|ajax_login|_profiler|_wdt).*'], defaults: ['route' => ''], priority: -1)]
    public function index(Request $request, KernelInterface $kernel): Response
    {
        // 1. Detección del entorno
        $env = $kernel->getEnvironment();
        $forceBuild = $request->query->get('mode') === 'build';

        // 2. MODO DESARROLLO (Vite HMR Server)
        // Si estamos en dev, le decimos a Twig que cargue el script directamente desde el servidor local de Vite
        if ($env === 'dev' && !$forceBuild) {
            return $this->render('util/app.html.twig', [
                'is_dev' => true,
                'vite_entry' => 'src/main.ts',
            ]);
        }

        // 3. MODO PRODUCCIÓN (Lectura del manifest.json)
        $projectDir = $kernel->getProjectDir();

        // Vite 5+ guarda el manifest dentro de la carpeta .vite/ por defecto
        $manifestPath = $projectDir . '/public/app_util/.vite/manifest.json';

        // Fallback para versiones anteriores de Vite
        if (!file_exists($manifestPath)) {
            $manifestPath = $projectDir . '/public/app_util/manifest.json';
        }

        // Si no hay manifest, significa que olvidaste correr `npm run build`
        if (!file_exists($manifestPath)) {
            return new Response(
                '<body>
                    <h1>Error Crítico: Build no encontrado</h1>
                    <p>No se encuentra el archivo <code>manifest.json</code> en <code>public/app_util</code>.</p>
                    <p>Asegúrate de compilar los assets ejecutando: <code>npm run build</code></p>
                </body>',
                500
            );
        }

        // 4. Decodificar el manifest y buscar los assets cacheados
        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        // Definimos el punto de entrada principal
        $entryPoint = 'src/main.ts';

        // Prevención de errores si el entry point cambió de extensión (.js en lugar de .ts)
        if (!isset($manifest[$entryPoint])) {
            $entryPoint = isset($manifest['src/main.js']) ? 'src/main.js' : array_key_first($manifest);
        }

        // 5. Renderizar la plantilla inyectando el JS y CSS ya compilados
        return $this->render('util/app.html.twig', [
            'is_dev' => false,
            'js_file' => $manifest[$entryPoint]['file'],
            'css_files' => $manifest[$entryPoint]['css'] ?? [],
        ]);
    }
}