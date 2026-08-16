<?php

declare(strict_types=1);

namespace App\Cotizacion\Serializer;

use App\Cotizacion\Entity\CotizacionCotcomponente;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * El prestador se muestra al cliente si la cotización lo decidió: `prestadorVisible`.
 *
 * Es el hotel que reservó él, el vuelo que compró él: enseñárselo hace que la
 * propuesta se lea como un itinerario completo en vez de como una lista de
 * carencias. En un componente `incluido`, en cambio, revelar quién lo opera es
 * justo lo que el anonimato white-label existe para evitar.
 *
 * 🔥 **Antes esa decisión se re-derivaba aquí de `getModo() !== NO_INCLUIDO`, y eso
 * era el defecto.** El modo es una clasificación comercial, no una decisión sobre
 * qué se le enseña al cliente; reevaluarlo en cada lectura hacía que reclasificar
 * un componente cambiara la propuesta **sin que nadie lo pidiera y sin avisar**, en
 * los dos sentidos: pasar a `incluido` borraba al prestador de una propuesta ya
 * enviada, y pasar a `no_incluido` publicaba uno que nadie había revisado.
 *
 * La regla no se perdió, cambió de sitio: hoy es el DEFAULT con que el editor
 * siembra la bandera al asignar el prestador. Aquí sólo se lee lo ya decidido, que
 * es lo que permite además expresar el caso que antes no cabía — asignar un
 * prestador **sólo para operar** (teléfono y dirección del recojo) sin publicarlo.
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
 * porque no llevan el grupo `pax_cotizacion:read`: nunca llegan a esta capa. Y la
 * propia bandera tampoco lo lleva: al cliente no le importa, sólo su efecto.
 *
 * Registrado por atributo; no requiere entrada en services.yaml. Ver la nota
 * CRÍTICA sobre supportsNormalization() en CotizacionPublicNormalizer.
 */
#[AsDecorator(decorates: 'api_platform.jsonld.normalizer.item', priority: 9)]
final class CotizacionCotcomponentePrestadorPublicNormalizer implements NormalizerInterface, SerializerAwareInterface
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

    /**
     * @param array<string, mixed> $context
     * @return array<array-key, mixed>|\ArrayObject<array-key, mixed>|string|int|float|bool|null
     */
    public function normalize($object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $data = $this->decorated->normalize($object, $format, $context);

        $isPublicView = \in_array(self::GRUPO_PUBLICO, $context['groups'] ?? [], true);

        if ($isPublicView && $object instanceof CotizacionCotcomponente && \is_array($data)) {
            if (!$object->isPrestadorVisible()) {
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
