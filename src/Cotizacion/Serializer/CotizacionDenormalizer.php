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
        // En POST api_allow_update es false: ItemNormalizer lanza
        // "Update is not allowed" ante CUALQUIER id presente en los datos.
        $permiteUpdate = true === ($context['api_allow_update'] ?? false);

        $idsNuevos = []; // path => uuid string

        // Id raíz: en POST hay que quitarlo y reasignarlo después
        $rootId = null;
        if (!$permiteUpdate && isset($data['id'])) {
            $rootId = $data['id'];
            unset($data['id'], $data['@id']);
        }

        if (isset($data['cotservicios']) && is_array($data['cotservicios'])) {
            foreach ($data['cotservicios'] as $i => &$servicio) {

                $this->prepararId($servicio, CotizacionCotservicio::class, "s$i", $idsNuevos, $permiteUpdate);

                if (isset($servicio['cotsegmentos']) && is_array($servicio['cotsegmentos'])) {
                    foreach ($servicio['cotsegmentos'] as $j => &$segData) {
                        $this->prepararId($segData, CotizacionSegmento::class, "s$i.g$j", $idsNuevos, $permiteUpdate);
                    }
                    unset($segData);
                }

                if (isset($servicio['cotcomponentes']) && is_array($servicio['cotcomponentes'])) {
                    foreach ($servicio['cotcomponentes'] as $k => &$comp) {
                        $this->prepararId($comp, CotizacionCotcomponente::class, "s$i.c$k", $idsNuevos, $permiteUpdate);

                        if (isset($comp['cottarifas']) && is_array($comp['cottarifas'])) {
                            foreach ($comp['cottarifas'] as $t => &$tar) {
                                $this->prepararId($tar, CotizacionCottarifa::class, "s$i.c$k.t$t", $idsNuevos, $permiteUpdate);
                            }
                            unset($tar);
                        }

                        // Desconectar el IRI del cotsegmento; se reconecta al final
                        // usando el mapa en memoria (el segmento puede no existir aún en BD).
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

        // Denormalización estándar
        $context[self::ALREADY_CALLED] = true;
        /** @var Cotizacion $cotizacion */
        $cotizacion = $this->denormalizer->denormalize($data, $type, $format, $context);

        // Reasignar los UUID del cliente a las entidades recién creadas
        if ($rootId !== null) {
            $cotizacion->setId($rootId);
        }

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

        // Reconectar los componentes a sus segmentos usando el mapa
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
     * POST: quita SIEMPRE el id (crear, ItemNormalizer no permite ids).
     * PUT/PATCH: quita el id solo si no existe en BD (upsert).
     * En ambos casos guarda el UUID para reasignarlo a la entidad nueva.
     */
    private function prepararId(array &$item, string $class, string $path, array &$idsNuevos, bool $permiteUpdate): void
    {
        if (!isset($item['id']) || !is_string($item['id'])) {
            return;
        }

        if (!Uuid::isValid($item['id'])) {
            unset($item['id'], $item['@id']);
            return;
        }

        if (!$permiteUpdate || null === $this->em->find($class, Uuid::fromString($item['id']))) {
            $idsNuevos[$path] = $item['id'];
            unset($item['id'], $item['@id']);
        }
    }
}