<?php

declare(strict_types=1);

namespace App\Pax\Controller;

use App\Service\Front\ManifiestoVite;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\KernelInterface;

class PaxAppController extends AbstractController
{
    /**
     * Primeros tramos de las rutas PRIVADAS del router de pax (`pax/src/router/index.ts`).
     *
     * 🔒 **pax no es un escaparate (13/10/2026).** Sus páginas se comparten por enlace y nada
     * pedía a los buscadores que no las indexaran: ni `robots.txt` (la ruta cae en este mismo
     * controlador) ni `noindex`. Un enlace pegado en un foro bastaba para que Google indexara la
     * guía de un huésped —con sus instrucciones de entrada—, un enlace de pago, un expediente o un
     * catálogo hecho para un cliente. Desde entonces todo lleva `X-Robots-Tag: noindex` salvo la
     * vitrina de las casitas (`/{establecimiento}/{unidad}`), que es pública a propósito: la sección
     * de alojamiento de openperu.pe enlaza ahí (`front.alojamiento_url`).
     *
     * ⚠️ Una ruta privada nueva en pax con otro prefijo de dos tramos se tomaría por vitrina: va
     * en esta lista.
     */
    // `acceso`: la ficha de un enlace del equipo (`/acceso/{token}`), con el código de la caja de
    // arriba. Tiene dos tramos: sin estar aquí se habría servido como vitrina, indexable.
    private const array PRIVADAS = ['file', 'catalogo', 'pago', 'huesped', 'acceso'];

    /**
     * App Pax (Symfony-first):
     * - HTML base lo sirve Symfony (Twig)
     * - En dev: carga Vite HMR
     * - En prod/build: lee manifest.json de Vite y carga assets compilados
     *
     * @param Request $request La petición entrante para capturar flags de desarrollo.
     * @param KernelInterface $kernel Interfaz para determinar el entorno y directorios.
     * @return Response El HTML base renderizado por Twig que monta la aplicación Vue.
     */
    #[Route('/{route}', name: 'pax_app_entry', requirements: ['route' => '.*'], defaults: ['route' => ''], priority: -100)]
    public function index(Request $request, KernelInterface $kernel): Response
    {
        $env = $kernel->getEnvironment();

        // Permite probar el build en local añadiendo ?mode=build
        $forceBuild = $request->query->get('mode') === 'build';

        // 1) DEV (Vite HMR)
        if ($env === 'dev' && !$forceBuild) {
            return $this->paraBuscadores($this->render('pax/app.html.twig', [
                'is_dev' => true,
                'vite_entry' => 'src/main.ts',
            ]), $request->getPathInfo());
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

        $manifest = ManifiestoVite::desdeJson((string) file_get_contents($manifestPath));

        $entrada = $manifest->entrada('src/main.ts') ?? $manifest->entrada('src/main.js');

        if ($entrada === null) {
            $keys = implode(', ', $manifest->claves());
            throw $this->createNotFoundException("Entrada 'src/main.ts' no encontrada en manifest.json. Claves disponibles: [$keys]");
        }

        return $this->paraBuscadores($this->render('pax/app.html.twig', [
            'is_dev' => false,
            'js_file' => $entrada['file'],
            'css_files' => $entrada['css'],
        ]), $request->getPathInfo());
    }

    /** `noindex` en todo lo que no sea la vitrina de una casita. Ver {@see self::PRIVADAS}. */
    private function paraBuscadores(Response $respuesta, string $ruta): Response
    {
        if (!self::esVitrina($ruta)) {
            $respuesta->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        // 🔒 La URL de un enlace del equipo ES la credencial (`/acceso/{token}`): que no salga en
        // el `Referer` hacia el vídeo de YouTube o la foto que la página embebe.
        if (str_starts_with($ruta, '/acceso/')) {
            $respuesta->headers->set('Referrer-Policy', 'no-referrer');
        }

        return $respuesta;
    }

    /** `/centro-cusco-inti/centro-cusco-inti-casita-1`: dos tramos y ninguno de los privados. */
    public static function esVitrina(string $ruta): bool
    {
        $tramos = array_values(array_filter(explode('/', $ruta), static fn (string $t): bool => $t !== ''));

        return count($tramos) === 2 && !in_array($tramos[0], self::PRIVADAS, true);
    }
}