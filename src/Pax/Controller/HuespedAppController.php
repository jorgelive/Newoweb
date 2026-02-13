<?php

namespace App\Pax\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\KernelInterface;

class HuespedAppController extends AbstractController
{
    /**
     * App Pax (Symfony-first):
     * - HTML base lo sirve Symfony (Twig)
     * - En dev: carga Vite HMR
     * - En prod/build: lee manifest.json de Vite y carga assets compilados
     *
     * Esta ruta atrapa /huesped y subrutas, y Vue Router maneja el enrutamiento interno.
     */
    #[Route('/huesped/{route}', name: 'huesped_app_entry', requirements: ['route' => '.*'], defaults: ['route' => ''], priority: -1)]
    public function index(Request $request, KernelInterface $kernel): Response
    {
        $env = $kernel->getEnvironment();

        // Permite probar el build en local añadiendo ?mode=build
        $forceBuild = $request->query->get('mode') === 'build';

        // 1) DEV (Vite HMR)
        if ($env === 'dev' && !$forceBuild) {
            return $this->render('pax/app.html.twig', [
                'is_dev' => true,
                'vite_entry' => 'src/main.ts',
            ]);
        }

        // 2) PROD / BUILD
        $projectDir = $kernel->getProjectDir();

        // Vite 5/6 suele generar manifest en /public/app_pax/.vite/manifest.json
        $manifestPath = $projectDir . '/public/app_pax/.vite/manifest.json';

        // Fallback para setups antiguos
        if (!file_exists($manifestPath)) {
            $manifestPath = $projectDir . '/public/app_pax/manifest.json';
        }

        if (!file_exists($manifestPath)) {
            return new Response(
                '<body>
                    <h1>Error: Build no encontrado</h1>
                    <p>No se encuentra el archivo <code>manifest.json</code> en <code>public/app_pax</code>.</p>
                    <p>Ejecuta: <code>cd pax && npm run build</code></p>
                </body>',
                500
            );
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        $entryPoint = 'src/main.ts';

        if (!isset($manifest[$entryPoint])) {
            if (isset($manifest['src/main.js'])) {
                $entryPoint = 'src/main.js';
            } else {
                $keys = implode(', ', array_keys($manifest));
                throw $this->createNotFoundException("Entrada '$entryPoint' no encontrada en manifest.json. Claves disponibles: [$keys]");
            }
        }

        return $this->render('pax/app.html.twig', [
            'is_dev' => false,
            'js_file' => $manifest[$entryPoint]['file'],
            'css_files' => $manifest[$entryPoint]['css'] ?? [],
        ]);
    }
}