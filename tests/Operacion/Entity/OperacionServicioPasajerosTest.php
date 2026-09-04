<?php

declare(strict_types=1);

namespace App\Tests\Operacion\Entity;

use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Cotizacion\Entity\CotizacionFileGrupo;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Entity\CotizacionPasajeroGrupo;
use App\Cotizacion\Enum\GrupoTipoEnum;
use App\Operacion\Entity\OperacionServicio;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Quiénes van en una orden.
 *
 * ⚠️ Se DERIVA de los subgrupos del componente, no se congela: La Biblia guarda un snapshot de
 * **valores** —precios, fechas— porque eso se acordó en un momento, pero **quién viaja cambia
 * hasta el día de salida**. Una orden con la lista de hace tres semanas manda a gente que ya no va.
 */
final class OperacionServicioPasajerosTest extends TestCase
{
    private function pasajero(string $nombre, string $apellido): CotizacionFilepasajero
    {
        $p = (new CotizacionFilepasajero())->setNombre($nombre)->setApellido($apellido);
        (new \ReflectionProperty(CotizacionFilepasajero::class, 'id'))->setValue($p, Uuid::v4());

        return $p;
    }

    /** @param array<string, CotizacionFilepasajero> $miembros nombre visible → pasajero */
    private function grupo(string $clave, array $miembros, ?string $codigo = null): CotizacionFileGrupo
    {
        $g = (new CotizacionFileGrupo())->setTipo(GrupoTipoEnum::RESERVA_AEREA)->setClave($clave);

        foreach ($miembros as $pax) {
            $pert = (new CotizacionPasajeroGrupo())->setGrupo($g)->setPasajero($pax);
            $pert->setCodigo($codigo);
            $g->getMiembros()->add($pert);
        }

        return $g;
    }

    private function orden(CotizacionFileGrupo ...$grupos): OperacionServicio
    {
        $comp = new CotizacionCotcomponente();

        foreach ($grupos as $g) {
            $comp->addGrupo($g);
        }

        return (new OperacionServicio())->setCotizacionComponente($comp);
    }

    public function testSinSubgruposDevuelveVACIO(): void
    {
        // ⚠️ Vacío significa «todo el grupo». Devolver las 133 fichas convertiría la orden de un
        // hotel en un listado inútil: quien la pinta dice «todo el grupo», que ES la información.
        self::assertSame([], $this->orden()->getPasajeros());
    }

    public function testDevuelveLaGenteDelSubgrupoConSuCodigo(): void
    {
        $ana = $this->pasajero('Ana', 'Pérez');
        $orden = $this->orden($this->grupo('YMFLHB', ['ana' => $ana], 'XKP4QT'));

        $pax = $orden->getPasajeros();

        self::assertCount(1, $pax);
        self::assertSame('Ana Pérez', $pax[0]['nombre']);
        self::assertSame('XKP4QT', $pax[0]['codigo']);
    }

    public function testSinCodigoPROPIOcaeEnLaClaveDelSubgrupo(): void
    {
        // El localizador del grupo es lo que hay cuando la aerolínea no da uno por persona.
        $orden = $this->orden($this->grupo('YMFLHB', ['a' => $this->pasajero('Ana', 'Pérez')]));

        self::assertSame('YMFLHB', $orden->getPasajeros()[0]['codigo']);
    }

    public function testJuntaVARIOSSubgruposSinRepetirAnadie(): void
    {
        // 🔥 El caso del vuelo JA7018: 7 PNRs en un componente. Y una persona puede estar en dos
        // subgrupos —dos cortes arbitrarios del eje GRUPO—, así que se cuenta por PASAJERO.
        $ana = $this->pasajero('Ana', 'Pérez');
        $beto = $this->pasajero('Beto', 'Quispe');

        $orden = $this->orden(
            $this->grupo('AAA111', ['a' => $ana, 'b' => $beto]),
            $this->grupo('BBB222', ['a' => $ana]),
        );

        $pax = $orden->getPasajeros();

        self::assertCount(2, $pax, 'Ana estaba en los dos subgrupos y no debe salir dos veces');
    }

    public function testSalenOrdenadosPorNombre(): void
    {
        // Una orden se lee pasando lista; el orden de la base no ayuda a eso.
        $orden = $this->orden($this->grupo('AAA111', [
            'z' => $this->pasajero('Zoe', 'Ramos'),
            'a' => $this->pasajero('Ana', 'Pérez'),
        ]));

        self::assertSame(['Ana Pérez', 'Zoe Ramos'], array_column($orden->getPasajeros(), 'nombre'));
    }
}
