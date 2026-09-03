<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Service;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFileGrupo;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Entity\CotizacionPasajeroGrupo;
use App\Cotizacion\Enum\FileModoEnum;
use App\Cotizacion\Enum\GrupoTipoEnum;
use App\Cotizacion\Service\CoberturaDeSubgrupos;
use PHPUnit\Framework\TestCase;

/**
 * El aviso sobre gente que NO puede reclamarlo.
 *
 * Si el vuelo se parte en dos y alguien no está en ninguno, abre su viaje y no ve ningún vuelo. No
 * hay error ni fila roja: hay un silencio. Y no lo reporta, porque no echa de menos lo que no
 * sabía que existía — así que la única red posible es preguntarlo antes.
 */
final class CoberturaDeSubgruposTest extends TestCase
{
    private function expediente(FileModoEnum $modo = FileModoEnum::GRUPO): CotizacionFile
    {
        return (new CotizacionFile())->setModo($modo);
    }

    private function pasajero(CotizacionFile $file, string $nombre): CotizacionFilepasajero
    {
        $pax = (new CotizacionFilepasajero())->setNombre($nombre)->setApellido('Pérez');
        $file->addFilepasajero($pax);

        return $pax;
    }

    /** @param list<CotizacionFilepasajero> $miembros */
    private function subgrupo(CotizacionFile $file, GrupoTipoEnum $tipo, string $nombre, array $miembros): void
    {
        $grupo = (new CotizacionFileGrupo())->setTipo($tipo)->setNombre($nombre);
        $file->addGrupo($grupo);

        foreach ($miembros as $pax) {
            $grupo->getMiembros()->add(
                (new CotizacionPasajeroGrupo())->setGrupo($grupo)->setPasajero($pax)
            );
        }
    }

    public function testSinSubgruposNoAvisa(): void
    {
        $file = $this->expediente();
        $this->pasajero($file, 'Ana');

        // ⚠️ Que no haya vuelos declarados significa que este viaje no usa ese eje, no que falten
        // 41 personas. Avisar aquí llenaría de rojo cualquier expediente normal, y una lista que
        // casi siempre miente no la lee nadie — que es cómo se pierde el aviso que sí importaba.
        self::assertSame([], (new CoberturaDeSubgrupos())->revisar($file));
    }

    public function testAvisaDeQuienNoEstaEnNingunSubgrupoDelEje(): void
    {
        $file = $this->expediente();
        $ana = $this->pasajero($file, 'Ana');
        $beto = $this->pasajero($file, 'Beto');
        $caro = $this->pasajero($file, 'Caro');

        $this->subgrupo($file, GrupoTipoEnum::RESERVA_AEREA, 'Nacional', [$ana]);
        $this->subgrupo($file, GrupoTipoEnum::RESERVA_AEREA, 'Internacional', [$beto]);

        $hallazgos = (new CoberturaDeSubgrupos())->revisar($file);

        self::assertCount(1, $hallazgos);
        self::assertSame('reserva_aerea', $hallazgos[0]['eje']);
        self::assertSame('Vuelo', $hallazgos[0]['ejeLabel']);
        self::assertSame(['Caro Pérez'], $hallazgos[0]['faltan']);
        self::assertSame([$caro->getNombre()], ['Caro']);
    }

    public function testLaUnionCubreAunqueNingunSubgrupoLoHagaSolo(): void
    {
        $file = $this->expediente();
        $ana = $this->pasajero($file, 'Ana');
        $beto = $this->pasajero($file, 'Beto');

        $this->subgrupo($file, GrupoTipoEnum::RESERVA_AEREA, 'Nacional', [$ana]);
        $this->subgrupo($file, GrupoTipoEnum::RESERVA_AEREA, 'Internacional', [$beto]);

        self::assertSame([], (new CoberturaDeSubgrupos())->revisar($file));
    }

    public function testCadaEjeSePreguntaPorSeparado(): void
    {
        $file = $this->expediente();
        $ana = $this->pasajero($file, 'Ana');
        $beto = $this->pasajero($file, 'Beto');

        // Los dos tienen habitación; sólo Ana tiene vuelo.
        $this->subgrupo($file, GrupoTipoEnum::HABITACION, '101', [$ana, $beto]);
        $this->subgrupo($file, GrupoTipoEnum::RESERVA_AEREA, 'Nacional', [$ana]);

        $hallazgos = (new CoberturaDeSubgrupos())->revisar($file);

        // ⚠️ Estar en una habitación NO cubre no estar en ningún vuelo. Si esta prueba se pusiera
        // verde con un solo eje agregado, el aviso habría dejado de ser útil justo en el caso que
        // motivó escribirlo.
        self::assertCount(1, $hallazgos);
        self::assertSame('reserva_aerea', $hallazgos[0]['eje']);
        self::assertSame(['Beto Pérez'], $hallazgos[0]['faltan']);
    }

    public function testUnSubgrupoVacioSiCuentaComoEjeDeclarado(): void
    {
        $file = $this->expediente();
        $this->pasajero($file, 'Ana');

        // Alguien creó el eje y no metió a nadie: eso SÍ es un descuido que hay que denunciar.
        $this->subgrupo($file, GrupoTipoEnum::RESERVA_AEREA, 'Nacional', []);

        $hallazgos = (new CoberturaDeSubgrupos())->revisar($file);

        self::assertCount(1, $hallazgos);
        self::assertSame(['Ana Pérez'], $hallazgos[0]['faltan']);
    }

    public function testUnExpedienteSinPadronNoSeRevisa(): void
    {
        $file = $this->expediente(FileModoEnum::ESTANDAR);
        $ana = $this->pasajero($file, 'Ana');
        $this->subgrupo($file, GrupoTipoEnum::RESERVA_AEREA, 'Nacional', []);

        // Para dos pasajeros, montar salones y avisos de cobertura es maquinaria que estorba.
        self::assertSame([], (new CoberturaDeSubgrupos())->revisar($file));
        self::assertSame('Ana', $ana->getNombre());
    }
}
