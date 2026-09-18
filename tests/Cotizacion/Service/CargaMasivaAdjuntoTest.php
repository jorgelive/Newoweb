<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Service;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFileGrupo;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Entity\CotizacionPasajeroGrupo;
use App\Cotizacion\Entity\CotizacionPasajeroIdentificacion;
use App\Cotizacion\Entity\CotizacionVuelo;
use App\Cotizacion\Enum\ArchivoTipoEnum;
use App\Cotizacion\Enum\GrupoTipoEnum;
use App\Cotizacion\Service\CargaMasivaDeArchivos;
use App\Enum\DocumentoTipoEnum;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Vich\UploaderBundle\FileAbstraction\ReplacingFile;
use ZipArchive;

/**
 * El adjunto que sale de la carga tiene que ser uno que Vich SÍ suba.
 *
 * ── Por qué existe este test ────────────────────────────────────────────────
 * 🔥 El 14/09/2026 se cargaron 32 boarding passes en producción: las 32 filas se crearon, salían
 * en el listado con su persona y su vuelo, y **no había ni un fichero en disco**. `image_name` e
 * `image_size` a NULL en las 32. Ningún error, ninguna línea en el log.
 *
 * La causa es una puerta del propio Vich, y es muda:
 *
 * ```php
 * // Vich\UploaderBundle\Handler\UploadHandler::hasUploadedFile()
 * return $file instanceof UploadedFile || $file instanceof ReplacingFile;
 * ```
 *
 * Con cualquier otro `File`, `upload()` hace `return;` y se acabó. Y como después del `flush()`
 * {@see CargaMasivaDeArchivos::limpiar()} borra el extracto, **el contenido se perdía**.
 *
 * ⚠️ Lo que despistó al escribirlo fue verificar la capa equivocada: `FileSystemStorage` sí hace
 * `copy()` para lo que no es un `UploadedFile`, pero la ejecución nunca llega ahí.
 *
 * Por eso el test no comprueba que el fichero acabe en disco —eso exigiría montar Vich entero—
 * sino **la condición exacta que Vich evalúa**. Es la línea que decide, y es la que se rompió.
 */
final class CargaMasivaAdjuntoTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/carga-adjunto-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir . '/var/documentos', 0o775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->projectDir));
    }

    public function testElAdjuntoLlevaUnFicheroQueVichAcepta(): void
    {
        $creado = $this->cargarUnBoardingPass();

        $subido = $creado->getImageFile();

        self::assertNotNull($subido, 'sin fichero no hay nada que subir');

        // ⚠️ Copia literal de `UploadHandler::hasUploadedFile()`. Si Vich cambia la puerta, este
        // test deja de describirla y hay que volver a leerla — pero mientras tanto es la única
        // comprobación que separa «se guardó» de «se guardó vacío».
        self::assertTrue(
            $subido instanceof UploadedFile || $subido instanceof ReplacingFile,
            sprintf('Vich ignora en silencio un %s: el adjunto quedaría sin fichero', $subido::class),
        );
    }

    /** Y que sea el fichero correcto, no uno cualquiera que pase la puerta. */
    public function testElFicheroEsElExtraidoDelZip(): void
    {
        $creado = $this->cargarUnBoardingPass();

        self::assertFileExists((string) $creado->getImageFile()?->getPathname());
        self::assertSame(
            '%PDF-1.4 fingido',
            file_get_contents((string) $creado->getImageFile()?->getPathname()),
            'el adjunto apunta al contenido que venía en el ZIP',
        );
        self::assertSame(ArchivoTipoEnum::TICKET_AEREO, $creado->getTipoArchivo());
    }

    // ── Andamiaje ───────────────────────────────────────────────────────────

    private function cargarUnBoardingPass(): \App\Cotizacion\Entity\CotizacionFilearchivo
    {
        $file = new CotizacionFile();

        $vuelo = (new CotizacionVuelo())
            ->setFile($file)
            ->setNumero('JA 7018')
            ->setOrigen('CUZ')
            ->setDestino('LIM')
            ->setSalida(new DateTimeImmutable('2026-09-17 07:15'));
        $vuelo->initializeId();
        $file->getVuelos()->add($vuelo);

        $grupo = (new CotizacionFileGrupo())
            ->setTipo(GrupoTipoEnum::RESERVA_AEREA)
            ->setSubeje('Nacional')
            ->setClave('RBEJRT');
        $grupo->initializeId();
        $grupo->getVuelos()->add($vuelo);

        $pax = (new CotizacionFilepasajero())->setNombre('Ana')->setApellido('Prueba');
        $pax->initializeId();
        $pax->addIdentificacion(
            (new CotizacionPasajeroIdentificacion())->setTipo(DocumentoTipoEnum::DNI)->setNumero('74003888'),
        );

        $pertenencia = (new CotizacionPasajeroGrupo())->setPasajero($pax)->setGrupo($grupo);
        $pax->addPertenencia($pertenencia);
        $grupo->addMiembro($pertenencia);
        $file->addFilepasajero($pax);

        $rutaZip = $this->projectDir . '/entrada.zip';
        $zip = new ZipArchive();
        $zip->open($rutaZip, ZipArchive::CREATE);
        $zip->addFromString('74003888-JA7018.pdf', '%PDF-1.4 fingido');
        $zip->close();

        $servicio = new CargaMasivaDeArchivos(
            $this->projectDir,
            $this->createStub(EntityManagerInterface::class),
        );

        $plan = $servicio->planificar($file, $rutaZip);
        self::assertNull($plan[0]['problema'], 'el andamiaje debe casar antes de probar el adjunto');

        $creados = $servicio->aplicar($file, $plan);
        self::assertCount(1, $creados);

        return $creados[0];
    }
}
