<?php

declare(strict_types=1);

namespace App\Api\Provider\Cotizacion;

use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Metadata\Operation;
use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use App\Cotizacion\Service\Publico\IdentidadDelPasajero;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Bundle\SecurityBundle\Security;

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
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly IdentidadDelPasajero $identidad,
    )
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

        /**
         * ⚠️ **El operador ve lo no publicado; el cliente no.**
         *
         * Es lo que permite previsualizar la vista cliente sin tocar el estado —la queja que
         * originó separar `publicado`: «para verla antes de mandarla tengo que ponerle enviada»—.
         *
         * No hace falta enlace ni token especial: `util` y `pax` comparten dominio de cookie
         * (`FRAMEWORK_SESION_COOKIE_DOMAIN`) y el host de la API está bajo el firewall `main`, que
         * es stateful. La sesión del operador ya llega hasta aquí.
         *
         * ⚠️ La caducidad NO se salta: una propuesta expirada tampoco se previsualiza, porque
         * entonces el operador vería algo que el cliente no puede ver y creería que sí.
         */
        $previsualiza = $this->security->isGranted('ROLE_USER');

        // ── 1. Resúmenes para la portada: un solo query escalar ──────────────
        $filas = $this->em->createQuery(<<<'DQL'
            SELECT c.propuesta, c.estado, c.numPax, c.titulo, c.resumen, c.idiomaCliente,
                   c.monedaGlobal, c.precioOculto, c.totalVenta, c.adelanto,
                   c.tipoCambio, c.fechaExpiracion, MIN(s.fechaInicioAbsoluta) AS fechaInicio,
                   o.totalVenta AS totalVentaOrigen
            FROM App\Cotizacion\Entity\Cotizacion c
            LEFT JOIN c.cotservicios s
            LEFT JOIN c.derivadaDe o
            WHERE c.file = :file
              AND (c.publicado = true OR :previsualiza = true)
              AND (c.fechaExpiracion IS NULL OR c.fechaExpiracion >= :ahora)
            GROUP BY c.id
            ORDER BY c.propuesta DESC
        DQL)
            ->setParameter('file', $file->getId(), UuidType::NAME)
            ->setParameter('previsualiza', $previsualiza)
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
                // ⚠️ **El total de una OPERATIVA sale de la confirmada**, igual que en el
                // detalle (`Cotizacion::origenFinancieroParaCliente()`). Aquí hay que repetirlo
                // porque esta consulta lee columnas, no entidades: el getter que compone no llega
                // a ejecutarse nunca. Dos sitios que dicen lo mismo por dos caminos distintos, y
                // por eso el segundo lleva este aviso.
                //
                // ⚠️ La condición mira el ESTADO, no que `derivadaDe` esté puesto: un histórico
                // también lo tiene —apunta a la viva— y debe enseñar su propio dinero.
                'totalVenta'      => $oculto ? null : (
                    $estado === CotizacionEstadoEnum::OPERATIVA->value && $f['totalVentaOrigen'] !== null
                        ? $f['totalVentaOrigen']
                        : $f['totalVenta']
                ),
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
            // ⚠️ `publicado` va EN LA CONSULTA, no sólo en la comprobación de abajo.
            //
            // Una propuesta tiene varias filas —sus históricos, la aprobada, la operativa— y todas
            // comparten número. Con el `findOneBy` a secas MySQL podía entregar la que quisiera y
            // esto respondía 404 aunque hubiera una publicada perfectamente viva: un enlace que el
            // cliente ya tenía dejando de funcionar sin que cambiara nada suyo. Ya pasó una vez.
            //
            // Y con `publicado` como eje propio esto además es DETERMINISTA: la invariante dice
            // que hay como máximo una publicada por propuesta, así que no hay nada que desempatar.
            $cotizacion = $this->em->getRepository(Cotizacion::class)->findOneBy([
                'file'    => $file,
                'propuesta' => (int) $uriVariables['propuesta'],
                ...($previsualiza ? [] : ['publicado' => true]),
            ]);

            $esVisible = $cotizacion
                && ($previsualiza || $cotizacion->isPublicado())
                && ($cotizacion->getFechaExpiracion() === null || $cotizacion->getFechaExpiracion() >= $ahora);

            if (!$esVisible) {
                return null; // versión inexistente, no pública o expirada
            }

            // ── La única puerta cerrada del expediente ───────────────────────
            //
            // La OPERATIVA de un grupo lleva datos por persona —tu vuelo, tu código, tu horario— y
            // el enlace lo tienen 133 familias. Lo comercial (confirmadas, históricas) se queda
            // abierto: es el mismo documento para todos.
            //
            // ⚠️ **403 y no 404.** Un 404 diría «no existe» y `pax` no tendría cómo saber que debe
            // enseñar el formulario; además le mentiría al usuario sobre algo que sí está ahí. El
            // código viaja en el cuerpo para que el front no tenga que adivinar por el texto.
            //
            // ⚠️ El operador se lo salta —ya se identificó de otra forma, con su sesión—, igual
            // que se salta `publicado`. La caducidad no se salta ninguno de los dos.
            if ($cotizacion->getEstado() === CotizacionEstadoEnum::OPERATIVA
                && !$previsualiza
                && !$this->identidad->estaIdentificado($file)
            ) {
                throw new AccessDeniedHttpException('IDENTIFICACION_REQUERIDA');
            }

            $file->setCotizacionParaCliente($cotizacion);
        }

        return $file;
    }
}