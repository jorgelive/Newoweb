<?php

declare(strict_types=1);

namespace App\Cotizacion\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Cotizacion\Entity\CotizacionFileGrupo;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Los subgrupos del expediente, cada uno con sus vuelos en corto.
 *
 * ── Por qué hace falta ──────────────────────────────────────────────────────
 * Al repartir un componente hay que elegir entre subgrupos que **se llaman igual**: «Vuelo ·
 * Nacional · JetSMART» son ocho reservas distintas. El localizador los distingue, pero no dice si
 * ese PNR corresponde al vuelo de **este** día — una reserva cubre ida y vuelta, así que casi
 * todos llevan vuelos de otras fechas.
 *
 * ── 🔥 UNA consulta para todos ──────────────────────────────────────────────
 * `$vuelos` es una `ManyToMany` **inversa**: pedirla subgrupo a subgrupo son **109 consultas**
 * para pintar una lista de opciones. Aquí se traen los enlaces de golpe y se reparten en memoria.
 *
 * Es el mismo motivo por el que `CotizacionFileItemProvider` existe: un dato que la entidad no
 * puede calcular sin caer en N+1 se rellena desde fuera, en una sola pasada.
 *
 * @implements ProviderInterface<CotizacionFileGrupo>
 */
final readonly class CotizacionFileGrupoCollectionProvider implements ProviderInterface
{
    public function __construct(
        /** @var ProviderInterface<CotizacionFileGrupo> */
        #[Autowire(service: 'api_platform.doctrine.orm.state.collection_provider')]
        private ProviderInterface $decorado,
        private Connection $conn,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return iterable<CotizacionFileGrupo>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable
    {
        /** @var iterable<CotizacionFileGrupo> $grupos */
        $grupos = $this->decorado->provide($operation, $uriVariables, $context);
        $grupos = is_array($grupos) ? $grupos : iterator_to_array($grupos);

        $ids = [];

        foreach ($grupos as $grupo) {
            $id = $grupo->getId()?->toRfc4122();

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            return $grupos;
        }

        // 🔥 **Se compara HEX contra HEX, no un id contra una columna binaria.**
        //
        // `grupo_id` es `BINARY(16)`. La versión anterior pasaba el UUID sin guiones —una cadena
        // hexadecimal— contra esa columna: **no casaba ninguna fila y no daba ningún error**, así
        // que la lista salía vacía y parecía que no había vuelos. Es la misma trampa que ya mordió
        // tres veces en este módulo, esta vez por la puerta del SQL crudo.
        //
        // Envolver la columna en `HEX()` renuncia al índice, y aquí da igual: son 56 filas en
        // total y la alternativa —convertir a binario en PHP— vuelve a poner el error a un
        // descuido de distancia.
        $filas = $this->conn->fetchAllAssociative(
            'SELECT LOWER(HEX(gv.grupo_id)) AS grupo, v.numero, v.origen, v.destino, v.salida, v.llegada
               FROM cotizacion_grupo_vuelo gv
               JOIN cotizacion_vuelo v ON v.id = gv.vuelo_id
              WHERE LOWER(HEX(gv.grupo_id)) IN (?)
              ORDER BY v.salida',
            [array_map(static fn (string $id): string => str_replace('-', '', $id), $ids)],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        );

        /** @var array<string, list<array{numero: string|null, salida: string|null, llegada: string|null, origen: string|null, destino: string|null}>> $porGrupo */
        $porGrupo = [];

        foreach ($filas as $fila) {
            $porGrupo[(string) $fila['grupo']][] = [
                'numero' => $fila['numero'] !== null ? (string) $fila['numero'] : null,
                'salida' => $fila['salida'] !== null ? (string) $fila['salida'] : null,
                'llegada' => $fila['llegada'] !== null ? (string) $fila['llegada'] : null,
                'origen' => $fila['origen'] !== null ? (string) $fila['origen'] : null,
                'destino' => $fila['destino'] !== null ? (string) $fila['destino'] : null,
            ];
        }

        foreach ($grupos as $grupo) {
            $clave = str_replace('-', '', strtolower((string) $grupo->getId()?->toRfc4122()));
            $grupo->setVuelosResumen($porGrupo[$clave] ?? []);
        }

        return $grupos;
    }
}
