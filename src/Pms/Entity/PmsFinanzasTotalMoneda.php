<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Maestro\MaestroMoneda;
use App\Pms\Repository\PmsFinanzasTotalMonedaRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Lo que se debe y lo que se ha cobrado en UNA moneda de UNA ficha financiera.
 *
 * ── Por qué existe esta tabla ───────────────────────────────────────────────
 * Hasta el 16/08/2026 la ficha guardaba dos escalares —`total_cargos` y `total_pagos`— con todo
 * convertido a una moneda contable. De ahí salía una familia entera de fallos: un registro sin
 * tipo de cambio **desaparecía del total sin avisar**, y el saldo en la otra moneda era un
 * número que nadie había pactado.
 *
 * La regla nueva es que **no se convierte**: soles con soles, dólares con dólares. Una fila por
 * moneda con movimiento. Si una reserva se cobró y se pagó en dólares, tiene una fila; si además
 * hubo una ampliación en soles, tiene dos, y ninguna miente sobre la otra.
 *
 * ── Por qué es una tabla y no una columna JSON ──────────────────────────────
 * `PmsEstadoPagoEventosService` decide en SQL crudo si una estancia pasa a `pago-total`, y esa
 * decisión encadena hasta los códigos de acceso de la guía del huésped. Necesita preguntar
 * «¿queda alguna moneda debiendo?» con un `NOT EXISTS`, y sobre un JSON eso no se consulta.
 *
 * ── ⚠️ La escribe SQL crudo. No la gestiona el ORM ──────────────────────────
 * La llena `PmsInformacionFinancieraRecalculoService` con un `DELETE` + `INSERT … SELECT` en
 * `postFlush`, igual que hacía con los escalares. Esta entidad existe para **leerla**: en lote
 * (el calendario carga cabeceras de un mes entero) y desde SQL. Nadie debe hacerle `persist()`.
 *
 * Y **no tiene lado inverso en `PmsInformacionFinanciera`, a propósito**. Dos motivos, los dos
 * medidos:
 *
 *   1. Una colección ya inicializada quedaría **rancia** en la misma petición que la escribió:
 *      el rollup no pasa por el `UnitOfWork` y `$em->refresh()` **no refresca colecciones**. Para
 *      leer el saldo de una ficha que se acaba de tocar está el value object, que suma desde
 *      `$info->getCargos()`/`getPagos()` y por tanto siempre ve lo último.
 *   2. `PmsEventosSpaCalendarProvider::fetchFinanzas()` carga las cabeceras en lote. Una
 *      colección lazy ahí son 50-80 consultas por mes pintado.
 *
 * ── Identidad compuesta y borrado ───────────────────────────────────────────
 * La clave es (ficha, moneda). El `ON DELETE CASCADE` de la FK **no es opcional**: sin él,
 * borrar una reserva revienta con un 1451 de MySQL — es el mismo bug que documenta el `OneToOne`
 * de `PmsInformacionFinanciera` («ninguna reserva se podía borrar desde el panel»), y esta tabla
 * no entra en ninguna cascada del ORM porque no está mapeada desde el otro lado.
 */
#[ORM\Entity(repositoryClass: PmsFinanzasTotalMonedaRepository::class)]
#[ORM\Table(name: 'pms_finanzas_total_moneda')]
class PmsFinanzasTotalMoneda
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: PmsInformacionFinanciera::class)]
    #[ORM\JoinColumn(name: 'informacion_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?PmsInformacionFinanciera $informacion = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'moneda_id', referencedColumnName: 'id', nullable: false)]
    private ?MaestroMoneda $moneda = null;

    /** Suma de los cargos de esta moneda. Sin convertir: es el importe tal como se pactó. */
    #[ORM\Column(name: 'total_cargos', type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $totalCargos = '0.00';

    /**
     * Suma de los cobros imputados a esta moneda.
     *
     * «Imputados» y no «recibidos»: un cobro puede declarar que salda la deuda de OTRA moneda
     * (ver `PmsPagoFinanciero::$monedaSaldada`), y entonces suma aquí convertido con su propio
     * tipo de cambio. Es la única conversión que sobrevive en todo el módulo, y sólo porque en
     * ese cobro el dinero **sí** cruzó de una moneda a otra.
     */
    #[ORM\Column(name: 'total_pagos', type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $totalPagos = '0.00';

    public function getInformacion(): ?PmsInformacionFinanciera { return $this->informacion; }

    public function getMoneda(): ?MaestroMoneda { return $this->moneda; }

    public function getMonedaId(): string { return (string) $this->moneda?->getId(); }

    public function getTotalCargos(): string { return $this->totalCargos; }

    public function getTotalPagos(): string { return $this->totalPagos; }

    /** Lo que queda por cobrar en esta moneda. Negativo = el huésped tiene saldo a favor. */
    public function getSaldo(): string
    {
        return number_format((float) $this->totalCargos - (float) $this->totalPagos, 2, '.', '');
    }
}
