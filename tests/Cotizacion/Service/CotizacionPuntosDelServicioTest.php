<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Service;

use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Cotizacion\Entity\CotizacionCotservicio;
use App\Cotizacion\Entity\CotizacionSegmento;
use App\Cotizacion\Service\CotizacionPuntosDelServicio;
use App\Travel\Entity\TravelPunto;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Enum\PuntoModoEnum;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * De qué segmento salen el recojo y la entrega de un servicio cotizado.
 *
 * Se prueba `paraServicio()` con el mapa de maestros inyectado a mano: es público justamente para
 * eso, así que esta regla —que es la que decide lo que se le dice a un proveedor— se verifica sin
 * base de datos y en centésimas.
 *
 * ⚠️ **El caso que motiva la clase es el override.** El extremo del día MANDA si declara algo, y
 * si no se cae al segmento del que cuelga el componente. La primera versión cogía el primero y el
 * último del día sin mirar, y eso BORRABA información: un pool colgado de un segmento que sí sabe
 * dónde recoge, en un día cuyo primer segmento no dice nada, se quedaba sin punto de recojo. No se
 * notaba porque hoy todos los abarcadores cuelgan del primer segmento de su día — y entonces los
 * dos caminos coinciden.
 */
final class CotizacionPuntosDelServicioTest extends TestCase
{
    /** @var array<string, TravelSegmento> */
    private array $maestros = [];

    private function maestro(string $id, PuntoModoEnum $inicio, PuntoModoEnum $fin, ?string $punto = null): string
    {
        $segmento = new TravelSegmento();
        $segmento->setInicioModo($inicio);
        $segmento->setFinModo($fin);

        if ($punto !== null) {
            $p = new TravelPunto();
            $p->setNombre($punto);

            if ($inicio === PuntoModoEnum::FIJO) {
                $segmento->setInicioPunto($p);
            }

            if ($fin === PuntoModoEnum::FIJO) {
                $segmento->setFinPunto($p);
            }
        }

        $this->maestros[$id] = $segmento;

        return $id;
    }

    private function segmento(CotizacionCotservicio $servicio, int $orden, string $maestroId): CotizacionSegmento
    {
        $seg = new CotizacionSegmento();
        $seg->setDia(1);
        $seg->setOrden($orden);
        $seg->setSegmentoMaestroId($maestroId);
        $servicio->addCotsegmento($seg);

        return $seg;
    }

    private function componente(CotizacionCotservicio $servicio, CotizacionSegmento $seg, string $tipo, bool $abarca): void
    {
        $comp = new CotizacionCotcomponente();
        $comp->setTipo($tipo);
        $comp->setNombreSnapshot([['language' => 'es', 'content' => 'Servicio de prueba']]);
        $comp->setCotsegmento($seg);
        $comp->setHoraServicioCompleto($abarca);
        $servicio->addCotcomponente($comp);
    }

    private function servicio(): CotizacionPuntosDelServicio
    {
        // El EntityManager sólo lo usa `paraCotizacion()` para cargar los maestros en lote; aquí
        // se inyectan a mano, así que no llega a tocarse.
        return new CotizacionPuntosDelServicio($this->createStub(EntityManagerInterface::class));
    }

    #[Test]
    public function el_ultimo_segmento_del_dia_MANDA_sobre_el_del_componente(): void
    {
        // El caso normal: el pool cuelga del recojo, que no sabe dónde acaba; el último segmento
        // del día sí lo sabe, y es el que gana.
        $servicio = new CotizacionCotservicio();
        $recojo = $this->segmento($servicio, 1, $this->maestro('m1', PuntoModoEnum::ALOJAMIENTO, PuntoModoEnum::SIN_DEFINIR));
        $this->segmento($servicio, 2, $this->maestro('m2', PuntoModoEnum::SIN_DEFINIR, PuntoModoEnum::FIJO, 'Plaza de Armas de Cusco'));
        $this->componente($servicio, $recojo, 'pool', abarca: true);

        $r = $this->servicio()->paraServicio($servicio, $this->maestros);

        self::assertSame('el alojamiento del pasajero', $r['inicio']['texto']);
        self::assertSame('Plaza de Armas de Cusco', $r['fin']['texto']);
        self::assertTrue($r['completo']);
    }

    #[Test]
    public function si_el_primero_del_dia_NO_declara_nada_manda_el_segmento_del_componente(): void
    {
        // ⚠️ El fallo que esto cierra. El componente cuelga del segmento 2, que SÍ dice dónde
        // recoge; el 1 del día no dice nada. Antes ganaba el 1 y la orden salía sin punto de
        // recojo, borrando un dato que estaba escrito.
        $servicio = new CotizacionCotservicio();
        $this->segmento($servicio, 1, $this->maestro('m1', PuntoModoEnum::SIN_DEFINIR, PuntoModoEnum::SIN_DEFINIR));
        $suyo = $this->segmento($servicio, 2, $this->maestro('m2', PuntoModoEnum::FIJO, PuntoModoEnum::SIN_DEFINIR, 'Estación de Ollantaytambo'));
        $this->segmento($servicio, 3, $this->maestro('m3', PuntoModoEnum::SIN_DEFINIR, PuntoModoEnum::ALOJAMIENTO));
        $this->componente($servicio, $suyo, 'pool', abarca: true);

        $r = $this->servicio()->paraServicio($servicio, $this->maestros);

        self::assertSame('Estación de Ollantaytambo', $r['inicio']['texto']);
        self::assertSame('el alojamiento del pasajero', $r['fin']['texto']);
    }

    #[Test]
    public function el_primero_del_dia_gana_cuando_SI_declara(): void
    {
        // La otra mitad del override: si el día declara, manda el día — aunque el segmento del
        // componente diga otra cosa. Es lo que permite que una plantilla cambie el recojo.
        $servicio = new CotizacionCotservicio();
        $this->segmento($servicio, 1, $this->maestro('m1', PuntoModoEnum::ALOJAMIENTO, PuntoModoEnum::SIN_DEFINIR));
        $suyo = $this->segmento($servicio, 2, $this->maestro('m2', PuntoModoEnum::FIJO, PuntoModoEnum::ALOJAMIENTO, 'Estación de Poroy'));
        $this->componente($servicio, $suyo, 'pool', abarca: true);

        $r = $this->servicio()->paraServicio($servicio, $this->maestros);

        self::assertSame('el alojamiento del pasajero', $r['inicio']['texto']);
    }

    #[Test]
    public function un_componente_que_NO_abarca_usa_solo_su_propio_segmento(): void
    {
        // Un tren de media mañana no hereda los extremos del día: los suyos son los de su tramo.
        $servicio = new CotizacionCotservicio();
        $this->segmento($servicio, 1, $this->maestro('m1', PuntoModoEnum::ALOJAMIENTO, PuntoModoEnum::SIN_DEFINIR));
        $suyo = $this->segmento($servicio, 2, $this->maestro('m2', PuntoModoEnum::FIJO, PuntoModoEnum::FIJO, 'Estación de Ollantaytambo'));
        $this->componente($servicio, $suyo, 'tren', abarca: false);

        $r = $this->servicio()->paraServicio($servicio, $this->maestros);

        self::assertSame('Estación de Ollantaytambo', $r['inicio']['texto']);
        self::assertSame('Estación de Ollantaytambo', $r['fin']['texto']);
    }

    #[Test]
    public function un_servicio_sin_nada_que_recoja_no_aplica(): void
    {
        // Alojamientos, tickets y comidas. `aplica: false` no es un hueco, y la vista NO tiene
        // que pintarles aviso: en rojo saldría media cotización y el rojo dejaría de significar.
        $servicio = new CotizacionCotservicio();
        $seg = $this->segmento($servicio, 1, $this->maestro('m1', PuntoModoEnum::SIN_DEFINIR, PuntoModoEnum::SIN_DEFINIR));
        $this->componente($servicio, $seg, 'ticket_variable', abarca: false);

        $r = $this->servicio()->paraServicio($servicio, $this->maestros);

        self::assertFalse($r['aplica']);
        self::assertTrue($r['completo']);
        self::assertSame([], $r['faltantes']);
    }

    #[Test]
    public function un_guiado_no_arrastra_su_fin_sin_declarar(): void
    {
        $servicio = new CotizacionCotservicio();
        $seg = $this->segmento($servicio, 1, $this->maestro('m1', PuntoModoEnum::ALOJAMIENTO, PuntoModoEnum::SIN_DEFINIR));
        $this->componente($servicio, $seg, 'guiado', abarca: false);

        $r = $this->servicio()->paraServicio($servicio, $this->maestros);

        self::assertFalse($r['tieneFin']);
        self::assertTrue($r['completo']);
    }
}
