<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Repository;

use App\Exchange\Repository\AbstractExchangeRepository;
use App\Exchange\Service\Contract\ExchangeQueueItemInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Uid\Uuid;

/**
 * El saneador de IDs de las colas de intercambio.
 *
 * ── Por qué merece prueba propia ────────────────────────────────────────────
 * `normalizeToBinary()` es la puerta por la que entran los IDs a las **siete** colas que heredan
 * de este repositorio (Beds24 send/receive, WhatsApp send, bookings pull/push, rates push,
 * invoice receive). Lo que se le cuele aquí se cuela en todas a la vez.
 *
 * Y lo que recibe no está garantizado: viaja por un transporte de Messenger dentro de
 * `RunExchangeTaskDispatch`, cuyo `array $ids` PHP no comprueba elemento a elemento al
 * deserializar. De ahí que el método filtre en vez de confiar.
 *
 * Se usa una subclase mínima que se salta el constructor de `ServiceEntityRepository` —pediría un
 * `ManagerRegistry` entero—, porque este método no toca la base: es conversión pura.
 */
final class AbstractExchangeRepositoryTest extends TestCase
{
    /**
     * La cola más pequeña posible. Sólo existe para poder llegar al saneador: los dos métodos
     * abstractos no se llaman en ninguna prueba de este fichero.
     */
    private function repositorio(): AbstractExchangeRepository
    {
        /** @extends AbstractExchangeRepository<ExchangeQueueItemInterface> */
        $clase = new class () extends AbstractExchangeRepository {
            /** Vacío a propósito: el padre pediría el `ManagerRegistry` que aquí no hace falta. */
            public function __construct()
            {
            }

            protected function getTableName(): string
            {
                return 'cola_de_prueba';
            }

            /**
             * @param list<string> $ids
             *
             * @return list<ExchangeQueueItemInterface>
             */
            protected function hydrateItems(array $ids): array
            {
                return [];
            }
        };

        return $clase;
    }

    /**
     * @param array<array-key, mixed> $ids
     *
     * @return list<string>
     */
    private function normalizar(array $ids): array
    {
        $repo = $this->repositorio();

        $metodo = new ReflectionMethod(AbstractExchangeRepository::class, 'normalizeToBinary');
        $metodo->setAccessible(true);

        /** @var list<string> $salida */
        $salida = $metodo->invoke($repo, $ids);

        return $salida;
    }

    public function testConvierteConYSinGuionesAlMismoBinario(): void
    {
        $uuid = Uuid::v7();

        $salida = $this->normalizar([(string) $uuid, str_replace('-', '', (string) $uuid)]);

        // Son el mismo id escrito de dos formas: tienen que colapsar en uno solo.
        self::assertSame([$uuid->toBinary()], $salida);
    }

    /**
     * La razón del `array_values` que envuelve al `array_unique`.
     *
     * `array_unique` CONSERVA las claves, así que con un repetido en medio devolvía `[0 => …,
     * 2 => …]` y eso no es una lista. `hydrateItems()` pide `list<string>` en su contrato, y era
     * quien recibía los huecos. Hoy no rompía nada —DBAL recorre los valores y le dan igual las
     * claves—, pero el contrato decía una cosa y llegaba otra.
     */
    public function testElResultadoEsUnaListaSinHuecosAunConRepetidosEnMedio(): void
    {
        $a = Uuid::v7();
        $b = Uuid::v7();

        $salida = $this->normalizar([(string) $a, (string) $a, (string) $b]);

        self::assertSame([0, 1], array_keys($salida));
        self::assertSame([$a->toBinary(), $b->toBinary()], $salida);
    }

    public function testLaBasuraSeDescartaSinRomperElLote(): void
    {
        $bueno = Uuid::v7();

        $salida = $this->normalizar([
            'no-soy-un-uuid',
            '',
            null,
            42,
            ['anidado'],
            (string) $bueno,
        ]);

        // El id bueno tiene que sobrevivir a los cinco de al lado: un dato sucio en el mensaje no
        // puede tumbar el lote entero, que es justo lo que evita el `continue` del bucle.
        self::assertSame([$bueno->toBinary()], $salida);
    }

    public function testSinNadaUtilizableDevuelveVacio(): void
    {
        self::assertSame([], $this->normalizar([]));
        self::assertSame([], $this->normalizar(['', null, 'basura']));
    }

    public function testCadaBinarioMideDieciseisBytes(): void
    {
        // Si algún día se reintrodujera el byte-swapping de UUID v1 o se colara un `hex2bin` de
        // más, esto lo caza: la columna es BINARY(16) y un id de otro tamaño no casa con nada.
        foreach ($this->normalizar([(string) Uuid::v7(), (string) Uuid::v4()]) as $binario) {
            self::assertSame(16, \strlen($binario));
        }
    }
}
