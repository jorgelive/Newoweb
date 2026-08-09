<?php

declare(strict_types=1);

namespace App\Finanzas\Contract;

use App\Finanzas\Dto\FinMovimientoDto;
use App\Finanzas\Dto\FinMovimientoFiltro;
use App\Finanzas\Enum\FinOrigenCobro;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Segundo puente entre Finanzas y los módulos: la vista de CAJA.
 *
 * Separado de `FinOrigenCobroResolverInterface` a propósito, aunque los implemente la
 * misma gente. Aquel contesta "¿cuánto debe este documento?" y sirve para cobrar; este
 * contesta "¿qué dinero ha entrado?" y sirve para mirar. Un módulo puede querer lo segundo
 * sin lo primero (algo que registra pagos pero no emite enlaces), y juntarlos obligaría a
 * implementar métodos vacíos.
 *
 * Lo implementa el módulo dueño, no Finanzas. El del PMS está en
 * `App\Pms\Finanzas\PmsPagoMovimientoProvider`.
 */
#[AutoconfigureTag('finanzas.movimiento_provider')]
interface FinMovimientoProviderInterface
{
    public function soporta(): FinOrigenCobro;

    /**
     * Cobros del módulo que cumplen el filtro, del más reciente al más antiguo.
     *
     * Debe respetar `$filtro->limite` por su cuenta: el registry no puede paginar a través
     * de módulos sin traérselo todo antes (ver la nota de `FinMovimientoRegistry::buscar()`).
     *
     * @return FinMovimientoDto[]
     */
    public function buscar(FinMovimientoFiltro $filtro): array;

    /**
     * Medios de cobro que este módulo conoce, para poblar el desplegable del filtro.
     *
     * Los declara el módulo y no un enum de Finanzas porque el vocabulario es suyo: el PMS
     * cobra en efectivo y por Yape, y un módulo de agencia podría cobrar por otras vías que
     * aquí no significan nada.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function mediosDisponibles(): array;
}
