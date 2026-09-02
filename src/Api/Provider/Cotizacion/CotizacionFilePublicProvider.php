<?php

declare(strict_types=1);

namespace App\Api\Provider\Cotizacion;

use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Metadata\Operation;
use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * Provider público del expediente por localizador.
 *
 * - GET .../{localizador}            → PORTADA: File + resúmenes escalares de
 *                                      todas las propuestas públicas vigentes.
 * - GET .../{localizador}/{propuesta}  → DETALLE: lo anterior + la cotización
 *                                      completa de esa versión.
 *
 * Rendimiento: los resúmenes salen de UN query escalar (getArrayResult) y el
 * detalle de UN findOneBy. La colección $file->getCotizaciones() nunca se
 * hidrata, así el expediente puede tener 100+ versiones sin colapsar.
 *
 * @implements ProviderInterface<CotizacionFile>
 */
final class CotizacionFilePublicProvider implements ProviderInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?CotizacionFile
    {
        $file = $this->em->getRepository(CotizacionFile::class)
            ->findOneBy(['localizador' => $uriVariables['localizador'] ?? null]);

        if (!$file) {
            return null; // 404 uniforme
        }

        $ahora = new \DateTimeImmutable();
        $estadosPublicos = array_map(
            fn(CotizacionEstadoEnum $e) => $e->value,
            array_filter(CotizacionEstadoEnum::cases(), fn(CotizacionEstadoEnum $e) => $e->esPublico())
        );

        // ── 1. Resúmenes para la portada: un solo query escalar ──────────────
        $filas = $this->em->createQuery(<<<'DQL'
            SELECT c.propuesta, c.estado, c.numPax, c.titulo, c.resumen, c.idiomaCliente,
                   c.monedaGlobal, c.precioOculto, c.totalVenta, c.adelanto,
                   c.tipoCambio, c.fechaExpiracion, MIN(s.fechaInicioAbsoluta) AS fechaInicio
            FROM App\Cotizacion\Entity\Cotizacion c
            LEFT JOIN c.cotservicios s
            WHERE c.file = :file
              AND c.estado IN (:publicos)
              AND (c.fechaExpiracion IS NULL OR c.fechaExpiracion >= :ahora)
            GROUP BY c.id
            ORDER BY c.propuesta DESC
        DQL)
            ->setParameter('file', $file->getId(), UuidType::NAME)
            ->setParameter('publicos', $estadosPublicos)
            ->setParameter('ahora', $ahora)
            ->getArrayResult();

        // Sin ninguna propuesta pública vigente, el expediente no es visible
        if ($filas === []) {
            return null;
        }

        $file->setPropuestasParaCliente(array_values(array_map(static function (array $f): array {
            $oculto = (bool) $f['precioOculto'];
            $estado = $f['estado'] instanceof CotizacionEstadoEnum ? $f['estado']->value : $f['estado'];

            return [
                'propuesta'         => $f['propuesta'],
                'estado'          => $estado,
                'numPax'          => $f['numPax'],
                'titulo'          => $f['titulo'] ?? [],           // I18nContent[] (texto)
                'resumen'         => $f['resumen'] ?? [],          // I18nContent[] (HTML)
                'idiomaCliente'   => $f['idiomaCliente'],
                'monedaGlobal'    => $f['monedaGlobal'],
                'precioOculto'    => $oculto,
                'tipoCambio'      => (float) $f['tipoCambio'],
                // No filtrar montos cuando el precio está oculto
                'totalVenta'      => $oculto ? null : $f['totalVenta'],
                'adelanto'        => $oculto ? null : $f['adelanto'],
                'fechaExpiracion' => $f['fechaExpiracion'] instanceof \DateTimeInterface
                    ? $f['fechaExpiracion']->format(DATE_ATOM) : null,
                'fechaInicio'     => $f['fechaInicio'] instanceof \DateTimeInterface
                    ? $f['fechaInicio']->format('Y-m-d')
                    : ($f['fechaInicio'] ? substr((string) $f['fechaInicio'], 0, 10) : null),
            ];
        }, $filas)));

        // ── 2. Detalle: cargar SOLO la versión solicitada ─────────────────────
        if (isset($uriVariables['propuesta'])) {
            // ⚠️ El ESTADO va en la consulta, no sólo en la comprobación de abajo.
            //
            // Desde que existen los históricos, `(file, version)` puede devolver más de una fila:
            // un histórico conserva a propósito el número de la cotización de la que salió. Con
            // el `findOneBy` a secas, MySQL podía entregar la foto congelada y esto respondía 404
            // aunque hubiera una versión pública perfectamente viva — un enlace que el cliente ya
            // tenía dejando de funcionar sin que cambiara nada suyo.
            $cotizacion = $this->em->getRepository(Cotizacion::class)->findOneBy([
                'file'    => $file,
                'propuesta' => (int) $uriVariables['propuesta'],
                'estado'  => array_filter(
                    CotizacionEstadoEnum::cases(),
                    static fn (CotizacionEstadoEnum $e): bool => $e->esPublico(),
                ),
            ]);

            $esVisible = $cotizacion
                && $cotizacion->getEstado()->esPublico()
                && ($cotizacion->getFechaExpiracion() === null || $cotizacion->getFechaExpiracion() >= $ahora);

            if (!$esVisible) {
                return null; // versión inexistente, no pública o expirada
            }

            $file->setCotizacionParaCliente($cotizacion);
        }

        return $file;
    }
}