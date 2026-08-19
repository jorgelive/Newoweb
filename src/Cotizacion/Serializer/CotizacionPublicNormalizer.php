<?php

declare(strict_types=1);

namespace App\Cotizacion\Serializer;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Service\PrestadorVivoResolver;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Nivel 1 del anonimato del visor público: la entidad Cotizacion.
 *
 * Responsabilidades:
 *  1. Si precioOculto=true, redacta SOLO los campos monetarios del JSON servido
 *     en el grupo `pax_cotizacion:read` (totalVenta, adelanto, y los montos
 *     dentro de clasificacionFinancieraCliente). Deliberadamente NO borra el
 *     bloque completo: `inclusiones` (ya sin montos) y los datos descriptivos
 *     de `opcionesUpgrade` (nombre, tarifa, badges) no son dinero y deben
 *     seguir viéndose aunque el precio esté oculto. Ver redactarMontos().
 *  2. Si proveedorOculto=true (flag GLOBAL a nivel de cotización completa),
 *     inyecta un flag en $context ANTES de delegar al normalizer decorado.
 *     Ese $context viaja automáticamente en toda la recursión de serialización
 *     (Cotizacion -> cotservicios -> cotcomponentes), así que
 *     CotizacionCotcomponenteProveedorPublicNormalizer lo puede leer 3 niveles
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
final class CotizacionPublicNormalizer implements NormalizerInterface, SerializerAwareInterface
{
    private const GRUPO_PUBLICO = 'pax_cotizacion:read';

    public function __construct(
        #[Autowire(service: 'App\Cotizacion\Serializer\CotizacionPublicNormalizer.inner')]
        private readonly NormalizerInterface $decorated,
        private readonly PrestadorVivoResolver $prestadorVivo,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @return array<array-key, mixed>|\ArrayObject<array-key, mixed>|string|int|float|bool|null
     */
    public function normalize($object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $isPublicView = \in_array(self::GRUPO_PUBLICO, $context['groups'] ?? [], true);

        // Se inyecta ANTES de delegar, para que el flag exista en el $context
        // que reciben las llamadas recursivas a los hijos (cotservicios, etc.).
        if ($isPublicView && $object instanceof Cotizacion && $object->isProveedorOculto()) {
            $context['pax_proveedor_oculto_global'] = true;
        }

        // Mismo criterio, misma razón: se hace ANTES de delegar. Aquí se recorre el árbol
        // una vez, se juntan los soft-links y se traen todos los maestros de golpe, para
        // que el normalizer de cada componente sólo tenga que leer del mapa. Resolverlo
        // ahí abajo sería una consulta por componente.
        if ($isPublicView && $object instanceof Cotizacion) {
            $this->precargarProveedores($object);
        }

        $data = $this->decorated->normalize($object, $format, $context);

        if ($isPublicView && $object instanceof Cotizacion && \is_array($data) && $object->isPrecioOculto()) {
            $data = $this->redactarMontos($data);
        }

        return $data;
    }

    /**
     * Junta los soft-links de prestador de toda la cotización y los resuelve en LOTE.
     *
     * Recorrer las colecciones aquí no añade coste: se van a serializar igualmente unas
     * líneas más abajo, así que la hidratación ya estaba pagada.
     */
    private function precargarProveedores(Cotizacion $cotizacion): void
    {
        $proveedorIds = [];
        $servicioIds = [];

        foreach ($cotizacion->getCotservicios() as $servicio) {
            foreach ($servicio->getCotcomponentes() as $componente) {
                $proveedorIds[] = $componente->getPrestadorMaestroId();
                $servicioIds[] = $componente->getPrestadorServicioMaestroId();
            }
        }

        $this->prestadorVivo->precargar($proveedorIds, $servicioIds);
    }

    /**
     * Quita únicamente los campos monetarios del payload público, dejando
     * intacto todo lo descriptivo (inclusiones, nombres/tarifas/badges de las
     * opciones de upgrade). `clasificacionFinancieraCliente` no se borra
     * entero: solo sus sub-campos de dinero.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function redactarMontos(array $data): array
    {
        unset($data['totalVenta'], $data['adelanto']);

        if (isset($data['clasificacionFinancieraCliente']) && \is_array($data['clasificacionFinancieraCliente'])) {
            $cfc = $data['clasificacionFinancieraCliente'];
            unset($cfc['totalVentaBruta'], $cfc['montoAdelanto'], $cfc['resumenGeneral'], $cfc['clasesPasajeros']);

            if (isset($cfc['opcionesUpgrade']) && \is_array($cfc['opcionesUpgrade'])) {
                $cfc['opcionesUpgrade'] = array_map(static function ($opcion) {
                    if (\is_array($opcion)) {
                        unset($opcion['deltaVentaPorPax'], $opcion['deltaVentaTotal'], $opcion['deltasPorPerfil']);
                    }
                    return $opcion;
                }, $cfc['opcionesUpgrade']);
            }

            $data['clasificacionFinancieraCliente'] = $cfc;
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
