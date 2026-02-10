<?php

namespace App\Pax\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\KernelInterface;

class PaxAppController extends AbstractController
{
    #[Route('/{route}', name: 'pax_app_entry', requirements: ['route' => '.*'], defaults: ['route' => ''], priority: 10)]
    public function index(string $route, KernelInterface $kernel): Response
    {
        $env = $kernel->getEnvironment();

        // --- MODO DEV ---
        if ($env === 'dev') {
            return $this->render('pax/app.html.twig', [
                'is_dev' => true,
                'vite_entry' => 'src/main.js'
            ]);
        }

        // --- MODO PROD ---
        $projectDir = $kernel->getProjectDir();

        // 👇 CAMBIO AQUÍ: app_pax
        $manifestPath = $projectDir . '/public/app_pax/.vite/manifest.json';

        if (!file_exists($manifestPath)) {
            $manifestPath = $projectDir . '/public/app_pax/manifest.json';
        }

        if (!file_exists($manifestPath)) {
            return new Response('<body><h1>Error</h1><p>No se encuentra el manifest en <code>public/app_pax</code>.</p></body>', 500);
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $entryPoint = 'src/main.js';

        if (!isset($manifest[$entryPoint])) {
            throw $this->createNotFoundException("Entrada '$entryPoint' no encontrada.");
        }

        return $this->render('pax/app.html.twig', [
            'is_dev' => false,
            'js_file' => $manifest[$entryPoint]['file'],
            'css_files' => $manifest[$entryPoint]['css'] ?? [],
        ]);
    }
}