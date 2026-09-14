<?php

declare(strict_types=1);

namespace App\Tests\Api\Provider\Cotizacion;

use App\Api\Provider\Cotizacion\CotizacionFilePublicProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * El orden de las tarjetas de «Lo tuyo».
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * 🔥 **No había orden ninguno.** Ni en la vista, ni en el store, ni aquí, y
 * `CotizacionFilepasajero::$pertenencias` tampoco lleva `#[ORM\OrderBy]`. Así que el orden era el
 * que devolvía MySQL al hidratar sin `ORDER BY`: en la práctica el de creación de los grupos, o
 * sea **el orden de las columnas del Excel del padrón**.
 *
 * Se veía en pantalla: el vuelo del **17** salía DESPUÉS del vuelo del **18**, con el grupo y la
 * habitación metidos entre los dos.
 *
 * ⚠️ Y no bastaba con ordenar por eje: «Nacional» e «Internacional» son el MISMO eje
 * (`reserva_aerea`) y sólo se distinguen por un `subeje` de texto libre. Por eje quedarían
 * empatados y el desempate volvería a ser el del padrón — que es el fallo de partida. Por eso el
 * criterio de los vuelos es la HORA DE SALIDA.
 */
final class SubgruposDeLoTuyoTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function ordenar(array $subgrupos): array
    {
        // ⚠️ Sin constructor a propósito: `ordenarSubgrupos()` es una función pura y no toca
        // ninguna de las tres dependencias. Dárselas obligaría a doblar `IdentidadDelPasajero`,
        // que es `final` — y a montar un andamiaje que no prueba nada de lo que aquí se prueba.
        $provider = (new ReflectionClass(CotizacionFilePublicProvider::class))
            ->newInstanceWithoutConstructor();

        $metodo = new ReflectionMethod($provider, 'ordenarSubgrupos');

        /** @var list<array<string, mixed>> $ordenados */
        $ordenados = $metodo->invoke($provider, $subgrupos);

        return $ordenados;
    }

    /** @param list<array{numero: string, salida: string}> $vuelos */
    private function sg(string $eje, string $clave, array $vuelos = []): array
    {
        return [
            'eje' => $eje,
            'ejeLabel' => $eje,
            'subeje' => '',
            'clave' => $clave,
            'nombre' => null,
            'codigo' => null,
            'vuelos' => array_map(
                static fn (array $v): array => [
                    'numero' => $v['numero'],
                    'origen' => 'CUZ',
                    'destino' => 'LIM',
                    'aerolinea' => null,
                    'salida' => $v['salida'],
                    'llegada' => null,
                ],
                $vuelos,
            ),
            'miembros' => [],
        ];
    }

    /** @param list<array<string, mixed>> $ordenados */
    private function claves(array $ordenados): array
    {
        return array_map(static fn (array $sg): string => (string) $sg['clave'], $ordenados);
    }

    /**
     * 🔥 El caso de la pantalla: tal como llegaba del padrón, y cómo debe quedar.
     *
     * El internacional sale el 18 y el nacional el 17, así que el nacional va PRIMERO aunque en el
     * Excel la columna «#Vuelo Internacional» fuera antes.
     */
    public function testLosVuelosVanPrimeroYEnOrdenDeSalida(): void
    {
        $entrada = [
            $this->sg('reserva_aerea', 'IFBI5Q', [['numero' => 'DM6771', 'salida' => '2026-09-18T00:30:00-05:00']]),
            $this->sg('grupo', '6'),
            $this->sg('habitacion', 'HA26'),
            $this->sg('reserva_aerea', 'RBEJRT', [['numero' => 'JA7018', 'salida' => '2026-09-17T07:15:00-05:00']]),
        ];

        self::assertSame(
            ['RBEJRT', 'IFBI5Q', 'HA26', '6'],
            $this->claves($this->ordenar($entrada)),
        );
    }

    /**
     * ⚠️ Dos vuelos del MISMO eje y sin `subeje` que los distinga: sólo los separa la hora.
     *
     * Es el empate que un orden por eje no resolvería.
     */
    public function testDosAereosSeDesempatanPorLaHora(): void
    {
        $entrada = [
            $this->sg('reserva_aerea', 'TARDE', [['numero' => 'JA7020', 'salida' => '2026-09-17T19:00:00-05:00']]),
            $this->sg('reserva_aerea', 'TEMPRANO', [['numero' => 'JA7018', 'salida' => '2026-09-17T07:15:00-05:00']]),
        ];

        self::assertSame(['TEMPRANO', 'TARDE'], $this->claves($this->ordenar($entrada)));
    }

    /**
     * Un subgrupo aéreo SIN tramos cargados no puede colarse delante de los que sí los tienen.
     *
     * ⚠️ Es el fallo fácil de este comparador: si lo que no vuela valiera cadena vacía, ordenaría
     * ANTES que cualquier fecha y el que no tiene horario encabezaría la lista.
     */
    public function testElAereoSinTramosVaDetrasDeLosQueSiLosTienen(): void
    {
        $entrada = [
            $this->sg('reserva_aerea', 'SINTRAMOS'),
            $this->sg('reserva_aerea', 'CONTRAMOS', [['numero' => 'JA7018', 'salida' => '2026-09-17T07:15:00-05:00']]),
        ];

        self::assertSame(['CONTRAMOS', 'SINTRAMOS'], $this->claves($this->ordenar($entrada)));
    }

    /** Habitación antes que grupo: se necesita al llegar; el grupo es la referencia. */
    public function testHabitacionAntesQueGrupo(): void
    {
        $entrada = [$this->sg('grupo', '6'), $this->sg('habitacion', 'HA26')];

        self::assertSame(['HA26', '6'], $this->claves($this->ordenar($entrada)));
    }

    /** Mismo eje y misma hora: la clave decide, para que el orden no baile entre peticiones. */
    public function testEmpateTotalLoResuelveLaClave(): void
    {
        $entrada = [$this->sg('habitacion', 'HA30'), $this->sg('habitacion', 'HA14')];

        self::assertSame(['HA14', 'HA30'], $this->claves($this->ordenar($entrada)));
    }
}
