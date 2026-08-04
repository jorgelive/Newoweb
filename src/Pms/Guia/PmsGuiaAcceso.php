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
 * Ventana horaria: se hereda la semántica que ya había —24 h antes de
 * `inicio`, hasta el final del día de `fin`— comparando en la zona del
 * servidor. Las fechas del evento se guardan en hora local del establecimiento,
 * así que mientras servidor y alojamiento compartan zona (America/Lima) el
 * cálculo es correcto; PmsEstablecimiento::getTimezone() existe pero todavía no
 * participa. Ver docs/PmsGuiaHuesped.md §3.
 */
final readonly class PmsGuiaAcceso
{
    /** Ventana de cortesía antes del check-in en la que se liberan los códigos. */
    private const HORAS_ANTICIPACION = 24;

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

        $ahora ??= new \DateTimeImmutable();

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

        $liberaEn = \DateTimeImmutable::createFromInterface($inicio)
            ->modify(sprintf('-%d hours', self::HORAS_ANTICIPACION));

        if ($ahora < $liberaEn) {
            return new self(PmsGuiaAccesoEstado::Pendiente, $liberaEn);
        }

        // El check-out cierra al final del día: el huésped sigue necesitando el
        // código de la caja para devolver las llaves la mañana que se va.
        if ($ahora > \DateTimeImmutable::createFromInterface($fin)->setTime(23, 59, 59)) {
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
