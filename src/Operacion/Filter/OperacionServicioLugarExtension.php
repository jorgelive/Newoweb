<?php

declare(strict_types=1);

namespace App\Operacion\Filter;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Operacion\Entity\OperacionServicio;
use App\Travel\Entity\TravelComponente;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Filtra el cuadro de tráfico por lugar / centro de operación: `?lugar[]=<uuid>&lugar[]=<uuid>`.
 * Varios lugares se combinan en OR — apretar «Lima» e «Ica» trae los dos.
 *
 * ## Por qué hace falta una extension y no basta un SearchFilter
 *
 * Operaciones no puede hacer JOIN a Travel por ninguna ruta: no tiene ni una relación Doctrine
 * hacia el catálogo, sólo importa tres enums. El único puente es
 * `CotizacionCotcomponente::$componenteMaestroId`, una columna `VARCHAR(36)` sin clave ajena.
 * Ese desacople es deliberado (borrar del catálogo no debe arrastrar historial de operaciones),
 * así que el filtro cruza en dos tiempos: resuelve la etiqueta a ids de componente maestro en
 * una consulta aparte, y luego filtra por esos ids.
 *
 * Es un filtro **vivo**, no un snapshot: re-etiquetar un componente en el catálogo se refleja
 * en el siguiente clic sin re-aplicar nada. Por eso NO se cachea la resolución.
 *
 * ## Los componentes MANUALES traen las suyas puestas
 *
 * Un componente tecleado a mano no tiene maestro al que preguntarle, así que sus ubicaciones
 * viven en `CotizacionCotcomponente::$lugaresManuales` — una lista de uuids en la propia fila.
 * Son excluyentes con el maestro por construcción (lo garantizan los setters de la entidad),
 * así que aquí no hay que desempatar: se preguntan las dos y se juntan con OR.
 *
 * Eso obliga a cruzar la columna JSON desde DQL, con `JSON_CONTAINS`/`JSON_LENGTH` registradas
 * en `config/packages/doctrine.yaml`. Es la alternativa barata a montar una tabla de relación
 * hacia el catálogo, que es justo lo que este módulo no quiere tener.
 */
final class OperacionServicioLugarExtension implements QueryCollectionExtensionInterface
{
    /** Chip «Sin etiqueta»: los que no tienen maestro o cuyo maestro no está etiquetado. */
    public const SIN_LUGAR = '__sin_lugar__';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = []
    ): void {
        if (OperacionServicio::class !== $resourceClass) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return;
        }

        $crudos = $request->query->all('lugar');
        if (!$crudos) {
            return;
        }

        $quiereSinLugar = in_array(self::SIN_LUGAR, $crudos, true);
        $uuids = $this->validar($crudos);

        // Llegó el parámetro pero no hay nada aplicable. Se devuelve vacío EN LUGAR de ignorar
        // el filtro: un filtro que se ignora en silencio es cómo se emite una orden de servicio
        // para la ciudad equivocada creyendo que estaba filtrada.
        if (!$uuids && !$quiereSinLugar) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $joinAlias = $queryNameGenerator->generateJoinAlias('cotCompLugar');

        // INNER JOIN seguro: la FK es `nullable: false` y el UniqueConstraint
        // `uniq_ops_servicio_componente` hace la relación 1:1, así que no duplica filas ni
        // rompe la paginación de API Platform.
        $queryBuilder->join(sprintf('%s.cotizacionComponente', $rootAlias), $joinAlias);

        $condiciones = [];

        if ($uuids) {
            $maestroIds = $this->componentesDeLugares($uuids);

            if ($maestroIds) {
                $param = $queryNameGenerator->generateParameterName('lugarMaestroIds');
                $condiciones[] = sprintf('%s.componenteMaestroId IN (:%s)', $joinAlias, $param);
                $queryBuilder->setParameter($param, $maestroIds);
            }

            // Y los manuales, que llevan sus uuids en la propia fila. Uno por uuid porque
            // `JSON_CONTAINS` compara contra UN candidato: con la lista entera preguntaría por
            // los que los tienen TODOS, y aquí varios lugares se combinan en OR.
            foreach ($uuids as $i => $uuid) {
                $param = $queryNameGenerator->generateParameterName('lugarManual_' . $i);
                $condiciones[] = sprintf('JSON_CONTAINS(%s.lugaresManuales, :%s) = 1', $joinAlias, $param);
                // El candidato viaja como JSON, no como texto pelado: `JSON_CONTAINS(x, 'abc')`
                // es un error de sintaxis JSON, y las comillas son parte del valor.
                $queryBuilder->setParameter($param, json_encode($uuid->toRfc4122()));
            }
        }

        if ($quiereSinLugar) {
            $etiquetados = $this->componentesEtiquetados();

            // Un componente MANUAL con ubicaciones propias ya no es «sin etiqueta»: sale en su
            // lugar y no aquí. Antes caían todos en este chip por no tener maestro, que era el
            // único sitio donde mirar.
            $manualSinEtiqueta = sprintf(
                '(%1$s.componenteMaestroId IS NULL AND JSON_LENGTH(%1$s.lugaresManuales) = 0)',
                $joinAlias
            );

            if ($etiquetados) {
                $param = $queryNameGenerator->generateParameterName('lugarEtiquetados');
                $condiciones[] = sprintf(
                    '(%1$s OR (%2$s.componenteMaestroId IS NOT NULL AND %2$s.componenteMaestroId NOT IN (:%3$s)))',
                    $manualSinEtiqueta,
                    $joinAlias,
                    $param
                );
                $queryBuilder->setParameter($param, $etiquetados);
            } else {
                // Nadie está etiquetado en el catálogo: «sin etiqueta» es todo lo que venga de
                // él, más los manuales que no se hayan puesto ubicación.
                $condiciones[] = sprintf(
                    '(%1$s OR %2$s.componenteMaestroId IS NOT NULL)',
                    $manualSinEtiqueta,
                    $joinAlias
                );
            }
        }

        // Siempre hay al menos una condición aquí: con uuids entra la rama de los manuales —una
        // por uuid, tenga o no el catálogo algo etiquetado— y «sin etiqueta» añade la suya. El
        // caso de quedarse sin ninguna lo corta el `1 = 0` de arriba, antes de llegar.
        $queryBuilder->andWhere(implode(' OR ', $condiciones));
    }

    /**
     * @param array<int, mixed> $crudos
     *
     * @return array<int, Uuid>
     */
    private function validar(array $crudos): array
    {
        $uuids = [];

        foreach ($crudos as $valor) {
            if (!is_string($valor) || self::SIN_LUGAR === $valor) {
                continue;
            }

            try {
                $uuids[] = Uuid::fromString($valor);
            } catch (\InvalidArgumentException) {
                // Un uuid mal formado se descarta; si no queda ninguno, el llamador corta.
                continue;
            }
        }

        return $uuids;
    }

    /**
     * Traduce lugares a ids de componente maestro, en el formato que guarda la cotización.
     *
     * ⚠️ El `toRfc4122()` no es cosmético: `travel_componente.id` es `BINARY(16)` y
     * `cotizacion_cotcomponente.componente_maestro_id` es un `VARCHAR(36)` en minúsculas —
     * es lo que escribe `extractIdStr()` del store del editor. Comparar el binario contra el
     * texto directamente no devuelve nada.
     *
     * @param array<int, Uuid> $uuids
     *
     * @return array<int, string>
     */
    private function componentesDeLugares(array $uuids): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c.id')
            ->from(TravelComponente::class, 'c')
            ->join('c.lugares', 'l');

        $nombres = [];

        foreach ($uuids as $i => $uuid) {
            $nombre = 'lugarFiltro_' . $i;
            $nombres[] = ':' . $nombre;
            // El tipo 'uuid' es obligatorio: sin él Doctrine no convierte a binario.
            $qb->setParameter($nombre, $uuid, 'uuid');
        }

        $qb->andWhere(sprintf('l.id IN (%s)', implode(',', $nombres)));

        return $this->aRfc4122($qb->getQuery()->getScalarResult());
    }

    /**
     * Todos los componentes que tienen al menos una etiqueta. Es el complemento que necesita
     * el chip «Sin etiqueta».
     *
     * @return array<int, string>
     */
    private function componentesEtiquetados(): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c.id')
            ->from(TravelComponente::class, 'c')
            ->join('c.lugares', 'l');

        return $this->aRfc4122($qb->getQuery()->getScalarResult());
    }

    /**
     * Normaliza el id del componente al formato que guarda la cotización: RFC 4122 en
     * minúsculas, que es lo que escribe `extractIdStr()` del store del editor.
     *
     * Hay que contemplar las tres formas porque `getScalarResult()` no garantiza cuál
     * devuelve: la columna es `BINARY(16)` y según la versión de DBAL llega como objeto
     * `Uuid`, como los 16 bytes crudos, o ya como texto. Los bytes crudos son la trampa —
     * pasarlos tal cual produce una comparación que nunca casa y un filtro que devuelve
     * cero sin error.
     *
     * @param array<int, array<string, mixed>> $filas
     *
     * @return array<int, string>
     */
    private function aRfc4122(array $filas): array
    {
        $ids = [];

        foreach ($filas as $fila) {
            $id = $fila['id'] ?? null;

            if ($id instanceof Uuid) {
                $ids[] = $id->toRfc4122();

                continue;
            }

            if (!is_string($id) || '' === $id) {
                continue;
            }

            if (16 === strlen($id)) {
                $ids[] = Uuid::fromBinary($id)->toRfc4122();

                continue;
            }

            if (Uuid::isValid($id)) {
                $ids[] = strtolower($id);
            }
        }

        return array_values(array_unique($ids));
    }
}
