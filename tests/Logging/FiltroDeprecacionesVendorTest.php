<?php

namespace App\Tests\Logging;

use App\Logging\FiltroDeprecacionesVendor;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/**
 * El filtro decide qué deprecaciones llegan al fichero. Si se equivoca en un
 * sentido, el log vuelve a crecer sin control; si se equivoca en el otro,
 * perdemos justo los avisos que hay que atender antes de saltar a Symfony 7.
 */
final class FiltroDeprecacionesVendorTest extends TestCase
{
    private function registro(string $mensaje): LogRecord
    {
        return new LogRecord(
            new \DateTimeImmutable(),
            'deprecation',
            Level::Info,
            $mensaje,
        );
    }

    public function testDescartaLasDeprecacionesDeApiPlatform(): void
    {
        $interno = new TestHandler();
        $filtro = new FiltroDeprecacionesVendor($interno);

        $filtro->handle($this->registro(
            'Since api-platform/metadata 4.2: The "ApiPlatform\Metadata\ApiProperty::getBuiltinTypes()" method is deprecated.'
        ));

        self::assertSame([], $interno->getRecords());
    }

    public function testDescartaLasDeprecacionesDeVichUploader(): void
    {
        $interno = new TestHandler();
        $filtro = new FiltroDeprecacionesVendor($interno);

        $filtro->handle($this->registro(
            'Since vich/uploader-bundle 2.9: The "Vich\UploaderBundle\Mapping\Annotation\Uploadable" class is deprecated.'
        ));

        self::assertSame([], $interno->getRecords());
    }

    public function testDejaPasarLasDeprecacionesDeSymfony(): void
    {
        $interno = new TestHandler();
        $filtro = new FiltroDeprecacionesVendor($interno);

        $mensaje = 'Since symfony/framework-bundle 6.4: The "annotations.reader" service is deprecated without replacement.';
        $filtro->handle($this->registro($mensaje));

        self::assertTrue($interno->hasInfoThatContains($mensaje));
    }

    /**
     * Un mensaje que mencione api-platform sin ser una deprecación suya —por
     * ejemplo un error nuestro citando la librería— no debe desaparecer: el
     * filtro se ancla al prefijo «Since <paquete>», no a la mera aparición del
     * nombre.
     */
    public function testNoDescartaMensajesQueSoloMencionanElPaquete(): void
    {
        $interno = new TestHandler();
        $filtro = new FiltroDeprecacionesVendor($interno);

        $mensaje = 'Fallo al serializar el recurso api-platform/metadata en PmsReserva.';
        $filtro->handle($this->registro($mensaje));

        self::assertTrue($interno->hasInfoThatContains($mensaje));
    }
}
