<?php

declare(strict_types=1);

namespace App\Cotizacion\Serializer;

use App\Cotizacion\Entity\CotizacionCottarifa;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Normalizer\CacheableSupportsMethodInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Nivel 2 del anonimato del visor público: la entidad CotizacionCottarifa,
 * 4 niveles anidada bajo Cotizacion (Cotizacion -> cotservicios ->
 * cotcomponentes -> cottarifas).
 *
 * Oculta los campos snapshot del proveedor cuando corresponde. La regla es
 * un OR entre dos fuentes, nunca un override:
 *  - El flag GLOBAL de Cotizacion->proveedorOculto, heredado vía
 *    $context['pax_proveedor_oculto_global'] (inyectado por
 *    CotizacionPublicNormalizer al normalizar la Cotizacion raíz).
 *  - El flag INDIVIDUAL de esta tarifa puntual (CotizacionCottarifa->proveedorOculto),
 *    para anonimato granular tipo "White Label" en un solo ítem aunque el
 *    resto de la cotización sí muestre proveedores.
 * Una tarifa nunca puede forzar mostrar el proveedor si el flag global ya lo
 * ocultó; solo puede agregar ocultamiento sobre él.
 *
 * Es una decoración INDEPENDIENTE del mismo servicio que decora
 * CotizacionPublicNormalizer. Symfony encadena ambos decoradores según su
 * `priority`; el $context fluye igual a través de toda la cadena y de toda
 * la recursión de serialización, así que el orden entre ambos decoradores
 * no afecta la propagación del flag — solo importa que
 * CotizacionPublicNormalizer inyecte el flag ANTES de que sus hijos se
 * normalicen, cosa que ya garantiza por sí mismo.
 *
 * CRÍTICO: supportsNormalization() delega SIEMPRE al normalizer decorado,
 * por la misma razón documentada en CotizacionPublicNormalizer — este
 * servicio es el normalizer general de item de toda la API.
 *
 * Registrado por atributo: no requiere ninguna entrada en services.yaml.
 */
#[AsDecorator(decorates: 'api_platform.jsonld.normalizer.item', priority: 10)]
final class CotizacionCottarifaProveedorPublicNormalizer implements NormalizerInterface, CacheableSupportsMethodInterface, SerializerAwareInterface
{
    private const GRUPO_PUBLICO = 'pax_cotizacion:read';

    /**
     * Campos que identifican o dan pistas del proveedor logístico.
     * nombreParaProveedorSnapshot queda deliberadamente fuera: es información
     * PARA el proveedor (uso interno del requerimiento), no SOBRE el proveedor,
     * y de todos modos no lleva el grupo pax_cotizacion:read.
     */
    private const PROVEEDOR_SNAPSHOT_FIELDS = [
        'proveedorNombreSnapshot',
        'proveedorTituloSnapshot',
        'proveedorUrlSnapshot',
        'proveedorImagenesSnapshot',
        'proveedorServicioNombreSnapshot',
        'proveedorServicioTituloSnapshot',
        'proveedorServicioUrlSnapshot',
        'proveedorServicioImagenesSnapshot',
    ];

    public function __construct(
        #[Autowire(service: 'App\Cotizacion\Serializer\CotizacionCottarifaProveedorPublicNormalizer.inner')]
        private readonly NormalizerInterface $decorated,
    ) {
    }

    public function normalize($object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $data = $this->decorated->normalize($object, $format, $context);

        $isPublicView = \in_array(self::GRUPO_PUBLICO, $context['groups'] ?? [], true);

        if ($isPublicView && $object instanceof CotizacionCottarifa && \is_array($data)) {
            $ocultarProveedor = ($context['pax_proveedor_oculto_global'] ?? false)
                || $object->isProveedorOculto();

            if ($ocultarProveedor) {
                foreach (self::PROVEEDOR_SNAPSHOT_FIELDS as $field) {
                    unset($data[$field]);
                }
            }
        }

        return $data;
    }

    public function supportsNormalization($data, ?string $format = null, array $context = []): bool
    {
        // Delega siempre: ver nota crítica en CotizacionPublicNormalizer.
        return $this->decorated->supportsNormalization($data, $format, $context);
    }

    public function getSupportedTypes(?string $format): array
    {
        return ['*' => false];
    }

    public function hasCacheableSupportsMethod(): bool
    {
        return $this->decorated instanceof CacheableSupportsMethodInterface
            && $this->decorated->hasCacheableSupportsMethod();
    }

    /**
     * CRÍTICO: mismo motivo documentado en CotizacionPublicNormalizer.
     * Sin reenviar setSerializer(), el normalizer de ApiPlatform envuelto
     * en la cadena de decoración nunca recibe el Serializer completo y
     * cualquier normalización de atributo anidado falla en toda la API.
     */
    public function setSerializer(SerializerInterface $serializer): void
    {
        if ($this->decorated instanceof SerializerAwareInterface) {
            $this->decorated->setSerializer($serializer);
        }
    }
}
