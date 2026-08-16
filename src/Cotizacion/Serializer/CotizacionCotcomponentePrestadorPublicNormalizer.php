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
 * Qué se le enseña al cliente del PRESTADOR, y con qué datos.
 *
 * `Proveedor` es la entidad maestra —el contacto-empresa— y el prestador es el papel que
 * juega en un componente: quién lo presta. A nivel de cotización sólo hay dos papeles, éste
 * y el comprador; hubo un tercero llamado «proveedor» que decía lo mismo y se retiró.
 *
 * ── La visibilidad se decide una vez y se guarda ────────────────────────────
 * 🔥 Antes se re-derivaba aquí de `getModo() !== NO_INCLUIDO`. El modo es una clasificación
 * comercial, no una decisión sobre qué se enseña, y reevaluarlo en cada lectura hacía que
 * reclasificar un componente cambiara la propuesta **sin que nadie lo pidiera y sin
 * avisar**: pasar a `incluido` borraba al prestador de una propuesta ya enviada, y pasar a
 * `no_incluido` publicaba uno que nadie había revisado.
 *
 * La regla no se perdió, cambió de sitio: es el DEFAULT con que el editor siembra
 * `prestadorVisible` al asignarlo. Aquí sólo se lee lo decidido — que es además lo que
 * permite el caso que antes no cabía: asignar un prestador **sólo para operar** (teléfono y
 * dirección del recojo) sin publicarlo.
 *
 * ── El gate ─────────────────────────────────────────────────────────────────
 *
 *   se muestra  ⟺  NO (Cotizacion::proveedorOculto)  Y  componente.prestadorVisible
 *
 * El flag global es el interruptor white-label de toda la propuesta y gana siempre; la
 * bandera del componente sólo puede afinar hacia abajo. Ojo a la asimetría: el global está
 * en negativo y el del componente en positivo.
 *
 * ── Lo que se sirve está VIVO, y no hay copia que pisar ─────────────────────
 * La cotización guarda el ENLACE, no una copia: título, url e imágenes se INYECTAN aquí
 * leyendo `travel_proveedor`. Renombrar una empresa o cambiarle el logo se ve en todas las
 * propuestas sin re-guardar ninguna.
 *
 * Llegaron a estar congelados en ocho columnas y no se leía ninguna mientras el maestro
 * existiera — el dato bueno siempre estaba al otro lado del id.
 *
 * Si el prestador se escribió **a mano** no hay maestro que consultar: no se inyecta nada y
 * el cliente no ve tarjeta, sólo lo que la línea diga. Es coherente con que ese caso sea la
 * excepción: una empresa que aún no está en el catálogo no tiene logo ni ficha que enseñar.
 *
 * Los campos operativos (nombre, correo) no llevan `pax_cotizacion:read` y nunca llegan a
 * esta capa. La bandera tampoco: al cliente le llega su efecto, no el motivo.
 *
 * Registrado por atributo; no requiere entrada en services.yaml. Ver la nota CRÍTICA
 * sobre supportsNormalization() en CotizacionPublicNormalizer.
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
        'prestadorServicioTituloSnapshot',
        'prestadorServicioUrlSnapshot',
        'prestadorServicioImagenesSnapshot',
    ];

    public function __construct(
        #[Autowire(service: 'App\Cotizacion\Serializer\CotizacionCotcomponentePrestadorPublicNormalizer.inner')]
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
                || !$object->isPrestadorVisible();

            if ($ocultar) {
                foreach (self::PRESTADOR_SNAPSHOT_FIELDS as $field) {
                    unset($data[$field]);
                }
            } else {
                $data = $this->conDatosVivos($object, $data);
            }
        }

        return $data;
    }

    /**
     * Resuelve la cara pública: **el snapshot manda, el maestro rellena**.
     *
     * Es la dirección contraria a la del contacto, y confundirlas es el error fácil. Aquí
     * el snapshot no es una copia envejecida: es un **override escrito a propósito**. Si
     * alguien puso otro título para esta propuesta, es porque quería ése y no el del
     * catálogo — pisarlo con lo «actual» sería deshacer su decisión sin avisar.
     *
     * El maestro cubre el hueco cuando no se tocó nada, que es el caso normal: así
     * renombrar una empresa se ve en todas las propuestas que no la hayan sobreescrito.
     *
     * Sin maestro —prestador de un solo uso— sólo queda el snapshot, y con eso basta.
     *
     * Los datos vienen precargados en lote desde `CotizacionPublicNormalizer`: aquí no se
     * consulta nada.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function conDatosVivos(CotizacionCotcomponente $componente, array $data): array
    {
        $maestro = $this->proveedorVivo->proveedor($componente->getPrestadorMaestroId());

        if ($maestro !== null) {
            $data['prestadorTituloSnapshot'] = self::override(
                $componente->getPrestadorTituloSnapshot(),
                $maestro->getTitulo(),
            );
            $data['prestadorUrlSnapshot'] = self::override(
                $componente->getPrestadorUrlSnapshot(),
                $maestro->getUrl(),
            );
            $data['prestadorImagenesSnapshot'] = self::override(
                $componente->getPrestadorImagenesSnapshot(),
                $this->proveedorVivo->imagenesDe($maestro),
            );
        }

        $servicio = $this->proveedorVivo->servicio($componente->getPrestadorServicioMaestroId());

        if ($servicio !== null) {
            $data['prestadorServicioTituloSnapshot'] = self::override(
                $componente->getPrestadorServicioTituloSnapshot(),
                $servicio->getTitulo(),
            );
            $data['prestadorServicioUrlSnapshot'] = self::override(
                $componente->getPrestadorServicioUrlSnapshot(),
                $servicio->getUrl(),
            );
            $data['prestadorServicioImagenesSnapshot'] = self::override(
                $componente->getPrestadorServicioImagenesSnapshot(),
                $this->proveedorVivo->imagenesDeServicio($servicio),
            );
        }

        return $data;
    }

    /**
     * Lo escrito gana; si no se escribió nada, lo del catálogo.
     *
     * «Nada» es null, cadena vacía o array vacío — un título i18n sin tocar llega como
     * `[]`, no como null, así que comprobar sólo `null` dejaría pasar el hueco.
     */
    private static function override(mixed $escrito, mixed $delCatalogo): mixed
    {
        if ($escrito === null || $escrito === '' || $escrito === []) {
            return $delCatalogo;
        }

        return $escrito;
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
