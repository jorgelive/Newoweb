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

        $files = [];
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

        // ⚠️ `GROUP BY c.id` y no `c.version`: `estado` y `titulo` dependen funcionalmente de la
        // clave, y así MySQL los acepta en el SELECT sin meterlos en el GROUP BY —agrupar por una
        // columna JSON funciona pero es pedirle al motor que compare documentos para nada—.
        $filas = $this->em->createQuery(<<<'DQL'
            SELECT f.id AS fileId, c.id AS cotizacionId, c.version, c.estado, c.titulo,
                   MIN(s.fechaInicioAbsoluta) AS fechaInicio
            FROM App\Cotizacion\Entity\CotizacionFile f
            JOIN f.cotizaciones c
            LEFT JOIN c.cotservicios s
            WHERE f.id IN (:fileIds)
            GROUP BY f.id, c.id
            ORDER BY c.version ASC
        DQL)
            ->setParameter('fileIds', $fileIds)
            ->getArrayResult();

        $porFile = [];
        foreach ($filas as $f) {
            $fileId = (string) $f['fileId'];
            $estado = $f['estado'] ?? null;

            $porFile[$fileId][] = [
                'version'     => $f['version'],
                // El estado y el título de CADA versión: en el dashboard se veía «V1: 30 oct.» y
                // nada más, así que un expediente con tres propuestas —una confirmada, una
                // cancelada y un histórico— se leía igual que uno con tres pendientes.
                'estado'      => $estado instanceof CotizacionEstadoEnum ? $estado->value : (string) $estado,
                // El i18n crudo: lo traduce el front con el idioma del panel, como el resto de
                // títulos. Resolverlo aquí obligaría a que el provider supiera qué idioma mira
                // quien pidió la página.
                'titulo'      => is_array($f['titulo'] ?? null) ? $f['titulo'] : [],
                'fechaInicio' => $f['fechaInicio'] instanceof \DateTimeInterface
                    ? $f['fechaInicio']->format('Y-m-d')
                    : ($f['fechaInicio'] ? substr((string) $f['fechaInicio'], 0, 10) : null),
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
            $file->setVersionesFechas($porFile[(string) $file->getId()] ?? []);
        }

        return $collection;
    }
}
