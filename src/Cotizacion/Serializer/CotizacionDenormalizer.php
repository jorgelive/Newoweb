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
        $permiteUpdate = true === ($context['api_allow_update'] ?? false);

        $rootId = null;
        if (!$permiteUpdate && isset($data['id'])) {
            $rootId = $data['id'];
            unset($data['id'], $data['@id']);
        }

        // Mapa estricto para reconstruir la relación Componente -> Segmento aislando los índices.
        $componentToSegmentMap = [];

        if (isset($data['cotservicios']) && is_array($data['cotservicios'])) {
            foreach ($data['cotservicios'] as &$servicio) {
                $this->embedIdInJson($servicio, CotizacionCotservicio::class, 'nombreSnapshot', $permiteUpdate);

                if (isset($servicio['cotsegmentos']) && is_array($servicio['cotsegmentos'])) {
                    foreach ($servicio['cotsegmentos'] as &$segData) {
                        $this->embedIdInJson($segData, CotizacionSegmento::class, 'nombreSnapshot', $permiteUpdate);
                    }
                }

                if (isset($servicio['cotcomponentes']) && is_array($servicio['cotcomponentes'])) {
                    foreach ($servicio['cotcomponentes'] as &$comp) {
                        $frontendCompId = $comp['id'] ?? null;

                        // Guardamos la relación en un mapa local antes de que el normalizador elimine la clave.
                        if (!empty($comp['cotsegmento']) && $frontendCompId && Uuid::isValid($frontendCompId)) {
                            $componentToSegmentMap[$frontendCompId] = basename($comp['cotsegmento']);
                        }

                        // Desenlazamos el IRI para evitar errores 404 de API Platform con segmentos no creados.
                        unset($comp['cotsegmento']);

                        $this->embedIdInJson($comp, CotizacionCotcomponente::class, 'nombreSnapshot', $permiteUpdate);

                        if (isset($comp['cottarifas']) && is_array($comp['cottarifas'])) {
                            foreach ($comp['cottarifas'] as &$tar) {
                                $this->embedIdInJson($tar, CotizacionCottarifa::class, 'tituloSnapshot', $permiteUpdate);
                            }
                        }
                    }
                }
            }
        }

        $context[self::ALREADY_CALLED] = true;
        /** @var Cotizacion $cotizacion */
        $cotizacion = $this->denormalizer->denormalize($data, $type, $format, $context);

        // Reasignar los UUID del cliente a las entidades recién creadas
        if ($rootId !== null) {
            $cotizacion->setId($rootId);
        }

        $segmentoObjMap = [];

        // 1. Extraemos las marcas inyectadas, restauramos los UUIDs exactos 1 a 1 en las entidades
        // y poblamos el mapa de objetos Segmento.
        foreach ($cotizacion->getCotservicios() as $servicio) {
            $this->extractAndSetId($servicio, 'NombreSnapshot');

            foreach ($servicio->getCotsegmentos() as $segmento) {
                $this->extractAndSetId($segmento, 'NombreSnapshot');
                $segmentoObjMap[(string) $segmento->getId()] = $segmento;
            }
        }

        // 2. Reconectamos los componentes con los segmentos usando los UUIDs restaurados.
        foreach ($cotizacion->getCotservicios() as $servicio) {
            foreach ($servicio->getCotcomponentes() as $componente) {
                $this->extractAndSetId($componente, 'NombreSnapshot');

                // Reconstruimos la relación a nivel de objetos Doctrine (bidireccional).
                $compId = (string) $componente->getId();
                if (isset($componentToSegmentMap[$compId])) {
                    $segId = $componentToSegmentMap[$compId];
                    if (isset($segmentoObjMap[$segId])) {
                        $segmento = $segmentoObjMap[$segId];

                        $componente->setCotsegmento($segmento);
                        if (!$segmento->getCotcomponentes()->contains($componente)) {
                            $segmento->addCotcomponente($componente);
                        }
                    }
                }

                foreach ($componente->getCottarifas() as $tarifa) {
                    $this->extractAndSetId($tarifa, 'TituloSnapshot');
                }
            }
        }

        return $cotizacion;
    }

    /**
     * POST: quita SIEMPRE el id (crear, ItemNormalizer no permite ids).
     * PUT/PATCH: quita el id solo si no existe en BD (upsert).
     * Inyecta el UUID temporalmente en el campo JSON.
     */
    private function embedIdInJson(array &$item, string $class, string $jsonField, bool $permiteUpdate): void
    {
        if (!isset($item['id']) || !is_string($item['id']) || !Uuid::isValid($item['id'])) {
            unset($item['id'], $item['@id']);
            return;
        }

        $id = $item['id'];

        if (!$permiteUpdate || null === $this->em->find($class, Uuid::fromString($id))) {
            unset($item['id'], $item['@id']);

            if (!isset($item[$jsonField]) || !is_array($item[$jsonField])) {
                $item[$jsonField] = [];
            }
            $item[$jsonField]['_frontend_uuid'] = $id;
        }
    }

    /**
     * Extrae el UUID inyectado en el campo JSON y lo restaura en la entidad.
     */
    private function extractAndSetId(object $entity, string $methodSuffix): void
    {
        $getter = 'get' . $methodSuffix;
        $setter = 'set' . $methodSuffix;

        if (method_exists($entity, $getter) && method_exists($entity, $setter)) {
            $data = $entity->$getter();

            // Si el objeto fue instanciado desde un payload nuevo, traerá la marca
            if (is_array($data) && isset($data['_frontend_uuid'])) {
                $frontendId = $data['_frontend_uuid'];
                unset($data['_frontend_uuid']); // Limpiamos la marca para no guardar basura en BD

                $entity->$setter($data);

                if (method_exists($entity, 'setId')) {
                    $entity->setId($frontendId);
                }
            }
        }
    }
}