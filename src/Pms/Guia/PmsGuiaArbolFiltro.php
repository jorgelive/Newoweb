<?php

declare(strict_types=1);

namespace App\Pms\Guia;

use App\Pms\Entity\PmsGuia;
use App\Pms\Entity\PmsGuiaItem;
use App\Pms\Entity\PmsGuiaSeccion;

/**
 * Poda el árbol de la guía dejando solo lo que el portador del enlace puede
 * ver, e interpola el contenido que sobrevive.
 *
 * Es la pieza que faltaba: PmsGuia::getSeccionesApi() devuelve TODAS las
 * secciones activas con TODOS sus ítems, y ese mismo árbol alimentaba tanto la
 * guía del huésped como el enlace público por UUID de unidad. Las credenciales
 * no viajaban —no tienen Groups— pero sí la estructura entera: normas de la
 * casa, avisos y el texto que rodea a la caja fuerte.
 *
 * Escribe en propiedades virtuales no persistidas (`setItemsParaCliente`,
 * `setContenidoParaCliente`…), nunca en columnas. Las entidades vienen del
 * EntityManager pero esta clase no las ensucia: no hay flush en el camino de
 * lectura de la API.
 */
final class PmsGuiaArbolFiltro
{
    public function __construct(private readonly PmsGuiaInterpolador $interpolador)
    {
    }

    /**
     * Devuelve las secciones visibles, ya con sus ítems filtrados e
     * interpolados. Una sección desaparece si no le queda ningún ítem: la
     * visibilidad vive solo en el ítem y la sección la hereda, de forma que no
     * puede haber dos campos contradiciéndose ni secciones vacías en el
     * catálogo.
     *
     * @return array<int, PmsGuiaSeccion>
     */
    public function podar(PmsGuia $guia, PmsGuiaAcceso $acceso, PmsGuiaContexto $contexto): array
    {
        $visibles = [];

        foreach ($guia->getSeccionesApi() as $seccion) {
            \assert($seccion instanceof PmsGuiaSeccion);

            $items = $this->podarItems($seccion, $acceso, $contexto);

            if ([] === $items) {
                continue;
            }

            $seccion->setItemsParaCliente($items);
            $visibles[] = $seccion;
        }

        return $visibles;
    }

    /**
     * @return array<int, PmsGuiaItem>
     */
    private function podarItems(PmsGuiaSeccion $seccion, PmsGuiaAcceso $acceso, PmsGuiaContexto $contexto): array
    {
        $items = [];

        foreach ($seccion->getItemsApi() as $item) {
            \assert($item instanceof PmsGuiaItem);

            $visibilidad = $item->getVisibilidad();

            if ($acceso->permite($visibilidad)) {
                $item->setBloqueado(false)->setBloqueadoHasta(null);
                $items[] = $this->interpolarItem($item, $acceso, $contexto);
                continue;
            }

            // No se puede ver todavía. Si la espera tiene salida —una fecha, o
            // una confirmación que depende del huésped— el ítem se queda
            // anunciado con el candado y su cuerpo sale con el mensaje de
            // bloqueo, nunca con el valor. Si no la tiene (estancia expirada),
            // desaparece del árbol. Ver PmsGuiaAcceso::debeAnunciarBloqueo().
            if ($acceso->debeAnunciarBloqueo($visibilidad)) {
                // `liberaEn` es null salvo en Pendiente: el candado lo marca
                // `setBloqueado()`, y la fecha es solo el "cuándo" opcional.
                $item->setBloqueado(true)->setBloqueadoHasta($acceso->liberaEn);
                $items[] = $this->interpolarItem($item, $acceso, $contexto);
            }
        }

        return $items;
    }

    private function interpolarItem(PmsGuiaItem $item, PmsGuiaAcceso $acceso, PmsGuiaContexto $contexto): PmsGuiaItem
    {
        return $item
            ->setTituloParaCliente($this->interpolador->interpolar($item->getTitulo(), $contexto, $acceso))
            ->setContenidoParaCliente($this->interpolador->interpolar($item->getDescripcion(), $contexto, $acceso));
    }
}
