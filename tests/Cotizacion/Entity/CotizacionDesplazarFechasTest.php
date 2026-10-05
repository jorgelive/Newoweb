<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Entity;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Cotizacion\Entity\CotizacionCotservicio;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Mover un viaje de fechas **sin desordenarlo**.
 *
 * ⚠️ Es la pieza con riesgo de clonar una cotización a otro expediente: el error que importa no es
 * olvidar un servicio —eso se ve— sino **barajar los días**, porque un itinerario con los días
 * cambiados se lee perfectamente plausible y nadie lo revisa.
 */
final class CotizacionDesplazarFechasTest extends TestCase
{
    /** @param list<array{string, string|null}> $servicios  [fecha del servicio, hora del componente] */
    private function cotizacion(array $servicios): Cotizacion
    {
        $cotizacion = new Cotizacion();

        foreach ($servicios as [$fecha, $horaComponente]) {
            $servicio = new CotizacionCotservicio();
            $servicio->setFechaInicioAbsoluta($fecha === '' ? null : new DateTimeImmutable($fecha));

            if ($horaComponente !== null) {
                $componente = new CotizacionCotcomponente();
                $componente->setFechaHoraInicio(new DateTimeImmutable($horaComponente));
                $servicio->addCotcomponente($componente);
            }

            $cotizacion->addCotservicio($servicio);
        }

        return $cotizacion;
    }

    /** @return list<string> */
    private function fechas(Cotizacion $c): array
    {
        $salida = [];

        foreach ($c->getCotservicios() as $s) {
            $salida[] = $s->getFechaInicioAbsoluta()?->format('Y-m-d') ?? '—';
        }

        return $salida;
    }

    #[Test]
    public function conserva_la_separacion_entre_servicios(): void
    {
        // El caso real: viaje de promoción del 17 al 23 de septiembre, movido a julio del año
        // siguiente. Lo que no puede pasar es que el día 4 deje de estar a 3 días del día 1.
        $c = $this->cotizacion([
            ['2026-09-17', null], ['2026-09-18', null], ['2026-09-20', null], ['2026-09-23', null],
        ]);

        $dias = $c->desplazarA(new DateTimeImmutable('2027-07-15'));

        self::assertSame(301, $dias);
        self::assertSame(['2027-07-15', '2027-07-16', '2027-07-18', '2027-07-21'], $this->fechas($c));
    }

    #[Test]
    public function la_hora_del_componente_no_se_mueve(): void
    {
        // ⚠️ Un recojo a las 04:00 tiene que seguir a las 04:00. Desplazar en horas en vez de días
        // movería los recojos de madrugada al día anterior al cruzar el cambio de día.
        $c = $this->cotizacion([['2026-09-17', '2026-09-17 04:00:00']]);

        $c->desplazarA(new DateTimeImmutable('2027-07-15'));

        $componente = $c->getCotservicios()->first()->getCotcomponentes()->first();
        self::assertNotFalse($componente);
        self::assertSame('2027-07-15 04:00', $componente->getFechaHoraInicio()?->format('Y-m-d H:i'));
    }

    #[Test]
    public function un_servicio_sin_fecha_se_queda_sin_fecha(): void
    {
        // «Todavía no tiene día» es un dato. Inventarle uno a partir del vecino lo borraría.
        $c = $this->cotizacion([['2026-09-17', null], ['', null], ['2026-09-19', null]]);

        $c->desplazarA(new DateTimeImmutable('2026-10-01'));

        self::assertSame(['2026-10-01', '—', '2026-10-03'], $this->fechas($c));
    }

    #[Test]
    public function el_ancla_es_el_servicio_mas_TEMPRANO_no_el_primero_de_la_lista(): void
    {
        // La colección no está necesariamente ordenada por fecha. Anclar al primero de la lista
        // movería el viaje a un sitio equivocado sin que ninguna fecha pareciera rara.
        $c = $this->cotizacion([['2026-09-20', null], ['2026-09-17', null], ['2026-09-23', null]]);

        $c->desplazarA(new DateTimeImmutable('2026-09-17'));

        self::assertSame(['2026-09-20', '2026-09-17', '2026-09-23'], $this->fechas($c), 'Mismo día: no se mueve nada.');
    }

    #[Test]
    public function desplaza_hacia_atras(): void
    {
        $c = $this->cotizacion([['2026-09-17', null], ['2026-09-19', null]]);

        self::assertSame(-7, $c->desplazarA(new DateTimeImmutable('2026-09-10')));
        self::assertSame(['2026-09-10', '2026-09-12'], $this->fechas($c));
    }

    #[Test]
    public function sin_ninguna_fecha_devuelve_null(): void
    {
        // Una plantilla sin fechas puestas: desplazar no significa nada, y el procesador lo
        // convierte en un error en vez de decir que movió un viaje que no movió.
        self::assertNull($this->cotizacion([['', null], ['', null]])->desplazarA(new DateTimeImmutable('2027-01-01')));
    }

    #[Test]
    public function cruza_un_cambio_de_anio_bisiesto(): void
    {
        // Del 28 de febrero de un año normal al de uno bisiesto: si el salto se calculara en
        // meses en vez de días, el 1 de marzo se convertiría en 29 de febrero.
        $c = $this->cotizacion([['2027-02-28', null], ['2027-03-01', null]]);

        $c->desplazarA(new DateTimeImmutable('2028-02-28'));

        self::assertSame(['2028-02-28', '2028-02-29'], $this->fechas($c));
    }
}
