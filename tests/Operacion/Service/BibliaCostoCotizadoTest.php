<?php

declare(strict_types=1);

namespace App\Tests\Operacion\Service;

use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Cotizacion\Entity\CotizacionCottarifa;
use App\Operacion\Service\BibliaSnapshotService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lo que cuesta un componente en La Biblia, que es lo que se le acaba pidiendo al proveedor.
 *
 * Se prueba porque el fallo que corrige NO daba error: guardaba un número menor y bien
 * formado. Un hotel de 2 noches a 106 se cobraba como 106, y la Orden de Servicio salía
 * corta sin que nada lo delatara. Medido en producción antes del arreglo: 8 filas mal, todas
 * exactamente a la mitad — la peor, 1.375,50 cuando eran 2.751,00.
 *
 * Unitario puro: entidades en memoria, sin contenedor ni base de datos.
 */
final class BibliaCostoCotizadoTest extends TestCase
{
    private function servicio(): BibliaSnapshotService
    {
        // El EM no se toca en este cálculo: sólo se recorren las tarifas del componente.
        return new BibliaSnapshotService($this->createStub(EntityManagerInterface::class));
    }

    private function tarifa(string $monto, int $cantidad = 1, ?string $rol = 'estandar'): CotizacionCottarifa
    {
        return (new CotizacionCottarifa())
            ->setMontoCosto($monto)
            ->setCantidad($cantidad)
            ->setRolSnapshot($rol);
    }

    #[Test]
    public function multiplica_por_las_noches_del_componente(): void
    {
        // El caso real: hotel de 2 noches a 106. Antes devolvía «106.00».
        $componente = (new CotizacionCotcomponente())->setCantidad(2);
        $componente->addCottarifa($this->tarifa('106.00'));

        self::assertSame('212.00', $this->servicio()->calcularCostoCotizado($componente));
    }

    #[Test]
    public function multiplica_tambien_por_la_cantidad_de_la_tarifa(): void
    {
        $componente = (new CotizacionCotcomponente())->setCantidad(1);
        $componente->addCottarifa($this->tarifa('125.00', 8));

        self::assertSame('1000.00', $this->servicio()->calcularCostoCotizado($componente));
    }

    /** El otro medio fallo: sólo se miraba UNA tarifa y se comía el resto. */
    #[Test]
    public function suma_todas_las_tarifas_del_componente(): void
    {
        $componente = (new CotizacionCotcomponente())->setCantidad(1);
        $componente->addCottarifa($this->tarifa('150.00', 1));   // Individual
        $componente->addCottarifa($this->tarifa('125.00', 8));   // Doble

        self::assertSame('1150.00', $this->servicio()->calcularCostoCotizado($componente));
    }

    /** Venta opcional que nadie compró: ni se encarga ni se paga. */
    #[Test]
    public function la_alternativa_no_suma(): void
    {
        $componente = (new CotizacionCotcomponente())->setCantidad(1);
        $componente->addCottarifa($this->tarifa('100.00', 1));
        $componente->addCottarifa($this->tarifa('999.00', 1, 'alternativa'));

        self::assertSame('100.00', $this->servicio()->calcularCostoCotizado($componente));
    }

    /**
     * Sin tarifas es 0 y no null: la fila existe como referencia —el hotel que reservó el
     * pasajero— y un null obligaría a comprobarlo en cada suma.
     */
    #[Test]
    public function sin_tarifas_cuesta_cero(): void
    {
        self::assertSame('0.00', $this->servicio()->calcularCostoCotizado(new CotizacionCotcomponente()));
    }

    /** Una cantidad en cero no anula el importe: se trata como una unidad. */
    #[Test]
    public function una_cantidad_en_cero_no_borra_el_costo(): void
    {
        $componente = (new CotizacionCotcomponente())->setCantidad(0);
        $componente->addCottarifa($this->tarifa('80.00', 0));

        self::assertSame('80.00', $this->servicio()->calcularCostoCotizado($componente));
    }
}
