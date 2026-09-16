<?php

declare(strict_types=1);

/**
 * ¿Sobrevive el expediente a exportar su padrón y volver a subirlo?
 *
 * Es el ciclo completo —`PadronPlantillaGenerador::exportar()` → `PadronImportador::importar()`—
 * sobre el expediente REAL, con sus 66 habitaciones, sus PNR y sus hoteles. Ningún test unitario
 * cubre esto: el fallo que lo motivó no estaba en ninguna de las dos mitades, estaba en que **no
 * encajaban**.
 *
 * ⚠️ **Lo que se mide es `pertenenciasQuitadas`, y tiene que ser CERO.** Reimportar el archivo que
 * acaba de salir no puede cambiar nada: el archivo dice exactamente lo que el sistema ya sabe. Si
 * sale distinto de cero, hay una cabecera que la exportación escribe y la importación no entiende —
 * y como `aplicarGrupos()` sincroniza, eso son personas sacadas de sus grupos sin un error.
 *
 * ⚠️ Y se leen los AVISOS, no sólo el número. Un «no corresponde a ningún eje conocido» ya no borra
 * nada (ver `PadronImportador::ejesDeclarados()`), pero sigue significando que esa columna no entra:
 * el ciclo es seguro y aun así incompleto.
 *
 * ⚠️ `importar(..., seco: true)` ya corre en una transacción con `rollback`: **no deja ni una fila**.
 * Por eso esta sonda se puede pasar en producción, que es donde están los datos que importan.
 *
 *   php tools/pruebas/probar-ciclo-padron.php 5SRAJV
 */

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Service\Padron\PadronImportador;
use App\Cotizacion\Service\Padron\PadronPlantillaGenerador;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');

$localizador = $argv[1] ?? '5SRAJV';
$entorno = $argv[2] ?? 'dev';

$kernel = new App\Kernel($entorno, $entorno === 'dev');
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();

$file = $em->getRepository(CotizacionFile::class)->findOneBy(['localizador' => $localizador]);

if ($file === null) {
    echo "No existe el expediente {$localizador}.\n";
    exit(1);
}

$grupos = 0;
$pertenencias = 0;

foreach ($file->getGrupos() as $grupo) {
    ++$grupos;
    $pertenencias += count($grupo->getMiembros());
}

printf("Expediente %s: %d subgrupos, %d pertenencias.\n", $localizador, $grupos, $pertenencias);

$ruta = sys_get_temp_dir().'/ciclo-padron-'.$localizador.'.xlsx';
file_put_contents($ruta, (new PadronPlantillaGenerador($em))->exportar($file));

printf("Exportado a %s (%d KB).\n\n", $ruta, (int) (filesize($ruta) / 1024));

$resultado = (new PadronImportador($em))->importar($file, $ruta, seco: true);

printf("pertenenciasQuitadas ... %d\n", $resultado->pertenenciasQuitadas);
printf("pertenenciasCreadas .... %d\n", $resultado->pertenenciasCreadas);
printf("pasajerosCreados ....... %d\n", $resultado->pasajerosCreados);

$avisos = $resultado->avisos;

if ($avisos !== []) {
    echo "\nAvisos:\n";

    foreach ($avisos as $aviso) {
        echo '  · ', $aviso, "\n";
    }
}

unlink($ruta);

if ($resultado->pertenenciasQuitadas !== 0) {
    printf("\n⛔ EL CICLO PIERDE DATOS: %d pertenencias.\n", $resultado->pertenenciasQuitadas);
    exit(1);
}

echo "\n✅ El ciclo no pierde ni una pertenencia.\n";
