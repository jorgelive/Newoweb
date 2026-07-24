<?php

declare(strict_types=1);

namespace App\Api\Provider\Cotizacion;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Cotizacion\Entity\CotizacionFile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Decora el provider de colección de Doctrine para el listado admin de
 * expedientes (dashboard), adjuntando la fecha de primer servicio de cada
 * versión sin hidratar $cotizaciones/$cotservicios por fila.
 *
 * Mismo objetivo de rendimiento que CotizacionFilePublicProvider: UN query
 * escalar batched para toda la página, en vez de N+1.
 */
final class CotizacionFileCollectionProvider implements ProviderInterface
{
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

        $filas = $this->em->createQuery(<<<'DQL'
            SELECT f.id AS fileId, c.version, MIN(s.fechaInicioAbsoluta) AS fechaInicio
            FROM App\Cotizacion\Entity\CotizacionFile f
            JOIN f.cotizaciones c
            LEFT JOIN c.cotservicios s
            WHERE f.id IN (:fileIds)
            GROUP BY f.id, c.version
            ORDER BY c.version ASC
        DQL)
            ->setParameter('fileIds', $fileIds)
            ->getArrayResult();

        $porFile = [];
        foreach ($filas as $f) {
            $fileId = (string) $f['fileId'];
            $porFile[$fileId][] = [
                'version'     => $f['version'],
                'fechaInicio' => $f['fechaInicio'] instanceof \DateTimeInterface
                    ? $f['fechaInicio']->format('Y-m-d')
                    : ($f['fechaInicio'] ? substr((string) $f['fechaInicio'], 0, 10) : null),
            ];
        }

        foreach ($files as $file) {
            \assert($file instanceof CotizacionFile);
            $file->setVersionesFechas($porFile[(string) $file->getId()] ?? []);
        }

        return $collection;
    }
}
