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
     * Controlador principal para la SPA Pax.
     * Atrapa todas las rutas bajo /pax/ y delega el enrutamiento a Vue Router.
     */
    #[Route('/huesped/{route}', name: 'huesped_app_entry', requirements: ['route' => '.*'], defaults: ['route' => ''], priority: -1)]
    public function index(Request $request, KernelInterface $kernel): Response
    {
        $env = $kernel->getEnvironment();

        // 🛠️ TRUCO: Permite probar el build en local añadiendo ?mode=build a la URL
        // Ejemplo: http://tudominio.test/pax/?mode=build
        $forceBuild = $request->query->get('mode') === 'build';

        // 1. MODO DESARROLLO (Vite HMR)
        // Se activa si estamos en entorno 'dev' Y NO estamos forzando la prueba de build.
        if ($env === 'dev' && !$forceBuild) {
            return $this->render('pax/app.html.twig', [
                'is_dev' => true,
                // Apunta a tu archivo de entrada TypeScript
                'vite_entry' => 'src/main.ts'
            ]);
        }

        // 2. MODO PRODUCCIÓN (O Prueba de Build)
        $projectDir = $kernel->getProjectDir();

        // Buscamos el manifest en la nueva ubicación de Vite 5+ (.vite/manifest.json)
        $manifestPath = $projectDir . '/public/app_pax/.vite/manifest.json';

        // Fallback para versiones anteriores de Vite
        if (!file_exists($manifestPath)) {
            $manifestPath = $projectDir . '/public/app_pax/manifest.json';
        }

        // Si no existe, es que no se ha ejecutado npm run build
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

        $manifest = json_decode(file_get_contents($manifestPath), true);

        // Definimos la entrada. Como usas TypeScript, es src/main.ts
        $entryPoint = 'src/main.ts';

        // Verificamos que la entrada exista en el mapa
        if (!isset($manifest[$entryPoint])) {
            // Intentamos buscar .js por si acaso se cambió la configuración
            if (isset($manifest['src/main.js'])) {
                $entryPoint = 'src/main.js';
            } else {
                // Debug: mostramos qué claves sí existen
                $keys = implode(', ', array_keys($manifest));
                throw $this->createNotFoundException("Entrada '$entryPoint' no encontrada en manifest.json. Claves disponibles: [$keys]");
            }
        }

        // Renderizamos la plantilla con los archivos compilados
        return $this->render('pax/app.html.twig', [
            'is_dev' => false,
            'js_file' => $manifest[$entryPoint]['file'],
            'css_files' => $manifest[$entryPoint]['css'] ?? [],
            // 'imports' => $manifest[$entryPoint]['imports'] ?? [], // Opcional: para preloading
        ]);
    }
}