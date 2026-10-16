<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\ApiPlatform\State;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Cotizacion\ApiPlatform\State\CotizacionFileBorradoProcessor;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionPago;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Un expediente con pagos no se borra: 409 con el motivo, no un 500 de clave foránea. */
#[CoversClass(CotizacionFileBorradoProcessor::class)]
final class CotizacionFileBorradoProcessorTest extends TestCase
{
    public function testConPagosNoSeBorraAunqueEstenAnulados(): void
    {
        $file = new CotizacionFile();
        $pago = (new CotizacionPago())->setFile($file)->setMonto('10')->setMoneda('USD');
        $pago->anular('Error', null, new \DateTimeImmutable());
        $file->getPagos()->add($pago);

        $borrador = $this->borrador();
        try {
            (new CotizacionFileBorradoProcessor($borrador))->process($file, new Delete());
            self::fail('Debía negarse');
        } catch (ConflictHttpException) {
            self::assertSame(0, $borrador->llamadas);
        }
    }

    public function testSinPagosSeBorra(): void
    {
        $borrador = $this->borrador();
        (new CotizacionFileBorradoProcessor($borrador))->process(new CotizacionFile(), new Delete());

        self::assertSame(1, $borrador->llamadas);
    }

    /** @return ProcessorInterface<CotizacionFile, null>&object{llamadas: int} */
    private function borrador(): ProcessorInterface
    {
        return new class implements ProcessorInterface {
            public int $llamadas = 0;

            public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
            {
                ++$this->llamadas;

                return null;
            }
        };
    }
}
