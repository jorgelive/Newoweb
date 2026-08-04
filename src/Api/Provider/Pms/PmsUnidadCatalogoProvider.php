<?php

declare(strict_types=1);

namespace App\Api\Provider\Pms;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Pms\Entity\PmsCatalogo;
use App\Pms\Entity\PmsGuiaItem;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Enum\PmsCatalogoBloque;
use App\Pms\Guia\PmsGuiaAcceso;
use App\Pms\Guia\PmsGuiaContexto;
use App\Pms\Guia\PmsGuiaInterpolador;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Escaparate público de una unidad: `.pe/{establecimiento}/{unidad}`.
 *
 * Sirve un `PmsCatalogo`, no la guía filtrada. La diferencia no es de filtro
 * sino de ESTRUCTURA: la guía agrupa por *cuándo lo necesitas* y el escaparate
 * por *qué vende*. Ver el docblock de PmsCatalogo.
 *
 * Qué decide qué se ve:
 *
 *  · **Pertenencia** — solo salen los `PmsGuiaItem` con fila activa en
 *    `PmsCatalogoHasItem`. `PmsGuiaVisibilidad` ya no interviene aquí: rige la
 *    guía y nada más.
 *  · **Interpolación pública** — el contexto se construye SIN estancia, así que
 *    `PmsGuiaContexto` no carga ni una credencial y el interpolador sustituye
 *    cualquier `{{ door_code }}` por el mensaje de bloqueo. Es la red que hace
 *    que colocar un ítem operativo aquí por error no filtre nada.
 */
final class PmsUnidadCatalogoProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PmsGuiaInterpolador $interpolador,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?PmsCatalogo
    {
        $unidad = $this->em->getRepository(PmsUnidad::class)
            ->findOneBy(['slug' => $uriVariables['unidad'] ?? null]);

        if (!$unidad || !$unidad->isActivo()) {
            return null; // 404 uniforme
        }

        // El slug de unidad es único a nivel global, así que la búsqueda de
        // arriba ya devuelve una sola fila. El segmento del establecimiento se
        // valida igualmente: una URL con el hotel equivocado tiene que dar 404
        // y no servir la unidad correcta bajo una dirección inventada, que
        // duplicaría el contenido en los buscadores.
        if ($unidad->getEstablecimiento()?->getSlug() !== ($uriVariables['establecimiento'] ?? null)) {
            return null;
        }

        $catalogo = $unidad->getCatalogo();

        if (!$catalogo || !$catalogo->isActivo()) {
            return null;
        }

        $bloques = $this->construirBloques($catalogo);

        // Sin nada publicado no hay escaparate que enseñar. Devolver la cáscara
        // —título y foto, cero contenido— solo serviría para que los buscadores
        // indexaran una página vacía.
        if ([] === $bloques) {
            return null;
        }

        return $catalogo
            ->setBloquesParaCliente($bloques)
            ->setUnidadesHermanasParaCliente($this->hermanasPublicadas($unidad));
    }

    /**
     * Agrupa los ítems del catálogo por bloque, en el orden de la página.
     *
     * El orden ENTRE bloques lo fija el orden de declaración del enum
     * (`PmsCatalogoBloque::cases()`), no la base de datos: la secuencia que
     * vende es una decisión de producto, no de datos. Dentro de cada bloque
     * manda el `orden` de la relación.
     *
     * @return array<int, array{tipo: string, items: array<int, PmsGuiaItem>}>
     */
    private function construirBloques(PmsCatalogo $catalogo): array
    {
        $contexto = PmsGuiaContexto::construir($catalogo->getUnidad(), null);
        $acceso = PmsGuiaAcceso::publico();

        /** @var array<string, array<int, array{orden: int, item: PmsGuiaItem}>> $porBloque */
        $porBloque = [];

        foreach ($catalogo->getCatalogoHasItems() as $rel) {
            $item = $rel->getItem();
            if (!$rel->isActivo() || !$item) {
                continue;
            }

            $porBloque[$rel->getBloque()->value][] = [
                'orden' => $rel->getOrden(),
                'item' => $item
                    ->setBloqueado(false)
                    ->setBloqueadoHasta(null)
                    ->setTituloParaCliente($this->interpolador->interpolar($item->getTitulo(), $contexto, $acceso))
                    ->setContenidoParaCliente($this->interpolador->interpolar($item->getDescripcion(), $contexto, $acceso)),
            ];
        }

        $out = [];

        foreach (PmsCatalogoBloque::cases() as $bloque) {
            $filas = $porBloque[$bloque->value] ?? [];
            if ([] === $filas) {
                continue;
            }

            usort($filas, static fn (array $a, array $b): int => $a['orden'] <=> $b['orden']);

            $out[] = [
                'tipo' => $bloque->value,
                'items' => array_column($filas, 'item'),
            ];
        }

        return $out;
    }

    /**
     * Las demás unidades del establecimiento que TIENEN escaparate.
     *
     * Basta con `catalogo->isActivo()` y que tenga algún ítem activo: ya no hay
     * que podar el árbol de la guía por cada hermana como antes, porque la
     * pertenencia es explícita. Enlazar solo a las publicadas evita el 404 —un
     * enlace muerto en una página pública es peor que no ofrecer el salto.
     *
     * @return array<int, array{nombre: string, slug: string, imageUrl: string|null, capacidad: int|null}>
     */
    private function hermanasPublicadas(PmsUnidad $actual): array
    {
        $establecimiento = $actual->getEstablecimiento();
        if (!$establecimiento) {
            return [];
        }

        $out = [];

        foreach ($establecimiento->getUnidades() as $hermana) {
            if ($hermana === $actual || !$hermana->isActivo() || !$hermana->getSlug()) {
                continue;
            }

            $catalogo = $hermana->getCatalogo();
            if (!$catalogo || !$catalogo->isActivo()) {
                continue;
            }

            $tieneContenido = false;
            foreach ($catalogo->getCatalogoHasItems() as $rel) {
                if ($rel->isActivo() && $rel->getItem()) {
                    $tieneContenido = true;
                    break;
                }
            }

            if (!$tieneContenido) {
                continue;
            }

            $out[] = [
                'nombre' => (string) $hermana->getNombre(),
                'slug' => (string) $hermana->getSlug(),
                'imageUrl' => $hermana->getImageUrl(),
                'capacidad' => $hermana->getCapacidad(),
            ];
        }

        usort($out, static fn (array $a, array $b): int => strnatcasecmp($a['nombre'], $b['nombre']));

        return $out;
    }
}
