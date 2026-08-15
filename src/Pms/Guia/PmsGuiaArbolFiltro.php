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
    /**
     * @param list<string> $categoriasBloqueadas Categorías que no pueden salir por el canal
     *        que va a responder. Vacío —el default— deja el comportamiento de siempre, que es
     *        el de la web y el catálogo: ahí no hay partner que restrinja nada.
     */
    public function podar(
        PmsGuia $guia,
        PmsGuiaAcceso $acceso,
        PmsGuiaContexto $contexto,
        array $categoriasBloqueadas = []
    ): array {
        $visibles = [];

        // El `assert()` que había aquí sobra desde que `getSeccionesApi()` declara
        // `list<PmsGuiaSeccion>`: lo que garantizaba en ejecución lo garantiza ahora el tipo.
        foreach ($guia->getSeccionesApi() as $seccion) {
            $items = $this->podarItems($seccion, $acceso, $contexto, $categoriasBloqueadas);

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
    /**
     * @param list<string> $categoriasBloqueadas
     *
     * @return array<int, PmsGuiaItem>
     */
    private function podarItems(
        PmsGuiaSeccion $seccion,
        PmsGuiaAcceso $acceso,
        PmsGuiaContexto $contexto,
        array $categoriasBloqueadas = []
    ): array {
        $items = [];

        // Igual que arriba: el `assert()` sobra desde que `getItemsApi()` declara su tipo.
        foreach ($seccion->getItemsApi() as $item) {

            // 🚧 RESTA POR CANAL, antes que nada.
            //
            // Va primero y DESAPARECE el ítem, sin candado ni anuncio. El candado de más abajo
            // dice «esto existe y lo verás cuando confirmes», que es información útil para
            // quien espera. Aquí no: anunciar «hay una dirección que no puedo darte» es
            // invitar al modelo a hablar de ella, y lo que el partner prohíbe no es el valor
            // sino la conversación entera. Si no puede salir, no existe.
            //
            // Y es un eje distinto de la visibilidad: se aplica encima, no en su lugar. Un
            // ítem `Publico` puede caer aquí —la dirección lo es— sin que eso cambie lo que se
            // sirve en la web.
            if (!$item->getCategoria()->permitidaCon($categoriasBloqueadas)) {
                continue;
            }

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

                // 🔒 EL CUERPO SE SUSTITUYE, no se interpola.
                //
                // Antes se llamaba al mismo `interpolarItem()` que para un ítem visible, y eso
                // sólo enmascara los PLACEHOLDERS: el texto editorial salía entero en el JSON
                // y el front lo pintaba con un badge de «aún no disponible». El comentario de
                // aquí prometía justo lo contrario desde el principio.
                //
                // Hoy no se filtra nada —lo sensible de los ítems bloqueados vive en
                // placeholders— pero el primer ítem que guarde un secreto en su TEXTO lo
                // regalaría. El título sí se interpola: hace falta para nombrarlo.
                $items[] = $item
                    ->setTituloParaCliente($this->interpolador->interpolar($item->getTitulo(), $contexto, $acceso))
                    ->setContenidoParaCliente($this->mensajeDeBloqueo($acceso));
            }
        }

        return $items;
    }

    /**
     * El motivo del candado, en los siete idiomas, con la forma i18n que espera el front.
     *
     * Se generan todos y no sólo el del huésped porque `contenidoParaCliente` es un JSON por
     * idioma: el consumidor elige. Es el mismo texto que ya se le enseñaba —«[Disponible al
     * confirmar]», «[Disponible el 12/08 a las 15:00]»—, pero ahora es TODO lo que viaja.
     *
     * @return list<array{language: string, content: string}>
     */
    private function mensajeDeBloqueo(PmsGuiaAcceso $acceso): array
    {
        $salida = [];

        foreach (PmsGuiaMensajes::IDIOMAS as $idioma) {
            $salida[] = [
                'language' => $idioma,
                'content' => PmsGuiaMensajes::bloqueo($acceso, $idioma),
            ];
        }

        return $salida;
    }

    private function interpolarItem(PmsGuiaItem $item, PmsGuiaAcceso $acceso, PmsGuiaContexto $contexto): PmsGuiaItem
    {
        return $item
            ->setTituloParaCliente($this->interpolador->interpolar($item->getTitulo(), $contexto, $acceso))
            ->setContenidoParaCliente($this->interpolador->interpolar($item->getDescripcion(), $contexto, $acceso));
    }
}
