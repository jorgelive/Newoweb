<?php

declare(strict_types=1);

namespace App\Tests\Operacion\Entity;

use App\Operacion\Entity\OperacionOrdenServicio;
use App\Operacion\Entity\OperacionOrdenServicioItem;
use App\Operacion\Enum\VisibilidadPuntoEnum;
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
    private function item(OperacionOrdenServicio $orden, string $fecha, string $hora, ?string $recojo, ?string $entrega = null, string $prestador = 'Futurismo'): OperacionOrdenServicioItem
    {
        $item = new OperacionOrdenServicioItem();
        $item->setPrestadorNombre($prestador);
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
    public function una_cadena_del_mismo_prestador_dice_DONDE_EMPIEZA_y_DONDE_ACABA(): void
    {
        // ⚠️ El caso real: el mismo proveedor recoge en el hotel, lleva a la estación y de ahí a
        // Machu Picchu. Contarle «hotel → estación · estación → estación» es contarle su propia
        // logística a quien la va a conducir.
        $orden = new OperacionOrdenServicio();
        $uno = $this->item($orden, '2026-09-08', '06:00', 'Hotel Terra - Cusco', 'Estación de Ollantaytambo');
        $dos = $this->item($orden, '2026-09-08', '06:40', 'Estación de Ollantaytambo', 'Estación de Machu Picchu');
        $tres = $this->item($orden, '2026-09-08', '09:00', 'Estación de Machu Picchu', 'Machu Picchu Pueblo');

        $rutas = $orden->getRutasVisibles();

        self::assertSame('Recoge en Hotel Terra - Cusco', $rutas[$this->id($uno)] ?? null);
        self::assertArrayNotHasKey($this->id($dos), $rutas);
        self::assertSame('Deja en Machu Picchu Pueblo', $rutas[$this->id($tres)] ?? null);
    }

    #[Test]
    public function cambiar_de_prestador_ABRE_una_cadena_nueva(): void
    {
        // Lo de en medio es logística del proveedor **de esa cadena**. En cuanto opera otro, ese
        // otro necesita su propio principio y su propio final.
        $orden = new OperacionOrdenServicio();
        $a1 = $this->item($orden, '2026-09-08', '06:00', 'Hotel Terra - Cusco', 'Estación de Ollantaytambo', 'Futurismo');
        $a2 = $this->item($orden, '2026-09-08', '07:00', 'Estación de Ollantaytambo', 'Estación de Machu Picchu', 'Futurismo');
        $b1 = $this->item($orden, '2026-09-08', '12:00', 'Machu Picchu Pueblo', 'Santuario de Machu Picchu', 'Consettur');

        $rutas = $orden->getRutasVisibles();

        self::assertSame('Recoge en Hotel Terra - Cusco', $rutas[$this->id($a1)] ?? null);
        self::assertSame('Deja en Estación de Machu Picchu', $rutas[$this->id($a2)] ?? null);
        // Cadena de uno: se cuenta entera, principio y final en la misma línea.
        self::assertSame('Recoge en Machu Picchu Pueblo → deja en Santuario de Machu Picchu', $rutas[$this->id($b1)] ?? null);
    }

    #[Test]
    public function un_servicio_suelto_se_cuenta_ENTERO(): void
    {
        $orden = new OperacionOrdenServicio();
        $solo = $this->item($orden, '2026-09-08', '06:00', 'Hotel Terra - Cusco', 'Aeropuerto de Cusco');

        self::assertSame(
            'Recoge en Hotel Terra - Cusco → deja en Aeropuerto de Cusco',
            $orden->getRutasVisibles()[$this->id($solo)] ?? null
        );
    }

    #[Test]
    public function al_dia_siguiente_empieza_cadena_nueva_aunque_sea_el_mismo_prestador(): void
    {
        // Se consulta por jornada: quien opera el miércoles puede no haber leído el martes.
        $orden = new OperacionOrdenServicio();
        $martes = $this->item($orden, '2026-09-08', '06:00', 'Hotel Terra - Cusco', 'Aeropuerto de Cusco');
        $miercoles = $this->item($orden, '2026-09-09', '06:00', 'Hotel Terra - Cusco', 'Aeropuerto de Cusco');

        $rutas = $orden->getRutasVisibles();

        self::assertArrayHasKey($this->id($martes), $rutas);
        self::assertArrayHasKey($this->id($miercoles), $rutas);
    }

    #[Test]
    public function un_item_sin_puntos_no_ROMPE_la_cadena(): void
    {
        // Un ticket de ingreso en medio de tres traslados del mismo proveedor no convierte eso en
        // dos cadenas. Tampoco entra en ella: no aporta ni principio ni final.
        $orden = new OperacionOrdenServicio();
        $uno = $this->item($orden, '2026-09-08', '06:00', 'Hotel Terra - Cusco', 'Estación de Ollantaytambo');
        $ticket = $this->item($orden, '2026-09-08', '07:00', null, null);
        $tres = $this->item($orden, '2026-09-08', '09:00', 'Estación de Ollantaytambo', 'Machu Picchu Pueblo');

        $rutas = $orden->getRutasVisibles();

        self::assertSame('Recoge en Hotel Terra - Cusco', $rutas[$this->id($uno)] ?? null);
        self::assertArrayNotHasKey($this->id($ticket), $rutas);
        self::assertSame('Deja en Machu Picchu Pueblo', $rutas[$this->id($tres)] ?? null);
    }

    #[Test]
    public function un_item_MARCADO_a_mano_sale_aunque_este_en_medio(): void
    {
        // La salida para cuando «lo de en medio es logística suya» no vale: un tramo
        // subcontratado, o un punto intermedio que el proveedor sí necesita por escrito.
        $orden = new OperacionOrdenServicio();
        $uno = $this->item($orden, '2026-09-08', '06:00', 'Hotel Terra - Cusco', 'Estación de Ollantaytambo');
        $medio = $this->item($orden, '2026-09-08', '07:00', 'Estación de Ollantaytambo', 'Estación de Machu Picchu');
        $tres = $this->item($orden, '2026-09-08', '09:00', 'Estación de Machu Picchu', 'Machu Picchu Pueblo');

        $medio->setVisibilidadRecojo(VisibilidadPuntoEnum::SIEMPRE);
        $medio->setVisibilidadEntrega(VisibilidadPuntoEnum::SIEMPRE);
        $rutas = $orden->getRutasVisibles();

        // El principio y el final de la cadena siguen saliendo: SIEMPRE añade, no reemplaza.
        self::assertSame('Recoge en Hotel Terra - Cusco', $rutas[$this->id($uno)] ?? null);
        self::assertSame('Recoge en Estación de Ollantaytambo → deja en Estación de Machu Picchu', $rutas[$this->id($medio)] ?? null);
        self::assertSame('Deja en Machu Picchu Pueblo', $rutas[$this->id($tres)] ?? null);
    }

    #[Test]
    public function OCULTO_calla_un_extremo_de_la_cadena_y_lo_AVISA(): void
    {
        // ⚠️ Lo que el booleano anterior no dejaba hacer. Se permite porque la decisión es por
        // lado, sobre el listado de la orden y con rastro — y porque bloquearlo empujaba al atajo
        // destructivo de vaciar el texto del punto, que además pierde el dato.
        $orden = new OperacionOrdenServicio();
        $uno = $this->item($orden, '2026-09-08', '06:00', 'Hotel Terra - Cusco', 'Estación de Ollantaytambo');
        $dos = $this->item($orden, '2026-09-08', '09:00', 'Estación de Ollantaytambo', 'Machu Picchu Pueblo');

        $uno->setVisibilidadRecojo(VisibilidadPuntoEnum::OCULTO);
        $rutas = $orden->getRutasVisibles();

        self::assertArrayNotHasKey($this->id($uno), $rutas);
        self::assertSame('Deja en Machu Picchu Pueblo', $rutas[$this->id($dos)] ?? null);

        // Y el sistema lo dice: no bloquea, hace visible la consecuencia.
        $avisos = $orden->getAvisosDeRutas();
        self::assertCount(1, $avisos);
        self::assertStringContainsString('dónde se recoge', $avisos[0]);
    }

    #[Test]
    public function una_cadena_completa_no_genera_avisos(): void
    {
        $orden = new OperacionOrdenServicio();
        $this->item($orden, '2026-09-08', '06:00', 'Hotel Terra - Cusco', 'Estación de Ollantaytambo');
        $this->item($orden, '2026-09-08', '09:00', 'Estación de Ollantaytambo', 'Machu Picchu Pueblo');

        self::assertSame([], $orden->getAvisosDeRutas());
    }
}
