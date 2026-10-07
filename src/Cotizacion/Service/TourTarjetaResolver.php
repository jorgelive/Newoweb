<?php

declare(strict_types=1);

namespace App\Cotizacion\Service;

use App\Cotizacion\Entity\CotizacionCatalogo;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
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
 * Fuente única de la regla para sus consumidores:
 *   - vista pública   → CotizacionCatalogoPublicProvider (pax, por localizador)
 *   - web pública     → App\Front\Tours\Service\CatalogoWebLector (openperu.pe)
 *   - panel interno   → CotizacionCatalogoAdminProvider (CatalogoDashboard.vue)
 *
 * @phpstan-type TarjetaDeTour array{
 *     id: string, propuesta: int, publicado: bool, estado: string, numPax: int,
 *     titulo: array<mixed>, resumen: array<mixed>, idiomaCliente: string, monedaGlobal: string,
 *     precioOculto: bool, orden: int, preciosDesde: array<mixed>, imagenPortada: array<mixed>|null,
 *     numDias: int|null
 * }
 */
final class TourTarjetaResolver
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Las tarjetas de los tours de un catálogo, en su orden de exhibición, con las dos reglas de
     * la tarjeta ya aplicadas:
     *
     *   - **portada**: el override editorial (`imagenPortada`) manda; si no, la derivada.
     *   - **precio oculto**: sin `preciosDesde`. El financiero real nunca sale de aquí.
     *
     * Un solo query escalar más dos en lote (portadas): la colección del catálogo no se hidrata.
     *
     * ⚠️ `$incluirBorradores` es para el OPERADOR en `pax` (previsualizar antes de publicar). La
     * web pública lo pasa siempre a `false`: es la cara pública y una caché no sabe quién miraba.
     *
     * @return list<TarjetaDeTour>
     */
    public function tarjetas(CotizacionCatalogo $catalogo, bool $incluirBorradores): array
    {
        // Mismas formas que en `CotizacionFilePublicProvider`: columnas por su tipo, `MIN()`/`MAX()`
        // en texto, y un JSON `NOT NULL` que puede traer el literal `null`.
        /**
         * @var list<array{id: Uuid, imagenPortada: array<mixed>|null, propuesta: int,
         *     estado: CotizacionEstadoEnum|string, publicado: bool, numPax: int, titulo: array<mixed>|null,
         *     resumen: array<mixed>|null, idiomaCliente: string, monedaGlobal: string, precioOculto: bool,
         *     preciosDesde: array<mixed>|null, orden: int,
         *     fechaMin: ?string, fechaMax: ?string}> $filas
         */
        $filas = $this->em->createQuery(<<<'DQL'
            SELECT c.id, c.imagenPortada, c.propuesta, c.estado, c.publicado, c.numPax, c.titulo, c.resumen, c.idiomaCliente,
                   c.monedaGlobal, c.precioOculto,
                   c.preciosDesde, c.orden,
                   MIN(s.fechaInicioAbsoluta) AS fechaMin, MAX(s.fechaInicioAbsoluta) AS fechaMax
            FROM App\Cotizacion\Entity\Cotizacion c
            LEFT JOIN c.cotservicios s
            WHERE c.catalogo = :catalogo
              AND (c.publicado = true OR :borradores = true)
            GROUP BY c.id
            ORDER BY c.orden ASC, c.propuesta ASC
        DQL)
            ->setParameter('catalogo', $catalogo->getId(), UuidType::NAME)
            ->setParameter('borradores', $incluirBorradores)
            ->getArrayResult();

        if ($filas === []) {
            return [];
        }

        $portadas = $this->portadasDerivadas(array_column($filas, 'id'));

        return array_map(static function (array $f) use ($portadas): array {
            $oculto = (bool) $f['precioOculto'];

            return [
                'id'            => self::clave($f['id']),
                'propuesta'     => $f['propuesta'],
                'publicado'     => (bool) $f['publicado'],
                'estado'        => $f['estado'] instanceof CotizacionEstadoEnum ? $f['estado']->value : $f['estado'],
                'numPax'        => $f['numPax'],
                'titulo'        => $f['titulo'] ?? [],
                'resumen'       => $f['resumen'] ?? [],
                'idiomaCliente' => $f['idiomaCliente'],
                'monedaGlobal'  => $f['monedaGlobal'],
                'precioOculto'  => $oculto,
                'orden'         => $f['orden'],
                'preciosDesde'  => $oculto ? [] : ($f['preciosDesde'] ?? []),
                'imagenPortada' => $f['imagenPortada'] ?? $portadas[self::clave($f['id'])] ?? null,
                'numDias'       => self::numDias($f['fechaMin'], $f['fechaMax']),
            ];
        }, $filas);
    }

    /**
     * Todas las fotos de un tour, en orden de itinerario y sin repetir: la galería de su ficha
     * pública. Sólo las del propio segmento (`imagenesSnapshot`), las mismas que ve el cliente.
     *
     * @return list<string> URLs tal como están guardadas (relativas: `/carga/...`)
     */
    public function imagenesDeTour(AbstractUid|string $cotId): array
    {
        /** @var list<array{imagenesSnapshot: array<mixed>|null}> $filas */
        $filas = $this->em->createQuery(<<<'DQL'
            SELECT seg.imagenesSnapshot
            FROM App\Cotizacion\Entity\CotizacionSegmento seg
            JOIN seg.cotservicio s
            WHERE s.cotizacion = :id
            ORDER BY s.fechaInicioAbsoluta ASC, seg.orden ASC
        DQL)
            ->setParameter('id', self::binarios([$cotId])[0], 'binary')
            ->getArrayResult();

        $urls = [];
        foreach ($filas as $fila) {
            $imagenes = $fila['imagenesSnapshot'] ?? [];
            // El snapshot guarda el orden del editor en `orden`, no en la posición del array.
            usort($imagenes, static fn (mixed $a, mixed $b): int =>
                (is_array($a) && is_int($a['orden'] ?? null) ? $a['orden'] : 0)
                <=> (is_array($b) && is_int($b['orden'] ?? null) ? $b['orden'] : 0));
            foreach ($imagenes as $img) {
                $url = is_array($img) && is_string($img['imageUrl'] ?? null) ? $img['imageUrl'] : '';
                if ($url !== '' && !in_array($url, $urls, true)) {
                    $urls[] = $url;
                }
            }
        }

        return $urls;
    }

    /**
     * Portada automática por tour: primera imagen marcada isPortada recorriendo
     * los segmentos en orden de itinerario; si ninguna lo está, la primera
     * imagen disponible. No aplica el override editorial (`imagenPortada`):
     * eso lo decide quien llama, que es quien sabe si debe respetarlo.
     *
     * @param list<AbstractUid|string> $cotIds
     *
     * @return array<string, array<mixed>> Mapa cotizacionId => imagen (snapshot)
     */
    public function portadasDerivadas(array $cotIds): array
    {
        if ($cotIds === []) {
            return [];
        }

        // `cotId` sale en BINARIO (ver `clave()`); la columna JSON, tal cual la guardó quien fuera.
        /** @var list<array{cotId: string, imagenesSnapshot: array<mixed>|null}> $filas */
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
            foreach ($fila['imagenesSnapshot'] ?? [] as $img) {
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
     * @param list<AbstractUid|string> $cotIds
     * @return array<string, int> Mapa cotizacionId => días
     */
    public function diasPorTour(array $cotIds): array
    {
        if ($cotIds === []) {
            return [];
        }

        // `MIN`/`MAX` de un DQL escalar no pasan por el tipo de la columna: llegan como texto.
        /** @var list<array{cotId: string, fechaMin: ?string, fechaMax: ?string}> $filas */
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
     * Ids a binario para el `IN (:ids)`. Otra cara del mismo gotcha: pasar
     * objetos `Uuid` sin tipo de parámetro los serializa mal y el IN no casa
     * con nada — la query no falla, simplemente devuelve cero filas.
     *
     * @param list<AbstractUid|string> $ids
     * @return list<string>
     */
    private static function binarios(array $ids): array
    {
        return array_map(
            static fn(AbstractUid|string $id): string => $id instanceof AbstractUid
                ? $id->toBinary()
                : (strlen($id) === 16 ? $id : Uuid::fromString($id)->toBinary()),
            $ids
        );
    }

    /**
     * Clave canónica de un id de cotización: UUID con guiones, en minúsculas.
     *
     * **Gotcha**: `IDENTITY(s.cotizacion)` en DQL devuelve el UUID **binario en
     * crudo** (16 bytes), no el objeto `Uuid` que sí hidrata `c.id`. Casar los
     * dos con `(string)` a secas nunca coincide — el mapa queda vacío en
     * silencio y la portada derivada "desaparece" sin ningún error. Todo id que
     * entre o salga de estos mapas pasa por aquí.
     *
     * `mixed` a propósito: recibe el id de una entidad (`?Uuid`), el de una fila de DQL (binario)
     * y el de una fila escalar. Un id que falta —una entidad sin guardar— es la clave vacía, que no
     * casa con ninguna, igual que antes.
     */
    public static function clave(mixed $id): string
    {
        if ($id instanceof AbstractUid) {
            return (string) $id;
        }
        if (is_string($id) && strlen($id) === 16) {
            return (string) Uuid::fromBinary($id);
        }
        return is_string($id) ? strtolower($id) : '';
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
