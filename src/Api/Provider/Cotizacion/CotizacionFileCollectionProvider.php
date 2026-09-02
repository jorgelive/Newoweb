<?php

declare(strict_types=1);

namespace App\Api\Provider\Cotizacion;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Decora el provider de colección de Doctrine para el listado admin de
 * expedientes (dashboard), adjuntando la fecha de primer servicio de cada
 * versión sin hidratar $cotizaciones/$cotservicios por fila.
 *
 * Mismo objetivo de rendimiento que CotizacionFilePublicProvider: UN query
 * escalar batched para toda la página, en vez de N+1.
 *
 * @implements ProviderInterface<CotizacionFile>
 */
final class CotizacionFileCollectionProvider implements ProviderInterface
{
    /**
     * @param ProviderInterface<CotizacionFile> $collectionProvider El de Doctrine, al que se decora.
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.collection_provider')]
        private readonly ProviderInterface $collectionProvider,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $collection = $this->collectionProvider->provide($operation, $uriVariables, $context);

        // El provider decorado devuelve la colección o —en una operación de ítem— la entidad
        // suelta. Sin esto, el `foreach` sobre la segunda forma es un error de tipo.
        $files = [];

        if (!is_iterable($collection)) {
            return $collection;
        }

        foreach ($collection as $file) {
            $files[] = $file;
        }

        if ($files === []) {
            return $collection;
        }

        // IMPORTANTE: no bindear entidades/Uuid directamente en el IN() —
        // Doctrine no resuelve el tipo custom 'uuid' para arrays y cae a un
        // bind genérico (invoca __toString() de la entidad), por lo que el
        // WHERE nunca matchea. Hay que pasar el binario crudo del UUID.
        $fileIds = array_map(
            static fn (CotizacionFile $file): string => $file->getId()->toBinary(),
            $files
        );

        // ⚠️ `GROUP BY c.id` y no `c.propuesta`: `estado` y `titulo` dependen funcionalmente de la
        // clave, y así MySQL los acepta en el SELECT sin meterlos en el GROUP BY —agrupar por una
        // columna JSON funciona pero es pedirle al motor que compare documentos para nada—.
        // ⚠️ El `WITH` del último JOIN es la condición EXACTA de `esEstadia` —la misma que aplican
        // `PaxCotizacionGuiaView` y el chequeo `multidia-sin-noche`—, y no está ahí por elegancia:
        // con `MAX(k.fechaHoraFin)` a secas, un traslado de las 22:00 que acaba a las 00:30
        // adelantaría el fin del viaje un día entero. **Cruzar medianoche no es durar dos días.**
        //
        // Y hace falta además de `MAX(s.fechaInicioAbsoluta)` porque un viaje puede acabar en un
        // checkout sin ningún servicio ese día: el último bloque del itinerario sería la víspera.
        $filas = $this->em->createQuery(<<<'DQL'
            SELECT f.id AS fileId, c.id AS cotizacionId, c.propuesta, c.estado, c.titulo,
                   MIN(s.fechaInicioAbsoluta) AS fechaInicio,
                   MAX(s.fechaInicioAbsoluta) AS fechaUltimoServicio,
                   MAX(k.fechaHoraFin) AS finEstadia
            FROM App\Cotizacion\Entity\CotizacionFile f
            JOIN f.cotizaciones c
            LEFT JOIN c.cotservicios s
            LEFT JOIN s.cotcomponentes k
                 WITH k.sinHorario = true AND DATE_DIFF(k.fechaHoraFin, k.fechaHoraInicio) > 0
            WHERE f.id IN (:fileIds)
            GROUP BY f.id, c.id
            ORDER BY c.propuesta ASC
        DQL)
            ->setParameter('fileIds', $fileIds)
            ->getArrayResult();

        $porFile = [];
        foreach ($filas as $f) {
            $fileId = (string) $f['fileId'];
            $estado = $f['estado'] ?? null;

            $porFile[$fileId][] = [
                // ⚠️ EL ID, y no por capricho: **la versión NO es única dentro del expediente**.
                // Un `historico` comparte número con la viva a propósito —es su foto congelada
                // antes de tocarla, ver §6 del doc— y `2KVBMX` tiene hoy dos filas en la V1.
                // Agrupando por `c.id` salen las dos, que es lo que se quiere; pero el front las
                // pinta con `v-for` y con `propuesta` de clave habría dos claves iguales.
                'id'          => (string) $f['cotizacionId'],
                'propuesta'   => $f['propuesta'],
                // El estado y el título de CADA versión: en el dashboard se veía «V1: 30 oct.» y
                // nada más, así que un expediente con tres propuestas —una confirmada, una
                // cancelada y un histórico— se leía igual que uno con tres pendientes.
                'estado'      => $estado instanceof CotizacionEstadoEnum ? $estado->value : (string) $estado,
                // El i18n crudo: lo traduce el front con el idioma del panel, como el resto de
                // títulos. Resolverlo aquí obligaría a que el provider supiera qué idioma mira
                // quien pidió la página.
                'titulo'      => is_array($f['titulo'] ?? null) ? $f['titulo'] : [],
                'fechaInicio' => self::soloFecha($f['fechaInicio']),
                // El fin del viaje: el último día del itinerario, salvo que una estadía termine
                // más tarde (el checkout que ya no tiene servicio propio). Se comparan como
                // `Y-m-d`, que ordena igual que las fechas que representa.
                'fechaFin'    => max(
                    self::soloFecha($f['fechaUltimoServicio']) ?? '',
                    self::soloFecha($f['finEstadia']) ?? ''
                ) ?: null,
            ];
        }

        // Sin `assert($file instanceof CotizacionFile)`: lo garantiza ahora el
        // `@param ProviderInterface<CotizacionFile>` del constructor, y eso es más fuerte que el
        // assert — `zend.assertions=-1` lo compila fuera en producción, así que sólo avisaba en
        // desarrollo. La anotación la comprueba PHPStan en cada pasada.
        //
        // ⚠️ Lo que afirma es el CABLEADO: que el provider decorado
        // (`api_platform.doctrine.orm.state.collection_provider`) sirve esta colección. Eso vive
        // en la metadata del recurso, no en este archivo. Si cambia, la anotación miente.
        foreach ($files as $file) {
            $file->setPropuestasFechas($porFile[(string) $file->getId()] ?? []);
        }

        return $collection;
    }

    /**
     * El día de un valor escalar de Doctrine, o `null` si no hay ninguno.
     *
     * Hoy las tres agregaciones llegan como **cadena** —`getArrayResult()` no aplica la
     * conversión de tipo a lo que sale de un `MIN`/`MAX`, así que `fechaHoraFin` viene tal cual,
     * `'2026-09-15 18:30:00'`—; de ahí el `substr()`, que es el que hace el trabajo real.
     *
     * La rama del `DateTimeInterface` se queda porque esa conversión sí depende de la plataforma
     * y del driver, y perderla convertiría un cambio de versión en un `fechaFin` mudo a `null`.
     * Se hereda del código anterior, que ya la traía.
     */
    private static function soloFecha(mixed $valor): ?string
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d');
        }

        return is_string($valor) && $valor !== '' ? substr($valor, 0, 10) : null;
    }
}
