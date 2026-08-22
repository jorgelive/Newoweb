<?php

declare(strict_types=1);

namespace App\Tests\Operacion\Entity;

use App\Operacion\Entity\OperacionOrdenServicio;
use App\Operacion\Entity\OperacionOrdenServicioItem;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Cuándo se le repite al proveedor dónde recoge, y cuándo se calla.
 *
 * ⚠️ Repetirle en las seis líneas del martes que recoge en el Hotel Terra no le informa: le enseña
 * a no leer ese renglón, y entonces no lo lee el día que sí cambia. Pero callarlo de más es peor
 * todavía, así que los tres bordes —cambio de día, cambio de sitio y vuelta al mismo— tienen que
 * estar fijados.
 */
final class OperacionOrdenRutasTest extends TestCase
{
    private function item(OperacionOrdenServicio $orden, string $fecha, string $hora, ?string $recojo, ?string $entrega = null): OperacionOrdenServicioItem
    {
        $item = new OperacionOrdenServicioItem();
        $item->setFechaServicio(new DateTimeImmutable($fecha));
        $item->setHora($hora);
        $item->setDescripcion('Servicio de prueba');
        $item->setPuntoRecojoConfirmado($recojo);
        $item->setPuntoEntregaConfirmado($entrega);
        $orden->addItem($item);

        return $item;
    }

    private function id(OperacionOrdenServicioItem $item): string
    {
        return (string) $item->getId()?->toRfc4122();
    }

    #[Test]
    public function el_mismo_sitio_el_mismo_dia_se_dice_UNA_vez(): void
    {
        $orden = new OperacionOrdenServicio();
        $primero = $this->item($orden, '2026-09-08', '06:00', 'Hotel Terra - Cusco');
        $segundo = $this->item($orden, '2026-09-08', '09:00', 'Hotel Terra - Cusco');
        $tercero = $this->item($orden, '2026-09-08', '14:00', 'Hotel Terra - Cusco');

        $rutas = $orden->rutasVisibles();

        self::assertArrayHasKey($this->id($primero), $rutas);
        self::assertArrayNotHasKey($this->id($segundo), $rutas);
        self::assertArrayNotHasKey($this->id($tercero), $rutas);
    }

    #[Test]
    public function al_dia_siguiente_vuelve_a_salir_aunque_sea_el_mismo_sitio(): void
    {
        // Se consulta por jornada: quien opera el miércoles puede no haber leído el martes.
        $orden = new OperacionOrdenServicio();
        $martes = $this->item($orden, '2026-09-08', '06:00', 'Hotel Terra - Cusco');
        $miercoles = $this->item($orden, '2026-09-09', '06:00', 'Hotel Terra - Cusco');

        $rutas = $orden->rutasVisibles();

        self::assertArrayHasKey($this->id($martes), $rutas);
        self::assertArrayHasKey($this->id($miercoles), $rutas);
    }

    #[Test]
    public function si_el_sitio_CAMBIA_en_mitad_del_dia_se_dice(): void
    {
        // Es justo cuando hace falta: callarlo aquí manda al conductor al sitio de la mañana.
        $orden = new OperacionOrdenServicio();
        $manana = $this->item($orden, '2026-09-08', '06:00', 'Hotel Terra - Cusco');
        $tarde = $this->item($orden, '2026-09-08', '15:00', 'Estación de Ollantaytambo');

        $rutas = $orden->rutasVisibles();

        self::assertArrayHasKey($this->id($manana), $rutas);
        self::assertArrayHasKey($this->id($tarde), $rutas);
    }

    #[Test]
    public function volver_al_sitio_anterior_el_mismo_dia_TAMBIEN_se_dice(): void
    {
        // A → B → A. La tercera línea vuelve al hotel, y no decirlo dejaría al conductor con la
        // estación como último dato leído. Sólo se calla lo que repite a la línea ANTERIOR.
        $orden = new OperacionOrdenServicio();
        $uno = $this->item($orden, '2026-09-08', '06:00', 'Hotel Terra - Cusco');
        $dos = $this->item($orden, '2026-09-08', '10:00', 'Estación de Ollantaytambo');
        $tres = $this->item($orden, '2026-09-08', '18:00', 'Hotel Terra - Cusco');

        $rutas = $orden->rutasVisibles();

        self::assertCount(3, $rutas);
        self::assertArrayHasKey($this->id($tres), $rutas);
        self::assertSame('Recoge en Hotel Terra - Cusco', $rutas[$this->id($uno)]);
        self::assertSame('Recoge en Estación de Ollantaytambo', $rutas[$this->id($dos)]);
    }

    #[Test]
    public function un_item_sin_punto_no_ocupa_sitio_ni_rompe_la_cadena(): void
    {
        $orden = new OperacionOrdenServicio();
        $conPunto = $this->item($orden, '2026-09-08', '06:00', 'Hotel Terra - Cusco');
        $sinPunto = $this->item($orden, '2026-09-08', '10:00', null);
        $repetido = $this->item($orden, '2026-09-08', '14:00', 'Hotel Terra - Cusco');

        $rutas = $orden->rutasVisibles();

        self::assertArrayHasKey($this->id($conPunto), $rutas);
        self::assertArrayNotHasKey($this->id($sinPunto), $rutas);
        self::assertArrayNotHasKey($this->id($repetido), $rutas);
    }
}
