<?php

declare(strict_types=1);

namespace App\Cotizacion\Service;

use App\Travel\Entity\TravelOrganizacion;
use App\Travel\Entity\TravelOrganizacionServicio;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Resuelve EN VIVO la cara pública del proveedor desde el catálogo maestro.
 *
 * El componente guarda un soft-link (`prestadorMaestroId`) más una copia congelada del
 * título, la url y las imágenes. La copia existe para sobrevivir al borrado del maestro,
 * pero **no es lo que se le enseña al cliente**: si el proveedor se renombra o cambia de
 * logo, la propuesta tiene que reflejarlo sin que nadie re-guarde nada.
 *
 * Por eso este servicio se consulta al SERVIR, y el snapshot queda de red de seguridad.
 *
 * ── Dos reglas que no son opcionales ────────────────────────────────────────
 *
 * 1. **Siempre en lote.** `precargar()` se llama UNA vez por cotización desde
 *    `CotizacionPublicNormalizer`, antes de que la serialización baje a los componentes.
 *    Resolver dentro del normalizer de cada componente sería una consulta por fila.
 *
 * 2. **Cuidado con los UUID.** El soft-link es un `VARCHAR(36)` con guiones y la clave del
 *    maestro es `BINARY(16)`. Casarlos a lo bruto no coincide nunca y —esto es lo caro— no
 *    da error: devuelve cero filas y el proveedor «simplemente no aparece». Es el mismo
 *    gotcha documentado en `TourTarjetaResolver`. Aquí se cierra por dos lados: los ids se
 *    normalizan a objeto `Uuid` antes de consultar, y las claves del mapa se normalizan
 *    siempre con `clave()`, de forma que da igual con qué forma se pregunte.
 *
 * Va por el ORM y no por SQL crudo a propósito: `imageUrl` es una propiedad virtual que
 * inyecta `TravelOrganizacionImagenAssetListener` en `postLoad`. Con SQL habría que reimplementar
 * la firma de las URLs.
 */
final class PrestadorVivoResolver
{
    /** @var array<string, TravelOrganizacion> */
    private array $proveedores = [];

    /** @var array<string, TravelOrganizacionServicio> */
    private array $servicios = [];

    /** Ids ya buscados, existan o no: evita repreguntar por los que no están. */
    private bool $precargado = false;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Trae de golpe todos los maestros que hagan falta. Idempotente por petición.
     *
     * @param array<int, string|null> $proveedorIds
     * @param array<int, string|null> $servicioIds
     */
    public function precargar(array $proveedorIds, array $servicioIds): void
    {
        $pUuids = self::aUuids($proveedorIds);
        $sUuids = self::aUuids($servicioIds);

        if ($pUuids !== []) {
            /** @var array<int, TravelOrganizacion> $encontrados */
            $encontrados = $this->em->getRepository(TravelOrganizacion::class)->findBy(['id' => $pUuids]);
            foreach ($encontrados as $p) {
                $this->proveedores[self::clave($p->getId())] = $p;
            }
        }

        if ($sUuids !== []) {
            /** @var array<int, TravelOrganizacionServicio> $encontrados */
            $encontrados = $this->em->getRepository(TravelOrganizacionServicio::class)->findBy(['id' => $sUuids]);
            foreach ($encontrados as $s) {
                $this->servicios[self::clave($s->getId())] = $s;
            }
        }

        $this->precargado = true;
    }

    public function estaPrecargado(): bool
    {
        return $this->precargado;
    }

    public function proveedor(?string $id): ?TravelOrganizacion
    {
        return $id === null ? null : ($this->proveedores[self::clave($id)] ?? null);
    }

    public function servicio(?string $id): ?TravelOrganizacionServicio
    {
        return $id === null ? null : ($this->servicios[self::clave($id)] ?? null);
    }

    /**
     * Galería en la forma que ya espera el front (misma que el snapshot).
     *
     * @return list<array{imageUrl: string|null, orden: int, isPortada: bool}>
     */
    public function imagenesDe(TravelOrganizacion $p): array
    {
        $out = [];
        foreach ($p->getImagenes() as $img) {
            $out[] = [
                'imageUrl' => $img->getImageUrl(),
                'orden' => $img->getOrden(),
                'isPortada' => $img->getIsPortada(),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{imageUrl: string|null, orden: int, isPortada: bool}>
     */
    public function imagenesDeServicio(TravelOrganizacionServicio $s): array
    {
        $out = [];
        foreach ($s->getImagenes() as $img) {
            $out[] = [
                'imageUrl' => $img->getImageUrl(),
                'orden' => $img->getOrden(),
                'isPortada' => $img->getIsPortada(),
            ];
        }

        return $out;
    }

    /**
     * Contacto del prestador: **el maestro manda, el snapshot rellena el hueco**.
     *
     * Es la dirección CONTRARIA a la de la presentación, y no es un descuido:
     *
     *   · el contacto quiere estar VIVO — el teléfono que contesta hoy, no el de hace tres
     *     meses, que es justo el que ya no sirve;
     *   · la presentación quiere estar ESCRITA — un título puesto a mano para esta
     *     propuesta es una decisión, no un dato envejecido (ver el normalizer público).
     *
     * Sin maestro —prestador de un solo uso, o empresa borrada del catálogo— se devuelve
     * el snapshot entero, que ahí es el único dato que existe. Es lo que permite mandarle
     * la orden esa vez al correo que figura.
     *
     * @param array{maestroId: string|null, nombre: string|null, email: string|null,
     *              telefono: string|null, direccion: string|null, manual: bool} $guardado
     * @return array{nombre: string|null, email: string|null, telefono: string|null,
     *               direccion: string|null, vivo: bool}
     */
    public function contactoDe(array $guardado): array
    {
        $maestro = $this->proveedor($guardado['maestroId'] ?? null);

        if ($maestro === null) {
            return [
                'nombre' => $guardado['nombre'] ?? null,
                'email' => $guardado['email'] ?? null,
                'telefono' => $guardado['telefono'] ?? null,
                'direccion' => $guardado['direccion'] ?? null,
                'vivo' => false,
            ];
        }

        return [
            'nombre' => $maestro->getNombreComercial() ?: ($guardado['nombre'] ?? null),
            'email' => $maestro->getEmail() ?: ($guardado['email'] ?? null),
            'telefono' => $maestro->getTelefono() ?: ($guardado['telefono'] ?? null),
            'direccion' => $maestro->getDireccion() ?: ($guardado['direccion'] ?? null),
            'vivo' => true,
        ];
    }

    /**
     * Normaliza cualquier forma de id a la canónica con guiones. Es lo que permite
     * preguntar con lo que sea —string del soft-link, objeto `Uuid`, binario crudo de una
     * query escalar— y que el mapa responda igual.
     */
    public static function clave(mixed $id): string
    {
        if ($id instanceof Uuid) {
            return strtolower((string) $id);
        }

        if (is_string($id) && strlen($id) === 16) {
            return strtolower((string) Uuid::fromBinary($id));
        }

        return strtolower(trim((string) $id));
    }

    /**
     * Soft-links a objetos `Uuid`, saltando los ilegibles.
     *
     * Un soft-link no tiene integridad referencial: puede guardar cualquier cosa. Si una
     * fila trae basura se ignora esa entrada, en vez de reventar la serialización entera
     * de la propuesta por un dato viejo.
     *
     * @param array<int, string|null> $ids
     * @return array<int, Uuid>
     */
    private static function aUuids(array $ids): array
    {
        $out = [];
        foreach (array_unique(array_filter($ids)) as $id) {
            if (!Uuid::isValid((string) $id)) {
                continue;
            }

            $out[] = Uuid::fromString((string) $id);
        }

        return $out;
    }
}
