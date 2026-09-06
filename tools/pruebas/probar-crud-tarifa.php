<?php
declare(strict_types=1);
/**
 * ¿Cada campo del CRUD de tarifas apunta a algo que EXISTE en la entidad?
 *
 * Es el fallo de `nombreParaOrganizacion`: un barrido de renombrado dejó el CRUD llamando a
 * una propiedad que no existe. No lo caza PHPStan —son cadenas— ni ningún test, y sólo
 * revienta al abrir el formulario. Sólo LEE.
 */
require dirname(__DIR__, 1) . '/vendor/autoload.php';

$crud = file_get_contents(dirname(__DIR__, 1) . '/src/Travel/Controller/Crud/TravelTarifaCrudController.php');
preg_match_all('/(?:TextField|AssociationField|NumberField|IntegerField|BooleanField|ChoiceField|CollectionField)::new\(\s*\'([a-zA-Z0-9_]+)\'/', $crud, $m);
$campos = array_values(array_unique($m[1]));

$r = new ReflectionClass(App\Travel\Entity\TravelTarifa::class);
$props = [];
foreach ($r->getProperties() as $p) { $props[$p->getName()] = true; }
foreach ($r->getMethods() as $me) {
    if (preg_match('/^(get|is|has)([A-Z].*)$/', $me->getName(), $g)) {
        $props[lcfirst($g[2])] = true;
    }
}

$malos = [];
foreach ($campos as $c) {
    if (!isset($props[$c])) { $malos[] = $c; }
}

printf("%d campo(s) en el CRUD, %d propiedad(es)/getter(s) en la entidad\n\n", count($campos), count($props));

if ($malos === []) {
    echo "✅ Todos apuntan a algo que existe.\n";
} else {
    echo "⛔ Sin respaldo en la entidad:\n";
    foreach ($malos as $c) { echo "   - $c\n"; }
    exit(1);
}

foreach (['prestador', 'prestadorServicio', 'comprador', 'nombreParaPrestador'] as $c) {
    printf("   %-22s %s\n", $c, in_array($c, $campos, true) ? 'en el formulario ✅' : 'AUSENTE ⛔');
}
