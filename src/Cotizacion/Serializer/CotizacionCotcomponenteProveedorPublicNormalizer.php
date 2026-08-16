<?php

declare(strict_types=1);

namespace App\Cotizacion\Serializer;

use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Cotizacion\Service\ProveedorVivoResolver;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Anonimato del proveedor, y resolución EN VIVO de su presentación.
 *
 * Hasta 2026-08-16 esto vivía un nivel más abajo, sobre cada `CotizacionCottarifa`,
 * porque el proveedor estaba anidado ahí. La regla de negocio es la misma; lo que cambia
 * es que se aplica **una vez por componente** en lugar de una por tarifa — que es justo
 * el punto de haber movido el dato.
 *
 * La condición sigue siendo un OR que sólo puede añadir ocultamiento, nunca forzar que
 * se muestre:
 *
 *   se muestra  ⟺  NO (flag global)  Y  componente.proveedorVisible
 *
 *  · El flag GLOBAL de `Cotizacion::$proveedorOculto` llega por
 *    `$context['pax_proveedor_oculto_global']`, que inyecta `CotizacionPublicNormalizer`
 *    al normalizar la cotización raíz. Es el interruptor white-label de toda la
 *    propuesta y gana siempre.
 *  · La bandera del COMPONENTE afina hacia abajo, para el anonimato granular de un solo
 *    ítem aunque el resto de la propuesta sí enseñe proveedores.
 *
 * ⚠️ La bandera está en positivo (`proveedorVisible`) mientras el global sigue en
 * negativo (`proveedorOculto`). No es descuido: el global es de la cotización y no se ha
 * tocado en este movimiento. Al leer la condición, cuidado con esa asimetría.
 *
 * ⚠️ No confundir con `CotizacionCotcomponentePrestadorPublicNormalizer`, que trabaja
 * sobre la misma entidad y con campos de nombre parecido. Son dos roles distintos y sus
 * reglas son deliberadamente distintas: el PRESTADOR no lo tapa el flag global —ver el
 * porqué en ese archivo—, el PROVEEDOR sí, porque es el que protege el margen.
 *
 * ⚠️ **Lo que ve el cliente sale del MAESTRO, no del snapshot.** Cuando el proveedor pasa
 * el gate, sus campos se sobreescriben con lo que dice hoy `travel_proveedor`: renombrar un
 * hotel o cambiarle el logo se refleja en todas las propuestas sin re-guardar ninguna. El
 * snapshot del componente sigue escribiéndose y queda de respaldo para cuando el maestro ya
 * no exista. Ver `ProveedorVivoResolver`, que además explica por qué la resolución va en
 * lote y qué cuidado hay que tener con los UUID.
 *
 * Registrado por atributo; no requiere entrada en services.yaml. Ver la nota CRÍTICA
 * sobre supportsNormalization() en CotizacionPublicNormalizer.
 */
#[AsDecorator(decorates: 'api_platform.jsonld.normalizer.item', priority: 8)]
final class CotizacionCotcomponenteProveedorPublicNormalizer implements NormalizerInterface, SerializerAwareInterface
{
    private const GRUPO_PUBLICO = 'pax_cotizacion:read';

    /**
     * Cara pública del proveedor y de su servicio. La operativa
     * (`proveedorNombreSnapshot`) no lleva el grupo público y no llega hasta aquí.
     */
    private const PROVEEDOR_SNAPSHOT_FIELDS = [
        'proveedorTituloSnapshot',
        'proveedorUrlSnapshot',
        'proveedorImagenesSnapshot',
        'proveedorServicioTituloSnapshot',
        'proveedorServicioUrlSnapshot',
        'proveedorServicioImagenesSnapshot',
    ];

    public function __construct(
        #[Autowire(service: 'App\Cotizacion\Serializer\CotizacionCotcomponenteProveedorPublicNormalizer.inner')]
        private readonly NormalizerInterface $decorated,
        private readonly ProveedorVivoResolver $proveedorVivo,
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
            $ocultar = ($context['pax_proveedor_oculto_global'] ?? false)
                || !$object->isProveedorVisible();

            if ($ocultar) {
                foreach (self::PROVEEDOR_SNAPSHOT_FIELDS as $field) {
                    unset($data[$field]);
                }
            } else {
                $data = $this->conDatosVivos($object, $data);
            }
        }

        return $data;
    }

    /**
     * Pisa el snapshot con lo que dice HOY el catálogo maestro.
     *
     * El snapshot no se borra ni deja de escribirse: es la red para cuando el maestro ya
     * no existe —los soft-links no tienen integridad referencial y un proveedor se puede
     * borrar— y para que una propuesta vieja siga diciendo algo en vez de quedarse muda.
     * Pero mientras el maestro esté, manda él: renombrar un hotel o cambiarle el logo
     * tiene que verse en todas las propuestas sin que nadie las re-guarde.
     *
     * Los datos ya vienen precargados en lote desde `CotizacionPublicNormalizer`; aquí no
     * se consulta nada.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function conDatosVivos(CotizacionCotcomponente $componente, array $data): array
    {
        $proveedor = $this->proveedorVivo->proveedor($componente->getProveedorMaestroId());

        if ($proveedor !== null) {
            $data['proveedorTituloSnapshot'] = $proveedor->getTitulo();
            $data['proveedorUrlSnapshot'] = $proveedor->getUrl();
            $data['proveedorImagenesSnapshot'] = $this->proveedorVivo->imagenesDe($proveedor);
        }

        $servicio = $this->proveedorVivo->servicio($componente->getProveedorServicioMaestroId());

        if ($servicio !== null) {
            $data['proveedorServicioTituloSnapshot'] = $servicio->getTitulo();
            $data['proveedorServicioUrlSnapshot'] = $servicio->getUrl();
            $data['proveedorServicioImagenesSnapshot'] = $this->proveedorVivo->imagenesDeServicio($servicio);
        }

        return $data;
    }

    public function supportsNormalization($data, ?string $format = null, array $context = []): bool
    {
        // Delega siempre: ver nota crítica en CotizacionPublicNormalizer.
        return $this->decorated->supportsNormalization($data, $format, $context);
    }

    /** @return array<string, bool> */
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
