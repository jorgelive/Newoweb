<?php

declare(strict_types=1);

namespace App\Cotizacion\Serializer;

use App\Cotizacion\Entity\Cotizacion;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Normalizer\CacheableSupportsMethodInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Nivel 1 del anonimato del visor público: la entidad Cotizacion.
 *
 * Responsabilidades:
 *  1. Si precioOculto=true, elimina totalVenta y clasificacionFinancieraCliente
 *     del JSON servido en el grupo `pax_cotizacion:read`.
 *  2. Si proveedorOculto=true (flag GLOBAL a nivel de cotización completa),
 *     inyecta un flag en $context ANTES de delegar al normalizer decorado.
 *     Ese $context viaja automáticamente en toda la recursión de serialización
 *     (Cotizacion -> cotservicios -> cotcomponentes -> cottarifas), así que
 *     CotizacionCottarifaProveedorPublicNormalizer lo puede leer 4 niveles
 *     más abajo sin que este archivo conozca esa entidad directamente.
 *
 * CRÍTICO: supportsNormalization() delega SIEMPRE al normalizer decorado.
 * Este servicio reemplaza el normalizer general de item de toda la API
 * (api_platform.jsonld.normalizer.item), así que si aquí se restringiera el
 * soporte solo a Cotizacion, se rompería la serialización de todas las demás
 * entidades del sistema (CotizacionFile, MaestroPais, etc.). La restricción
 * por tipo vive dentro de normalize(), nunca en el gate de entrada.
 *
 * Registrado por atributo: no requiere ninguna entrada en services.yaml.
 */
#[AsDecorator(decorates: 'api_platform.jsonld.normalizer.item', priority: 20)]
final class CotizacionPublicNormalizer implements NormalizerInterface, CacheableSupportsMethodInterface, SerializerAwareInterface
{
    private const GRUPO_PUBLICO = 'pax_cotizacion:read';

    public function __construct(
        #[Autowire(service: 'App\Cotizacion\Serializer\CotizacionPublicNormalizer.inner')]
        private readonly NormalizerInterface $decorated,
    ) {
    }

    public function normalize($object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $isPublicView = \in_array(self::GRUPO_PUBLICO, $context['groups'] ?? [], true);

        // Se inyecta ANTES de delegar, para que el flag exista en el $context
        // que reciben las llamadas recursivas a los hijos (cotservicios, etc.).
        if ($isPublicView && $object instanceof Cotizacion && $object->isProveedorOculto()) {
            $context['pax_proveedor_oculto_global'] = true;
        }

        $data = $this->decorated->normalize($object, $format, $context);

        if ($isPublicView && $object instanceof Cotizacion && \is_array($data) && $object->isPrecioOculto()) {
            unset($data['totalVenta'], $data['clasificacionFinancieraCliente']);
        }

        return $data;
    }

    public function supportsNormalization($data, ?string $format = null, array $context = []): bool
    {
        // Delega siempre: este normalizer no debe decidir qué se serializa,
        // solo post-procesa el resultado cuando el objeto es una Cotizacion.
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
     * CRÍTICO: sin este método, ApiPlatform\Serializer\AbstractItemNormalizer
     * (envuelto dentro de $decorated) nunca recibe la instancia del Serializer
     * completo. Symfony's Serializer solo llama setSerializer() sobre los
     * normalizers que él mismo registra directamente — que ahora es ESTE
     * decorador, no el normalizer original de ApiPlatform que quedó oculto
     * adentro de la cadena de decoración.
     *
     * Sin este reenvío, $this->serializer queda null dentro del normalizer
     * de ApiPlatform, y CUALQUIER intento de normalizar un atributo anidado
     * (literalmente cualquier propiedad de cualquier entidad, no solo las
     * del visor público) explota con LogicException. Rompe toda la API,
     * no solo esta ruta.
     */
    public function setSerializer(SerializerInterface $serializer): void
    {
        if ($this->decorated instanceof SerializerAwareInterface) {
            $this->decorated->setSerializer($serializer);
        }
    }
}
