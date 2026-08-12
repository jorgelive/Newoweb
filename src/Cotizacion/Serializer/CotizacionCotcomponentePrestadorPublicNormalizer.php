<?php

declare(strict_types=1);

namespace App\Cotizacion\Serializer;

use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Travel\Enum\ComponenteModoEnum;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Normalizer\CacheableSupportsMethodInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * El prestador sólo se muestra al cliente en componentes `no_incluido`.
 *
 * Es el hotel que reservó él, el vuelo que compró él: enseñárselo hace que la
 * propuesta se lea como un itinerario completo en vez de como una lista de
 * carencias. En un componente `incluido`, en cambio, revelar quién lo opera es
 * justo lo que el anonimato white-label existe para evitar.
 *
 * ⚠️ Deliberadamente NO hereda el flag de anonimato de la tarifa
 * (`pax_proveedor_oculto_global` / `CotizacionCottarifa::proveedorOculto`).
 * Ese flag protege el margen: impide que el cliente se salte tu intermediación y
 * contrate directo. Un componente `no_incluido` no lo necesita porque no le estás
 * vendiendo nada — ya lo contrató él y sabe perfectamente cuál es. Encadenarlo al
 * flag global tendría el efecto absurdo de que activar el modo anónimo borrase de
 * la propuesta la referencia del hotel del propio pasajero.
 *
 * Los campos operativos (nombre comercial, teléfono, dirección) no aparecen aquí
 * porque no llevan el grupo `pax_cotizacion:read`: nunca llegan a esta capa.
 *
 * Registrado por atributo; no requiere entrada en services.yaml. Ver la nota
 * CRÍTICA sobre supportsNormalization() en CotizacionPublicNormalizer.
 */
#[AsDecorator(decorates: 'api_platform.jsonld.normalizer.item', priority: 9)]
final class CotizacionCotcomponentePrestadorPublicNormalizer implements NormalizerInterface, CacheableSupportsMethodInterface, SerializerAwareInterface
{
    private const GRUPO_PUBLICO = 'pax_cotizacion:read';

    /** Cara pública del prestador. La operativa ni siquiera llega hasta aquí. */
    private const PRESTADOR_SNAPSHOT_FIELDS = [
        'prestadorTituloSnapshot',
        'prestadorUrlSnapshot',
        'prestadorImagenesSnapshot',
    ];

    public function __construct(
        #[Autowire(service: 'App\Cotizacion\Serializer\CotizacionCotcomponentePrestadorPublicNormalizer.inner')]
        private readonly NormalizerInterface $decorated,
    ) {
    }

    public function normalize($object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $data = $this->decorated->normalize($object, $format, $context);

        $isPublicView = \in_array(self::GRUPO_PUBLICO, $context['groups'] ?? [], true);

        if ($isPublicView && $object instanceof CotizacionCotcomponente && \is_array($data)) {
            if ($object->getModo() !== ComponenteModoEnum::NO_INCLUIDO) {
                foreach (self::PRESTADOR_SNAPSHOT_FIELDS as $field) {
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
     * CRÍTICO: mismo motivo documentado en CotizacionPublicNormalizer. Sin reenviar
     * setSerializer(), el normalizer envuelto nunca recibe el Serializer completo y
     * cualquier normalización de atributo anidado falla en toda la API.
     */
    public function setSerializer(SerializerInterface $serializer): void
    {
        if ($this->decorated instanceof SerializerAwareInterface) {
            $this->decorated->setSerializer($serializer);
        }
    }
}
