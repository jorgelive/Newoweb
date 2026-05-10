<?php

declare(strict_types=1);

namespace App\Cotizacion\Serializer;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionSegmento;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Uid\Uuid;

final class CotizacionDenormalizer implements DenormalizerInterface, DenormalizerAwareInterface
{
    use DenormalizerAwareTrait;

    private const ALREADY_CALLED = 'COTIZACION_DENORMALIZER_ALREADY_CALLED';

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
        // 1. Construir mapa UUID → objeto CotizacionSegmento desde los datos crudos
        //    ANTES de que el denormalizador estándar toque las relaciones
        $segmentoMap = [];

        if (isset($data['cotservicios']) && is_array($data['cotservicios'])) {
            foreach ($data['cotservicios'] as &$servicio) {
                if (!isset($servicio['cotsegmentos']) || !is_array($servicio['cotsegmentos'])) {
                    continue;
                }
                foreach ($servicio['cotsegmentos'] as &$segData) {
                    // Si el segmento viene como objeto embebido (no como IRI string)
                    if (is_array($segData) && isset($segData['id'])) {
                        $uuid = $segData['id'];
                        // Creamos el objeto manualmente para tenerlo en el mapa
                        $segmento = new CotizacionSegmento();
                        $segmento->setId(Uuid::fromString($uuid));
                        $segmentoMap[$uuid] = $segmento;
                    }
                }
                unset($segData);

                // 2. Reemplazar el IRI del cotsegmento por null en los componentes
                //    El processor lo conectará después usando el mapa en memoria
                if (isset($servicio['cotcomponentes']) && is_array($servicio['cotcomponentes'])) {
                    foreach ($servicio['cotcomponentes'] as &$comp) {
                        if (!empty($comp['cotsegmento'])) {
                            // Guardamos el UUID en un campo temporal y quitamos el IRI
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

        // 4. Reconectar los componentes a sus segmentos usando el mapa
        foreach ($cotizacion->getCotservicios() as $srvIdx => $servicio) {
            // Reconstruir el mapa con los objetos reales ya denormalizados
            $segmentoMapReal = [];
            foreach ($servicio->getCotsegmentos() as $segmento) {
                $segmentoMapReal[(string) $segmento->getId()] = $segmento;
            }

            $rawComps = $data['cotservicios'][$srvIdx]['cotcomponentes'] ?? [];
            foreach ($servicio->getCotcomponentes() as $compIdx => $componente) {
                $uuid = $rawComps[$compIdx]['_cotsegmentoUuid'] ?? null;
                if ($uuid && isset($segmentoMapReal[$uuid])) {
                    $componente->setCotsegmento($segmentoMapReal[$uuid]);
                }
            }
        }

        return $cotizacion;
    }
}