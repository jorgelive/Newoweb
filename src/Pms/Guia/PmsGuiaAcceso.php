<?php

declare(strict_types=1);

namespace App\Pms\Guia;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsEventoEstadoPago;
use App\Pms\Enum\PmsGuiaVisibilidad;

/**
 * Qué puede ver el portador de un enlace de guía, y desde cuándo.
 *
 * Fuente ÚNICA de verdad de la regla. Antes vivía en
 * GuiaHelperResponseTrait::calcularAcceso() —un método privado de un trait de
 * controller que devolvía `array{status, authorized, unlock_at}`— y el
 * navegador la volvía a aplicar por su cuenta en tres sitios
 * (showPendingWarning, WifiCardWidget.isLocked y el `includes('*')` de
 * copiarAlPortapapeles). Con dos implementaciones de la misma regla, cualquier
 * desajuste se salda filtrando datos o bloqueando de más.
 *
 * Ventana horaria: desde 30 h antes de `inicio` hasta el final del día de
 * `fin` —ver `HORAS_ANTICIPACION` para el porqué del 30— y desde el 08/09/2026 se compara
 * en la zona del ESTABLECIMIENTO, no en la del servidor. Las fechas del evento
 * se guardan en hora de pared del alojamiento, así que compararlas contra el
 * reloj de la máquina sólo acertaba mientras los dos compartieran huso.
 * `PmsEstablecimiento::zonaHoraria()` ya participa. Ver docs/PmsGuiaHuesped.md §3.
 */
final readonly class PmsGuiaAcceso
{
    /**
     * Ventana de cortesía antes del check-in en la que se liberan los códigos.
     *
     * ⚠️ **Es 30 y no 24, y va atada a `recordatorio_llegada`.** Hasta el 11/09/2026 era 24, y
     * el recordatorio del día anterior sale a `start −1800` —30 h antes, las 08:00 para una
     * entrada a las 14:00— prometiendo «instrucciones para el recojo de llaves» y «clave de
     * WiFi». Las dos fichas son `solo-ventana`, así que durante seis horas el mensaje mandaba
     * al huésped a un candado, justo el día en que más las busca.
     *
     * Se abrió la ventana y no se atrasó el mensaje por decisión del dueño: las 08:00 del día
     * antes es buena hora para escribir, y los códigos son fijos por unidad
     * (`PmsGuiaContexto`), no por reserva — seis horas antes no enseñan nada que el huésped
     * anterior no conozca ya. Lo que protege la ventana es que un código no circule semanas
     * antes de la llegada, y eso sigue igual.
     *
     * **Si se mueve la regla del recordatorio, se mueve esto** (y al revés): el recordatorio
     * tiene que salir con la ventana ya abierta. Viven en sitios distintos —esto es código, la
     * regla es una fila de `msg_rule`— y por eso se dice aquí.
     */
    private const HORAS_ANTICIPACION = 30;

    public function __construct(
        public PmsGuiaAccesoEstado $estado,
        /** Momento en que se abren los ítems `Llegada`; solo se rellena en estado Pendiente. */
        public ?\DateTimeImmutable $liberaEn = null,
    ) {
    }

    /** Catálogo público: no hay estancia detrás del enlace. */
    public static function publico(): self
    {
        return new self(PmsGuiaAccesoEstado::Publico);
    }

    /**
     * Evalúa una estancia concreta.
     *
     * Se comprueban TRES cosas antes de la ventana temporal, no una. La versión
     * anterior solo miraba el estado de pago, y por eso una estancia CANCELADA
     * pero pagada conservaba `estadoPago = pago-total` y seguía entregando los
     * códigos reales de puerta y caja. Además GuiaHelperClientController hacía
     * `find($id)` a pelo, saltándose el filtro de PmsReserva::getEventosActivosGuia()
     * y sirviendo hasta estancias marcadas explícitamente como "no mostrar en guía".
     */
    public static function paraEvento(?PmsEventoCalendario $evento, ?\DateTimeImmutable $ahora = null): self
    {
        if (null === $evento) {
            return self::publico();
        }

        // ⚠️ El «ahora» se pide en la zona del ESTABLECIMIENTO, no en la del servidor.
        //
        // Las fechas del evento se guardan en hora de pared del alojamiento (el estándar del
        // proyecto), así que compararlas contra el reloj de la máquina sólo funciona mientras los
        // dos compartan huso. Aquí se decide si se entregan los códigos de puerta y de caja: con
        // un alojamiento en otro país, la ventana se abriría o se cerraría con horas de error y
        // nada fallaría de forma visible.
        $zona = $evento->zonaHoraria();
        $ahora ??= new \DateTimeImmutable('now', $zona);

        // 1 y 2. Estancia muerta (cancelada, bloqueo interno) o excluida a mano
        // por el operador: deja de ser cliente DE ESTA UNIDAD —puede tener otras
        // estancias vigentes— y se trata como visitante, que es el nivel más
        // restrictivo posible.
        //
        // Es una RED DE SEGURIDAD, no el filtro real: PmsReserva::getEventosActivosGuia()
        // ya descarta estas estancias antes, así que ni aparecen en la lista de
        // la reserva ni se puede abrir su guía (PmsGuiaHuespedProvider devuelve
        // 404). Esto solo cubre a quien llame a paraEvento() con un evento crudo.
        if (!in_array($evento->getEstado()?->getId(), PmsEventoEstado::MOSTRAR_EVENTO_GUIA, true)
            || $evento->isGuiaDisabled()
        ) {
            return self::publico();
        }

        // 3. Solo el dinero recibido confirma la estancia.
        if (!in_array($evento->getEstadoPago()?->getId(), PmsEventoEstadoPago::ESTADOS_PAGO_CONFIABLES, true)) {
            return new self(PmsGuiaAccesoEstado::SinPago);
        }

        $inicio = $evento->getInicio();
        $fin = $evento->getFin();

        // Sin fechas no se puede situar la ventana. Se degrada a SinPago (el
        // nivel inmediatamente inferior) en vez de arriesgar a abrir códigos.
        if (null === $inicio || null === $fin) {
            return new self(PmsGuiaAccesoEstado::SinPago);
        }

        // Doctrine devuelve las fechas etiquetadas con el huso por defecto, sean cuales sean los
        // dígitos que se guardaron. Como lo guardado es hora de pared del alojamiento, hay que
        // volver a leerlas EN SU ZONA antes de compararlas — si no, el instante que representan es
        // el de otro sitio.
        $inicioLocal = new \DateTimeImmutable($inicio->format('Y-m-d H:i:s'), $zona);
        $finLocal = new \DateTimeImmutable($fin->format('Y-m-d H:i:s'), $zona);

        $liberaEn = $inicioLocal->modify(sprintf('-%d hours', self::HORAS_ANTICIPACION));

        if ($ahora < $liberaEn) {
            return new self(PmsGuiaAccesoEstado::Pendiente, $liberaEn);
        }

        // El check-out cierra al final del día: el huésped sigue necesitando el
        // código de la caja para devolver las llaves la mañana que se va.
        if ($ahora > $finLocal->setTime(23, 59, 59)) {
            return new self(PmsGuiaAccesoEstado::Expirada);
        }

        return new self(PmsGuiaAccesoEstado::Activa);
    }

    /**
     * LA MATRIZ. Cruzar visibilidad del ítem con estado de la estancia ocurre
     * aquí y en ningún otro sitio.
     *
     * Los cuatro niveles son una escalera estrictamente creciente
     * (`Publico ⊂ Cliente ⊂ ClienteConfirmado ⊂ SoloVentana`), y cada peldaño
     * añade UNA condición: tener localizador, haber pagado, estar en ventana.
     *
     * | estado \ visibilidad | Publico | Cliente | ClienteConfirmado | SoloVentana |
     * |----------------------|---------|---------|-------------------|-------------|
     * | Publico              |    ✓    |    ✗    |         ✗         |      ✗      |
     * | SinPago              |    ✓    |    ✓    |         🔒        |      🔒     |
     * | Pendiente            |    ✓    |    ✓    |         ✓         |      🔒     |
     * | Activa               |    ✓    |    ✓    |         ✓         |      ✓      |
     * | Expirada             |    ✓    |    ✓    |         ✓         |      🔒     |
     *
     * (🔒 = no se puede ver, pero el ítem SE ANUNCIA con candado; ver
     * debeAnunciarBloqueo().)
     *
     * `SinPago` conserva el nivel `Cliente` a propósito: cómo llegar y las
     * normas de la casa no son secretos, y quien tiene el localizador lo sacó
     * de su correo. Lo que el pago protege empieza en `ClienteConfirmado`.
     *
     * `ClienteConfirmado` sigue abierto en `Expirada`: lo que se pagó, pagado
     * está. Lo que caduca con el check-out es la ventana, o sea `SoloVentana`.
     */
    public function permite(PmsGuiaVisibilidad $visibilidad): bool
    {
        return match ($visibilidad) {
            PmsGuiaVisibilidad::Publico           => true,
            PmsGuiaVisibilidad::Cliente           => $this->estado->esHuesped(),
            PmsGuiaVisibilidad::ClienteConfirmado => $this->estado->pagoConfirmado(),
            PmsGuiaVisibilidad::SoloVentana       => PmsGuiaAccesoEstado::Activa === $this->estado,
        };
    }

    /**
     * Un ítem que no se puede ver, ¿se anuncia con candado o desaparece?
     *
     * A un huésped identificado SIEMPRE se le anuncia: tiene el localizador, no
     * es un desconocido, y esconderle el ítem le hace creer que la guía no trae
     * esa información en vez de entender qué le falta para verla. El título y la
     * barra se ven; el cuerpo viaja con el mensaje de bloqueo y el valor real
     * nunca sale del servidor. Cada estado dice algo distinto (PmsGuiaMensajes):
     *
     * - `SinPago`   → "[Disponible al confirmar]". Acción en su mano.
     * - `Pendiente` → "[Disponible el 12/08 a las 15:00]". Hay fecha.
     * - `Expirada`  → "[Reserva finalizada]". Hubo algo ahí y caducó.
     *
     * La única excepción es el visitante sin estancia —o con la estancia
     * cancelada, que cae en el mismo estado—: a él no se le insinúa siquiera la
     * estructura de la guía privada.
     *
     * Esto decide la visibilidad del ÍTEM, no la del DATO. De eso se ocupa
     * `permite()`, arriba.
     */
    public function debeAnunciarBloqueo(PmsGuiaVisibilidad $visibilidad): bool
    {
        return $this->estado->esHuesped() && $visibilidad->exigeCondicionExtra();
    }

    /** Atajo de legibilidad: la ventana está abierta y los códigos son reales. */
    public function estaAbierto(): bool
    {
        return PmsGuiaAccesoEstado::Activa === $this->estado;
    }
}
