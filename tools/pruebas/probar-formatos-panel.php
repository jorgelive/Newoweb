<?php
/**
 * Llama a cada `formatValue()` de los CRUD como lo hace EasyAdmin —en índice y detalle, con
 * `($valorDelCampo, $entidad)`— sobre entidades REALES de la base local, y vuelca lo que devuelven
 * a un JSON. Sirve para comparar el código viejo con el nuevo sin entrar al panel (que pide sesión).
 * Sólo lee.
 *
 * Se escribió para subir PHPStan al nivel 10 (26/09/2026): 33 controladores, 7 100 llamadas,
 * idénticas antes y después. Ver `docs/PanelEasyAdmin.md`.
 *
 *   git worktree add /tmp/viejo <commit> && ln -s $PWD/vendor /tmp/viejo/vendor && ln -s $PWD/.env.local /tmp/viejo/.env.local
 *   php tools/pruebas/probar-formatos-panel.php /tmp/viejo /tmp/viejo.json App\Pms\Controller\Crud\PmsUnidadCrudController …
 *   php tools/pruebas/probar-formatos-panel.php $PWD /tmp/nuevo.json App\Pms\Controller\Crud\PmsUnidadCrudController …
 *
 * ⚠️ El autoloader antepone el `src/` de la raíz que se le pasa: con `vendor` enlazado, el classmap
 * cargaría el del repo principal y se compararía el código nuevo consigo mismo.
 * ⚠️ Una miniatura puede salir como `media/cache/resolve/…` en una copia y `media/cache/…` en otra:
 * depende de si la imagen ya está generada en el `public/` de cada copia, no del código.
 */
$raiz = $argv[1]; $salida = $argv[2]; $clases = array_slice($argv, 3);
chdir($raiz);
require $raiz . '/vendor/autoload.php';
spl_autoload_register(static function (string $c) use ($raiz): void {
    if (str_starts_with($c, 'App\\')) { $f = $raiz . '/src/' . str_replace('\\', '/', substr($c, 4)) . '.php'; if (is_file($f)) require $f; }
}, true, true);
(new Symfony\Component\Dotenv\Dotenv())->bootEnv($raiz . '/.env');
$kernel = new App\Kernel('dev', true);
$kernel->boot();
$c = $kernel->getContainer();
$em = $c->get('doctrine')->getManager();
$acc = Symfony\Component\PropertyAccess\PropertyAccess::createPropertyAccessorBuilder()->enableExceptionOnInvalidIndex()->getPropertyAccessor();
$res = [];
foreach ($clases as $clase) {
    $fq = $clase;
    try { $ctrl = $c->get($fq); } catch (Throwable $e) { $ctrl = (new ReflectionClass($fq))->newInstanceWithoutConstructor(); }
    $entidad = $fq::getEntityFqcn();
    $filas = $em->getRepository($entidad)->findBy([], null, 40);
    foreach (['index', 'detail'] as $pagina) {
        try { $campos = iterator_to_array($ctrl->configureFields($pagina), false); }
        catch (Throwable $e) { $res["$clase|$pagina|configureFields"] = 'EXC ' . get_class($e) . ': ' . $e->getMessage(); continue; }
        foreach ($campos as $i => $campo) {
            $dto = $campo->getAsDto();
            $cb = $dto->getFormatValueCallable();
            if ($cb === null) continue;
            $prop = $dto->getProperty();
            foreach ($filas as $k => $fila) {
                try { $valor = $acc->isReadable($fila, $prop) ? $acc->getValue($fila, $prop) : null; } catch (Throwable) { $valor = null; }
                try { $out = $cb($valor, $fila); $out = is_scalar($out) || $out === null ? $out : (is_object($out) && method_exists($out, '__toString') ? (string) $out : get_debug_type($out)); }
                catch (Throwable $e) { $out = 'EXC ' . get_class($e) . ': ' . $e->getMessage(); }
                $res["$clase|$pagina|$i:$prop|$k"] = $out;
            }
        }
    }
    $em->clear();
}
file_put_contents($salida, json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo count($res) . " llamadas\n";
