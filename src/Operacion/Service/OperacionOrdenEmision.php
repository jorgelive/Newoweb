<?php

declare(strict_types=1);

namespace App\Operacion\Service;

use App\Operacion\Entity\OperacionOrdenServicio;
use App\Operacion\Entity\OperacionOrdenServicioItem;
use App\Operacion\Entity\OperacionServicio;
use App\Operacion\Enum\EstadoOrdenServicioEnum;
use App\Operacion\Service\OperacionPuntosDelServicio;
use DomainException;

/**
 * Las dos transiciones que convierten una Orden en documento y la retiran.
 *
 * ## Emitir: se congela el contenido
 *
 * Mientras es `borrador`, la Orden es una **vista viva** sobre las filas de La Biblia: se está
 * componiendo, y corregir un pax allí debe reflejarse aquí. Al emitirla pasa a ser un
 * **documento**, y un documento dice lo que decía el día que se mandó.
 *
 * ## Anular: se suelta el vínculo, se conserva el contenido
 *
 * Es la razón de ser de todo esto. Antes los ítems eran las filas vivas, así que:
 *
 *     liberas las filas  →  la Orden anulada queda VACÍA
 *     las dejas atadas   →  no se pueden volver a pedir en otra Orden
 *
 * Con el contenido congelado las dos cosas son ciertas: la Orden sigue diciendo lo que pidió y
 * la fila queda libre.
 *
 * ⚠️ **Lo que se canceló no vuelve a entrar, y no hace falta marcarlo.** Al soltarse, la fila
 * queda disponible; pero si el componente está cancelado o reemplazado en la cotización,
 * {@see \App\Operacion\Entity\OperacionServicio::motivoNoComprable()} le impide entrar en la
 * siguiente Orden. La guarda ya existía: reutilizarla es un mecanismo menos que mantener.
 */
final readonly class OperacionOrdenEmision
{
    public function __construct(private OperacionPuntosDelServicio $puntos) {}

    /**
     * Congela el contenido y marca la Orden como emitida.
     *
     * Idempotente: si ya tiene líneas congeladas no se rehacen. Reemitir no es «volver a
     * congelar» —eso reescribiría el documento— sino **anular y crear la sucesora**.
     */
    public function emitir(OperacionOrdenServicio $orden): void
    {
        if ($orden->getItems()->count() === 0) {
            foreach ($orden->getOperacionServicios() as $servicio) {
                $negociado = (float) $servicio->getCostoNegociado();

                $item = new OperacionOrdenServicioItem();
                $item
                    ->setOperacionServicioId((string) $servicio->getId())
                    // Los DOS, siempre: qué es y dónde encaja. `descripcion` sola es la
                    // variante de tarifa, y sola le decía «Auto» al que hace el traslado.
                    ->setDescripcion($servicio->getDescripcionServicio())
                    ->setNombreComponente($servicio->getNombreComponente())
                    // El MOMENTO: sin él, el componente tiene que cargar con la ruta en su
                    // nombre, y eso es lo que multiplicó las tarifas por destino.
                    ->setNombreSegmento($servicio->getNombreSegmento())
                    // El TIPO decide cuál de los dos nombres va en grande, así que se congela
                    // con ellos: leerlo del maestro al pintar haría que una orden emitida se
                    // leyera distinta el día que el catálogo cambie de opinión.
                    ->setTipoComponente($servicio->getTipoComponente())
                    ->setContextoServicio($servicio->getContextoServicio())
                    ->setFechaServicio($servicio->getFechaServicio())
                    // La hora que se pidió: la pactada si la hay, si no la vendida.
                    ->setHora($servicio->getHoraRecojo() ?? $servicio->getHoraComponente())
                    // Nula si el proveedor todavía no la ha confirmado. Es lo que distingue
                    // «confirmó» de «cambió» cuando aparezca. Ver el docblock del ítem.
                    ->setHoraRecojoConfirmada($servicio->getHoraRecojo())
                    ->setCantidadPax($servicio->getCantidadPax())
                    ->setCantidad((string) $servicio->getCantidadComponente())
                    // Mientras nadie negocie, lo que se pide es lo cotizado: un cero se leería
                    // como «pactado en cero», que es lo contrario de «todavía sin pactar».
                    ->setImporte($negociado > 0.0 ? $servicio->getCostoNegociado() : $servicio->getCostoCotizado())
                    ->setMoneda($negociado > 0.0
                        ? ($servicio->getMonedaNegociada() ?? $servicio->getMonedaCotizada())
                        : $servicio->getMonedaCotizada())
                    // Por NOMBRE y el EFECTIVO: el documento no depende de que la ficha siga
                    // existiendo, y lo que se pidió es lo que operaciones decidió.
                    ->setPrestadorNombre($servicio->getPrestadorEfectivoNombre())
                    ->setPrestadorServicioNombre($servicio->getPrestadorServicioEfectivoNombre())
                    // Lo que hay que contarle: su redacción si el operador la escribió, si no los
                    // detalles que la cotización marcó para él. Congelado, como todo lo demás.
                    ->setNotasPrestador($servicio->getNotasPrestadorEfectivas());

                // ── Dónde recoge y dónde deja, CONGELADOS ────────────────────
                //
                // Se resuelven del todo aquí —override del operador, punto del catálogo y, si
                // toca, el hotel concreto de esa noche— y se guardan como texto. Leerlos en vivo
                // al pintar el documento haría que el proveedor abriera el enlace público la
                // semana siguiente y viera un sitio DISTINTO del que se le mandó. Un documento
                // emitido dice lo que decía al emitirse; si el catálogo cambia, se reemite.
                $puntos = $this->puntos->para($servicio);

                if ($puntos->aplica) {
                    $item
                        ->setPuntoRecojoConfirmado($puntos->recojo)
                        ->setPuntoEntregaConfirmado($puntos->entrega)
                        ->setVisibilidadRecojo($servicio->getVisibilidadRecojo())
                        ->setVisibilidadEntrega($servicio->getVisibilidadEntrega());
                }

                $orden->addItem($item);
            }
        }

        // La llave del enlace público, aquí y no al crear: hasta ahora no había documento que
        // enseñar. Es idempotente, así que reemitir no invalida un enlace ya repartido.
        $orden->asegurarTokenPublico();

        $orden->setEstadoOs(EstadoOrdenServicioEnum::EMITIDA);
    }

    /**
     * Anula la Orden y suelta sus filas.
     *
     * @param OperacionOrdenServicio|null $sucesora la que la sustituye, si se está reemitiendo
     */
    public function anular(OperacionOrdenServicio $orden, ?OperacionOrdenServicio $sucesora = null): void
    {
        // ⚠️ El ESTADO va primero, y no es cosmético. Sacar una fila de una orden sólo se
        // permite mientras se compone (borrador) o cuando ya está anulada —ver la guarda de
        // `OperacionServicio::setOrdenServicio()`—. Soltándolas antes de marcarla, la orden
        // todavía estaría emitida y la guarda tumbaría la propia anulación.
        $orden->setEstadoOs(EstadoOrdenServicioEnum::CANCELADA);

        // El `foreach` sobre una copia: `setOrdenServicio(null)` toca la colección que se está
        // recorriendo, y modificar mientras se itera se salta elementos.
        foreach ($orden->getOperacionServicios()->toArray() as $servicio) {
            $servicio->setOrdenServicio(null);
        }

        $sucesora?->setReemplazaA($orden);
    }

    /**
     * Las reglas de coherencia de una Orden, en un solo sitio.
     *
     * Vivían repartidas: `conflictoSeleccion` en el navegador y las guardas en los setters de
     * la entidad. Dos pestañas abiertas —o cualquier otro consumidor de la API— podían armar
     * algo que la vista impedía. Aquí se comprueban **antes** de tocar nada, así el error llega
     * entero y no a mitad de una orden ya medio construida.
     *
     * @param list<OperacionServicio> $servicios
     *
     * @throws DomainException con el motivo, que API Platform devuelve como 422
     */
    public function validar(array $servicios): void
    {
        if ($servicios === []) {
            throw new DomainException('Elige al menos un servicio para la orden.');
        }

        foreach ($servicios as $servicio) {
            if (($motivo = $servicio->motivoNoComprable()) !== null) {
                throw new DomainException(sprintf(
                    '«%s» %s, y no puede entrar en una Orden de Servicio.',
                    $servicio->getDescripcionServicio(),
                    $motivo
                ));
            }

            if ($servicio->getOrdenServicio() !== null) {
                throw new DomainException(sprintf(
                    '«%s» ya está en la orden %s. Anúlala antes de volver a pedirlo.',
                    $servicio->getDescripcionServicio(),
                    $servicio->getOrdenServicio()->getNumeroOs()
                ));
            }
        }

        // Un solo expediente: una OS es una solicitud sobre un grupo, y un proveedor no puede
        // firmar un documento que mezcla dos viajes.
        $expedientes = [];
        $compradores = [];
        foreach ($servicios as $servicio) {
            $expedientes[(string) $servicio->getFile()?->getId()] = $servicio->getFile()?->getNombreGrupo() ?? '—';
            $compradores[(string) $servicio->getCompradorEfectivoMaestroId()] = $servicio->getCompradorEfectivoNombre() ?? '—';
        }

        if (count($expedientes) > 1) {
            throw new DomainException(sprintf(
                'Los servicios son de expedientes distintos (%s). Una orden es sobre uno solo.',
                implode(', ', $expedientes)
            ));
        }

        // Por ID, no por texto: «Futurismo» tecleado y «Futurismo Jonathan» del catálogo
        // serían dos órdenes distintas aunque se lean igual.
        if (count($compradores) > 1) {
            throw new DomainException(sprintf(
                'Los servicios tienen compradores distintos (%s). La orden va dirigida a uno.',
                implode(', ', $compradores)
            ));
        }
    }

    /**
     * ¿Se puede pasar de un estado al otro?
     *
     * La regla corta: **un borrador se compone, una emitida ya es un documento**. Volver a
     * borrador desde emitida reescribiría algo que el proveedor ya tiene, y una anulada no
     * revive: para eso está reemitir.
     */
    public function validarTransicion(EstadoOrdenServicioEnum $desde, EstadoOrdenServicioEnum $hasta): void
    {
        if ($desde === $hasta) {
            return;
        }

        if ($desde === EstadoOrdenServicioEnum::CANCELADA) {
            throw new DomainException('Una orden anulada no vuelve atrás: emite una nueva que la reemplace.');
        }

        if ($hasta === EstadoOrdenServicioEnum::BORRADOR) {
            throw new DomainException('Una orden ya emitida no vuelve a borrador: el proveedor ya la tiene. Anúlala y emite otra.');
        }
    }
}