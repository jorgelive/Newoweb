<?php

declare(strict_types=1);

/**
 * Comprueba SIN navegador las dos cosas que tumban el CRUD de Medios de cobro y que no
 * detectan ni `php -l` ni `lint:container`:
 *
 * 1. Los `ChoiceType` de enum. Si `opciones()` devuelve strings en vez de casos, el
 *    formulario de EDICIÓN muere con «could not be converted to string» — y el listado y el
 *    alta siguen funcionando, que es lo que despista.
 * 2. Que cada propiedad listada en `configureFields()` se pueda leer y escribir en la
 *    entidad. Un campo mal escrito (o sin getter, como pasó con `notaEsVisual`) sólo se ve
 *    al abrir la pantalla.
 *
 * No hace falta el kernel: los dos fallos están en el componente Form, no en la aplicación.
 *
 * Uso: php var/probar-medios-cobro.php
 */

use App\Finanzas\Entity\FinMedioCobro;
use App\Finanzas\Enum\FinAudienciaCobro;
use App\Finanzas\Enum\FinMedioCobroTipo;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Forms;
use Symfony\Component\PropertyAccess\PropertyAccess;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$factory = Forms::createFormFactory();
$fallos = 0;

/** Construye el desplegable con el valor que ya tiene la entidad y lo renderiza. */
$probar = static function (string $etiqueta, array $choices, object $valorActual) use ($factory, &$fallos): void {
    try {
        $factory->create(ChoiceType::class, $valorActual, ['choices' => $choices])->createView();
        printf("  ok  %s\n", $etiqueta);
    } catch (\Throwable $e) {
        $fallos++;
        printf("FALLA %s — %s\n", $etiqueta, $e->getMessage());
    }
};

// Lo que hace HOY el enum: etiqueta => caso. Es lo que espera una entidad con `enumType`.
$probar('tipo, opciones() actual (casos)', FinMedioCobroTipo::opciones(), FinMedioCobroTipo::YAPE);
$probar('audiencia, opciones() actual (casos)', FinAudienciaCobro::opciones(), FinAudienciaCobro::PERU);

// Y la versión que reventaba, para que quede claro que la prueba detecta el fallo.
$strings = [];
foreach (FinMedioCobroTipo::cases() as $caso) {
    $strings[$caso->label()] = $caso->value;
}

try {
    $factory->create(ChoiceType::class, FinMedioCobroTipo::YAPE, ['choices' => $strings])->createView();
    $fallos++;
    echo "FALLA la regresión no se detecta: con strings debería romper y no rompió\n";
} catch (\Throwable $e) {
    printf("  ok  con strings rompe, como debe — %s\n", $e->getMessage());
}

// ── Las propiedades que declara FinMedioCobroCrudController::configureFields() ──────────
// Se listan a mano a propósito: importarlas del controlador exigiría el contenedor entero,
// y lo que se quiere comprobar es justo que el NOMBRE case con la entidad.
$editables = ['tipo', 'banco', 'moneda', 'numero', 'titular', 'titularAlterno', 'cci',
              'audiencia', 'nota', 'activo', 'orden', 'ejecutarTraduccion', 'sobreescribirTraduccion'];
$soloLectura = ['notaEsVisual'];

$accessor = PropertyAccess::createPropertyAccessor();
$medio = new FinMedioCobro();

foreach ($editables as $prop) {
    $bien = $accessor->isReadable($medio, $prop) && $accessor->isWritable($medio, $prop);
    if (!$bien) {
        $fallos++;
    }
    printf("%s  campo «%s» %s\n", $bien ? '  ok ' : 'FALLA', $prop, $bien ? 'se lee y se escribe' : 'NO es accesible en la entidad');
}

foreach ($soloLectura as $prop) {
    $bien = $accessor->isReadable($medio, $prop);
    if (!$bien) {
        $fallos++;
    }
    printf("%s  campo «%s» %s\n", $bien ? '  ok ' : 'FALLA', $prop, $bien ? 'se lee (sólo listado)' : 'NO tiene getter');
}

echo $fallos === 0 ? "\nTodo correcto.\n" : "\n{$fallos} fallo(s).\n";
exit($fallos === 0 ? 0 : 1);
