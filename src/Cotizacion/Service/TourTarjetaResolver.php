<?php

declare(strict_types=1);

namespace App\Cotizacion\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\AbstractUid;
use Symfony\Component\Uid\Uuid;

/**
 * Datos de "tarjeta" de un tour de catálogo: portada derivada y duración.
 *
 * Ninguno de los dos es una columna: la portada puede venir de un override
 * editorial o derivarse del itinerario, y los días son el span de las fechas
 * nominales. Vive aquí — y no en la entidad — porque se resuelve con queries
 * escalares en lote: recorrer `$cotizacion->getCotservicios()` para cada tour
 * hidrataría el árbol entero de cada uno (N+1 caro y evitable).
 *
 * Fuente única de la regla para los dos consumidores:
 *   - vista pública   → CotizacionCatalogoPublicProvider
 *   - panel interno   → CotizacionCatalogoAdminProvider (CatalogoDashboard.vue)
 */
final class TourTarjetaResolver
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Portada automática por tour: primera imagen marcada isPortada recorriendo
     * los segmentos en orden de itinerario; si ninguna lo está, la primera
     * imagen disponible. No aplica el override editorial (`imagenPortada`):
     * eso lo decide quien llama, que es quien sabe si debe respetarlo.
     *
     * @param array<int, mixed> $cotIds
     * @return array<string, array> Mapa cotizacionId => imagen (snapshot)
     */
    public function portadasDerivadas(array $cotIds): array
    {
        if ($cotIds === []) {
            return [];
        }

        $filas = $this->em->createQuery(<<<'DQL'
            SELECT IDENTITY(s.cotizacion) AS cotId, seg.imagenesSnapshot
            FROM App\Cotizacion\Entity\CotizacionSegmento seg
            JOIN seg.cotservicio s
            WHERE s.cotizacion IN (:ids)
            ORDER BY s.fechaInicioAbsoluta ASC, seg.orden ASC
        DQL)
            ->setParameter('ids', self::binarios($cotIds), ArrayParameterType::BINARY)
            ->getArrayResult();

        $portadas = [];
        $fallbacks = [];
        foreach ($filas as $fila) {
            $cotId = self::clave($fila['cotId']);
            foreach ((array) ($fila['imagenesSnapshot'] ?? []) as $img) {
                if (!is_array($img)) {
                    continue;
                }
                $fallbacks[$cotId] ??= $img;
                if (!isset($portadas[$cotId]) && ($img['isPortada'] ?? false)) {
                    $portadas[$cotId] = $img;
                }
            }
        }

        return $portadas + $fallbacks;
    }

    /**
     * Duración en días de cada tour, en lote.
     *
     * @param array<int, mixed> $cotIds
     * @return array<string, int> Mapa cotizacionId => días
     */
    public function diasPorTour(array $cotIds): array
    {
        if ($cotIds === []) {
            return [];
        }

        $filas = $this->em->createQuery(<<<'DQL'
            SELECT IDENTITY(s.cotizacion) AS cotId,
                   MIN(s.fechaInicioAbsoluta) AS fechaMin,
                   MAX(s.fechaInicioAbsoluta) AS fechaMax
            FROM App\Cotizacion\Entity\CotizacionCotservicio s
            WHERE s.cotizacion IN (:ids)
            GROUP BY s.cotizacion
        DQL)
            ->setParameter('ids', self::binarios($cotIds), ArrayParameterType::BINARY)
            ->getArrayResult();

        $dias = [];
        foreach ($filas as $fila) {
            $n = self::numDias($fila['fechaMin'], $fila['fechaMax']);
            if ($n !== null) {
                $dias[self::clave($fila['cotId'])] = $n;
            }
        }

        return $dias;
    }

    /**
     * Clave canónica de un id de cotización: UUID con guiones, en minúsculas.
     *
     * **Gotcha**: `IDENTITY(s.cotizacion)` en DQL devuelve el UUID **binario en
     * crudo** (16 bytes), no el objeto `Uuid` que sí hidrata `c.id`. Casar los
     * dos con `(string)` a secas nunca coincide — el mapa queda vacío en
     * silencio y la portada derivada "desaparece" sin ningún error. Todo id que
     * entre o salga de estos mapas pasa por aquí.
     */
    /**
     * Ids a binario para el `IN (:ids)`. Otra cara del mismo gotcha: pasar
     * objetos `Uuid` sin tipo de parámetro los serializa mal y el IN no casa
     * con nada — la query no falla, simplemente devuelve cero filas.
     *
     * @param array<int, mixed> $ids
     * @return array<int, string>
     */
    private static function binarios(array $ids): array
    {
        return array_map(
            static fn(mixed $id): string => $id instanceof AbstractUid
                ? $id->toBinary()
                : (strlen((string) $id) === 16 ? (string) $id : Uuid::fromString((string) $id)->toBinary()),
            $ids
        );
    }

    public static function clave(mixed $id): string
    {
        if ($id instanceof AbstractUid) {
            return (string) $id;
        }
        if (is_string($id) && strlen($id) === 16) {
            return (string) Uuid::fromBinary($id);
        }
        return strtolower((string) $id);
    }

    /**
     * Días del tour a partir del span de fechas nominales del itinerario.
     * Las fechas nominales son consistentes entre sí, por lo que el span
     * (max - min + 1) equivale a la duración real del programa.
     */
    public static function numDias(mixed $min, mixed $max): ?int
    {
        $aFecha = static function (mixed $v): ?\DateTimeImmutable {
            if ($v instanceof \DateTimeInterface) {
                return \DateTimeImmutable::createFromInterface($v);
            }
            if (is_string($v) && $v !== '') {
                return new \DateTimeImmutable(substr($v, 0, 10));
            }
            return null;
        };

        $fMin = $aFecha($min);
        $fMax = $aFecha($max);
        if (!$fMin || !$fMax) {
            return null;
        }

        return (int) $fMin->diff($fMax)->format('%a') + 1;
    }
}
