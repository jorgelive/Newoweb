<?php

declare(strict_types=1);

namespace App\Cotizacion\Serializer;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Cotizacion\Entity\CotizacionCotservicio;
use App\Cotizacion\Entity\CotizacionCottarifa;
use App\Cotizacion\Entity\CotizacionSegmento;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Uid\Uuid;

final class CotizacionDenormalizer implements DenormalizerInterface, DenormalizerAwareInterface
{
    use DenormalizerAwareTrait;

    private const ALREADY_CALLED = 'COTIZACION_DENORMALIZER_ALREADY_CALLED';

    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
    }

    public function getSupportedTypes(?string $format): array
    {
        return [Cotizacion::class => false];
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return $type === Cotizacion::class && !($context[self::ALREADY_CALLED] ?? false);
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        $idsNuevos = []; // path => uuid string

        if (isset($data['cotservicios']) && is_array($data['cotservicios'])) {
            foreach ($data['cotservicios'] as $i => &$servicio) {

                // 1. UPSERT: quitar ids que no existen en BD para que se creen
                //    en vez de que ItemNormalizer intente buscarlos (Item not found).
                $this->prepararId($servicio, CotizacionCotservicio::class, "s$i", $idsNuevos);

                if (isset($servicio['cotsegmentos']) && is_array($servicio['cotsegmentos'])) {
                    foreach ($servicio['cotsegmentos'] as $j => &$segData) {
                        $this->prepararId($segData, CotizacionSegmento::class, "s$i.g$j", $idsNuevos);
                    }
                    unset($segData);
                }

                if (isset($servicio['cotcomponentes']) && is_array($servicio['cotcomponentes'])) {
                    foreach ($servicio['cotcomponentes'] as $k => &$comp) {
                        $this->prepararId($comp, CotizacionCotcomponente::class, "s$i.c$k", $idsNuevos);

                        if (isset($comp['cottarifas']) && is_array($comp['cottarifas'])) {
                            foreach ($comp['cottarifas'] as $t => &$tar) {
                                $this->prepararId($tar, CotizacionCottarifa::class, "s$i.c$k.t$t", $idsNuevos);
                            }
                            unset($tar);
                        }

                        // 2. Desconectar el IRI del cotsegmento; el paso 5 lo reconecta
                        //    usando el mapa en memoria (el segmento puede no existir aún en BD).
                        if (!empty($comp['cotsegmento'])) {
                            $comp['_cotsegmentoUuid'] = basename($comp['cotsegmento']);
                            $comp['cotsegmento'] = null;
                        }
                    }
                    unset($comp);
                }
            }
            unset($servicio);
        }

        // 3. Dejar que el denormalizador estándar procese el resto normalmente
        $context[self::ALREADY_CALLED] = true;
        /** @var Cotizacion $cotizacion */
        $cotizacion = $this->denormalizer->denormalize($data, $type, $format, $context);

        // 4. Reasignar los UUID del cliente a las entidades recién creadas
        //    (antes de reconectar segmentos, para que el mapa por id funcione).
        foreach ($cotizacion->getCotservicios() as $i => $servicio) {
            if (isset($idsNuevos["s$i"])) {
                $servicio->setId($idsNuevos["s$i"]);
            }
            foreach ($servicio->getCotsegmentos() as $j => $segmento) {
                if (isset($idsNuevos["s$i.g$j"])) {
                    $segmento->setId($idsNuevos["s$i.g$j"]);
                }
            }
            foreach ($servicio->getCotcomponentes() as $k => $componente) {
                if (isset($idsNuevos["s$i.c$k"])) {
                    $componente->setId($idsNuevos["s$i.c$k"]);
                }
                foreach ($componente->getCottarifas() as $t => $tarifa) {
                    if (isset($idsNuevos["s$i.c$k.t$t"])) {
                        $tarifa->setId($idsNuevos["s$i.c$k.t$t"]);
                    }
                }
            }
        }

        // 5. Reconectar los componentes a sus segmentos usando el mapa
        foreach ($cotizacion->getCotservicios() as $srvIdx => $servicio) {
            $segmentoMap = [];
            foreach ($servicio->getCotsegmentos() as $segmento) {
                $segmentoMap[(string) $segmento->getId()] = $segmento;
            }

            $rawComps = $data['cotservicios'][$srvIdx]['cotcomponentes'] ?? [];
            foreach ($servicio->getCotcomponentes() as $compIdx => $componente) {
                $uuid = $rawComps[$compIdx]['_cotsegmentoUuid'] ?? null;
                if ($uuid && isset($segmentoMap[$uuid])) {
                    $componente->setCotsegmento($segmentoMap[$uuid]);
                }
            }
        }

        return $cotizacion;
    }

    /**
     * Si el ítem trae un id que NO existe en BD, lo remueve del payload
     * (para que se cree como entidad nueva) y lo guarda para reasignarlo después.
     */
    private function prepararId(array &$item, string $class, string $path, array &$idsNuevos): void
    {
        if (!isset($item['id']) || !is_string($item['id'])) {
            return;
        }

        if (!Uuid::isValid($item['id'])) {
            unset($item['id'], $item['@id']);
            return;
        }

        if (null === $this->em->find($class, Uuid::fromString($item['id']))) {
            $idsNuevos[$path] = $item['id'];
            unset($item['id'], $item['@id']);
        }
    }
}