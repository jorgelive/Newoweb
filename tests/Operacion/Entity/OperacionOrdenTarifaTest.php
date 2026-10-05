<?php

declare(strict_types=1);

namespace App\Tests\Operacion\Entity;

use App\Operacion\Entity\OperacionOrdenServicioItem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CUÁL de las tarifas del componente se compró, y de quién es el precio.
 *
 * ⚠️ **El caso que lo estrenó: el ingreso a Vinicunca.** La orden decía «Ingreso a Vinicunca · 4
 * pax · PEN 80.00» y no decía en ninguna de sus tres superficies que la tarifa era la de nacional
 * —PEN 20 por persona, frente a PEN 30 la de extranjero—. En un `ticket_variable` la procedencia
 * **es** el precio, así que el proveedor recibía el encargo sin el dato que lo determina y el
 * único rastro de la respuesta era el importe.
 *
 * Y no salía por un motivo que no se ve leyendo el código: `descripcion` viene de una cascada en
 * la que el nombre interno de la tarifa va CUARTO, detrás del nombre interno del componente — que
 * está relleno en 281 de 281 filas de producción. Esa prioridad no puede ganar nunca.
 *
 * Lo que fija este test es la regla de silencio, que es donde está el riesgo: medido sobre las 85
 * filas reales, **subir el nombre de la tarifa a sustituir el título empeoraba 23 de 28 rótulos**
 * porque muchos nombres internos son el del prestador («Junela»), el default de fábrica («Nueva
 * Tarifa») o un genérico («Auto»). De ahí que esto sea una ranura que se AÑADE y se calla sola.
 */
final class OperacionOrdenTarifaTest extends TestCase
{
    /** @param array<string, string|null> $campos */
    private function item(array $campos = []): OperacionOrdenServicioItem
    {
        $item = new OperacionOrdenServicioItem();
        $item->setDescripcion($campos['descripcion'] ?? 'Ingreso a Vinicunca');
        $item->setNombreComponente($campos['nombreComponente'] ?? 'Ingreso a Vinicunca');
        $item->setNombreSegmento($campos['nombreSegmento'] ?? null);
        $item->setTipoComponente($campos['tipoComponente'] ?? 'ticket_variable');
        $item->setPrestadorNombre($campos['prestadorNombre'] ?? null);
        $item->setTarifaNombre($campos['tarifaNombre'] ?? null);
        $item->setTarifaProcedencia($campos['tarifaProcedencia'] ?? null);

        return $item;
    }

    #[Test]
    public function el_nombre_de_la_tarifa_sale_cuando_el_titulo_no_lo_dice(): void
    {
        // El caso real de la OS-20261004-445.
        $item = $this->item(['tarifaNombre' => 'Peruano', 'tarifaProcedencia' => 'nacional']);

        self::assertSame('Ingreso a Vinicunca', $item->getTituloParaProveedor());
        // `descripcion` repite el título, así que la variante se calla — y antes de esto ahí se
        // acababa la línea.
        self::assertNull($item->getVarianteParaProveedor());
        self::assertSame('Peruano', $item->getTarifaParaProveedor());
        self::assertSame('Nacional', $item->getProcedenciaParaProveedor());
    }

    #[Test]
    public function la_linea_al_proveedor_dice_la_tarifa_y_la_procedencia(): void
    {
        $item = $this->item(['tarifaNombre' => 'Peruano', 'tarifaProcedencia' => 'nacional']);
        $item->setCantidadPax(4);

        $linea = $item->lineaParaProveedor();

        self::assertStringContainsString('Peruano', $linea);
        self::assertStringContainsString('Nacional', $linea);
    }

    #[Test]
    public function se_calla_cuando_el_nombre_de_la_tarifa_es_el_del_prestador(): void
    {
        // «Pool Vinicunca» lo presta Junela y su tarifa se llama «Junela»: mandarle a Junela una
        // línea que dice «Junela» gasta la ranura en no decir nada. Es un caso REAL, no teórico.
        $item = $this->item([
            'descripcion' => 'Pool Vinicunca',
            'nombreComponente' => 'Pool Vinicunca',
            'tipoComponente' => 'pool',
            'prestadorNombre' => 'Junela',
            'tarifaNombre' => 'Junela',
        ]);

        self::assertNull($item->getTarifaParaProveedor());
    }

    #[Test]
    public function se_calla_cuando_el_prestador_solo_la_CONTIENE(): void
    {
        // ⚠️ El caso real de la OS-20261004-304, el mismo día del despliegue: la igualdad exacta
        // se quedó corta por un prefijo y a Machupicchu Perú Extreme le llegó una línea que decía
        // «Machupicchu Perú Extreme».
        $item = $this->item([
            'descripcion' => 'Pool Quelccaya',
            'nombreComponente' => 'Pool Quelcaya',
            'tipoComponente' => 'pool',
            'prestadorNombre' => 'Qelccaya Machupicchu Perú Extreme',
            'tarifaNombre' => 'Machupicchu Perú Extreme',
        ]);

        self::assertNull($item->getTarifaParaProveedor());
        // Y el nombre para el proveedor sí sale: es lo que distingue este caso de un borrado.
        self::assertSame('Pool Quelccaya', $item->getVarianteParaProveedor());
    }

    #[Test]
    public function compara_sin_tildes_ni_mayusculas(): void
    {
        $item = $this->item(['prestadorNombre' => 'TUNUPA CUSCO', 'tarifaNombre' => 'Tunupá']);

        self::assertNull($item->getTarifaParaProveedor());
    }

    #[Test]
    public function pero_al_reves_NO_se_calla_porque_lo_que_sobra_informa(): void
    {
        // «Buffet Tunupa Valle Niño» contiene al prestador y añade «Niño», que es la tarifa de
        // menor: callarlo escondería por qué el precio es otro. La contención sólo vale en un
        // sentido, y ésa es la mitad del arreglo.
        $item = $this->item([
            'descripcion' => 'Almuerzo en el Valle',
            'nombreComponente' => 'Almuerzo en el Valle',
            'tipoComponente' => 'alimentacion_fijo',
            'prestadorNombre' => 'Tunupa Valle',
            'tarifaNombre' => 'Buffet Tunupa Valle Niño',
        ]);

        self::assertSame('Buffet Tunupa Valle Niño', $item->getTarifaParaProveedor());
    }

    #[Test]
    public function una_variante_de_vehiculo_no_se_calla_por_contencion(): void
    {
        // «Auto» es la variante de vehículo, hermana de «Van» y «Bus»: es el dato por el que
        // cambia el precio del transporte. Ningún prestador real la contiene —medido—, pero si
        // alguno se llamara «Transportes Auto Sur» esto lo fijaría.
        $item = $this->item([
            'descripcion' => 'Transporte urbano en Punta Cana',
            'nombreComponente' => 'Transporte urbano en Punta Cana',
            'tipoComponente' => 'transporte',
            'prestadorNombre' => 'Dominican Shuttle',
            'tarifaNombre' => 'Auto',
        ]);

        self::assertSame('Auto', $item->getTarifaParaProveedor());
    }

    #[Test]
    public function se_calla_cuando_repite_el_titulo_o_la_variante(): void
    {
        self::assertNull($this->item(['tarifaNombre' => 'Ingreso a Vinicunca'])->getTarifaParaProveedor());

        // La tarifa tiene nombre para proveedor, así que `descripcion` ya ES ese nombre y sube a
        // la variante: la ranura de tarifa no debe repetirlo.
        $conVariante = $this->item([
            'descripcion' => 'Del Origen Al Presente de Lima',
            'tarifaNombre' => 'Del Origen Al Presente de Lima',
        ]);
        self::assertSame('Del Origen Al Presente de Lima', $conVariante->getVarianteParaProveedor());
        self::assertNull($conVariante->getTarifaParaProveedor());
    }

    #[Test]
    public function los_dos_nombres_salen_a_la_vez_cuando_son_distintos(): void
    {
        // Lo que pide el campo: «Del Origen Al Presente de Lima» es lo que él entiende y «Pool
        // City Lima CT002 (Base 1-4)» lo que tú buscas en el tarifario cuando te pregunta por qué
        // le pagas eso. Colapsarlos en uno era el fallo original.
        $item = $this->item([
            'descripcion' => 'Del Origen Al Presente de Lima',
            'tarifaNombre' => 'Pool City Lima CT002 (Base 1-4)',
        ]);

        self::assertSame('Del Origen Al Presente de Lima', $item->getVarianteParaProveedor());
        self::assertSame('Pool City Lima CT002 (Base 1-4)', $item->getTarifaParaProveedor());
    }

    #[Test]
    public function una_procedencia_nula_es_sin_restriccion_y_no_se_pinta(): void
    {
        // Nulo NO es «falta el dato»: 750 de 852 tarifas maestras no restringen nacionalidad, y
        // una tarifa de pool no tiene por qué. Misma regla que en el resto del proyecto: lista
        // vacía = sin acotar, y entonces la ranura se calla en vez de inventar un «sin
        // especificar» que el proveedor leería como un dato.
        self::assertNull($this->item()->getProcedenciaParaProveedor());
        // Y un valor que el enum no conoce tampoco se pinta a medias.
        self::assertNull($this->item(['tarifaProcedencia' => 'marciano'])->getProcedenciaParaProveedor());
    }

    #[Test]
    public function las_tres_procedencias_tienen_etiqueta(): void
    {
        self::assertSame('Nacional', $this->item(['tarifaProcedencia' => 'nacional'])->getProcedenciaParaProveedor());
        self::assertSame('Extranjero', $this->item(['tarifaProcedencia' => 'extranjero'])->getProcedenciaParaProveedor());
        self::assertSame('Comunidad Andina', $this->item(['tarifaProcedencia' => 'can'])->getProcedenciaParaProveedor());
    }
}
