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

        // 1. La estancia tiene que estar viva (ni cancelada ni bloqueo interno).
        if (!in_array($evento->getEstado()?->getId(), PmsEventoEstado::MOSTRAR_EVENTO_GUIA, true)) {
            return new self(PmsGuiaAccesoEstado::NoConfirmada);
        }

        // 2. El operador puede excluirla de la guía a mano.
        if ($evento->isGuiaDisabled()) {
            return new self(PmsGuiaAccesoEstado::NoConfirmada);
        }

        // 3. Solo el dinero recibido abre los códigos de acceso.
        if (!in_array($evento->getEstadoPago()?->getId(), PmsEventoEstadoPago::ESTADOS_PAGO_CONFIABLES, true)) {
            return new self(PmsGuiaAccesoEstado::NoConfirmada);
        }

        $inicio = $evento->getInicio();
        $fin = $evento->getFin();

        if (null === $inicio || null === $fin) {
            return new self(PmsGuiaAccesoEstado::NoConfirmada);
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
     * | estado \ visibilidad | Publico | Privado | Llegada |
     * |----------------------|---------|---------|---------|
     * | Publico              |    ✓    |    ✗    |    ✗    |
     * | NoConfirmada         |    ✓    |    ✓    |    ✗    |
     * | Pendiente            |    ✓    |    ✓    |    ✗    |
     * | Activa               |    ✓    |    ✓    |    ✓    |
     * | Expirada             |    ✓    |    ✓    |    ✗    |
     *
     * NoConfirmada conserva el acceso a lo `Privado` a propósito: cómo llegar y
     * las normas de la casa no son secretos, y quien tiene el localizador lo
     * sacó de su correo de confirmación. Lo que el pago protege son los códigos,
     * y esos son `Llegada`.
     */
    public function permite(PmsGuiaVisibilidad $visibilidad): bool
    {
        return match ($visibilidad) {
            PmsGuiaVisibilidad::Publico => true,
            PmsGuiaVisibilidad::Privado => $this->estado->esHuesped(),
            PmsGuiaVisibilidad::Llegada => PmsGuiaAccesoEstado::Activa === $this->estado,
        };
    }

    /**
     * Un ítem que no se puede ver todavía, ¿se anuncia bloqueado o desaparece?
     *
     * Solo se anuncia cuando la espera tiene fecha: en Pendiente el huésped ve
     * "[Disponible el 12/08 a las 15:00]" y entiende que el dato existe. En
     * Expirada o NoConfirmada no hay nada que prometer, así que el ítem se
     * omite del árbol en vez de dejar un candado permanente sin explicación.
     */
    public function debeAnunciarBloqueo(PmsGuiaVisibilidad $visibilidad): bool
    {
        return $visibilidad->esTemporal()
            && PmsGuiaAccesoEstado::Pendiente === $this->estado;
    }

    /** Atajo de legibilidad: la ventana está abierta y los códigos son reales. */
    public function estaAbierto(): bool
    {
        return PmsGuiaAccesoEstado::Activa === $this->estado;
    }
}
