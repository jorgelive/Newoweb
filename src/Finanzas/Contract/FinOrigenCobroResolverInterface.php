<?php

declare(strict_types=1);

namespace App\Finanzas\Contract;

use App\Finanzas\Dto\FinOrigenCobroDto;
use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Enum\FinOrigenCobro;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Uid\Uuid;

/**
 * Puente entre Finanzas y el módulo dueño del documento que se cobra.
 *
 * **La dirección de la dependencia importa**: Finanzas declara este contrato y cada
 * módulo lo implementa dentro de su propio namespace (el del PMS vive en
 * `App\Pms\Finanzas\PmsReservaOrigenCobroResolver`). Así Finanzas no conoce a nadie, y
 * añadir cobros al módulo de tours el día de mañana es escribir UNA clase — sin tocar la
 * entidad compartida, sin migración y sin editar este archivo.
 *
 * El `#[AutoconfigureTag]` va en la interfaz, no en cada implementación: basta con
 * implementarla para que el registry la recoja.
 *
 * @see \App\Finanzas\Service\FinOrigenCobroRegistry
 */
#[AutoconfigureTag('finanzas.origen_cobro_resolver')]
interface FinOrigenCobroResolverInterface
{
    /** Tipo de origen que atiende esta implementación. Uno por módulo. */
    public function soporta(): FinOrigenCobro;

    /**
     * Lee el documento y devuelve su foto de cobro.
     *
     * Devuelve `null` si el documento ya no existe: un enlace pagado sobrevive al borrado
     * de su reserva (ver la nota de `FinOrigenCobro`) y la UI tiene que poder pintarlo
     * igual. Nunca lances por "no encontrado".
     *
     * ── `$moneda`, y por qué es opcional ────────────────────────────────────────
     * Un documento puede deber en más de una moneda —una reserva con la estancia en dólares y
     * una ampliación en soles—, y **una pasarela cobra un enlace en UNA divisa**. Con `$moneda`
     * se pide la foto de esa deuda concreta; sin ella, la de mayor saldo.
     *
     * Es opcional a propósito: los llamantes que ya existían siguen funcionando sin tocarlos, y
     * un módulo que sólo maneje una moneda puede ignorar el parámetro por completo.
     */
    public function resolver(Uuid $origenId, ?string $moneda = null): ?FinOrigenCobroDto;

    /**
     * Imputa en el módulo el dinero ya cobrado por la pasarela.
     *
     * Se llama UNA sola vez, desde `FinEnlacePagoService::confirmarPago()`, dentro de la
     * transacción del webhook y sólo cuando la firma ya se validó. La implementación es
     * responsable de la idempotencia si el webhook llegase repetido (Finanzas ya frena el
     * caso normal comprobando que el enlace no esté PAGADO, pero no confíes sólo en eso).
     *
     * Devuelve el id del registro creado en el módulo (p. ej. el `PmsPagoFinanciero`) para
     * poder navegar del enlace al asiento, o `null` si el módulo no crea nada.
     */
    public function registrarCobro(FinEnlacePago $enlace): ?Uuid;

    /**
     * Deshace en el módulo el dinero que la pasarela acaba de devolver.
     *
     * Se llama desde `FinEnlacePagoService::reembolsar()` y **sólo después** de que la
     * pasarela confirme la devolución: primero se mueve el dinero, luego se escribe. Al revés
     * se estaría anotando una devolución que puede no haber ocurrido.
     *
     * ── Lo que se espera de la implementación ───────────────────────────────────
     * **No borrar el asiento.** El cobro existió y sigue siendo parte de lo que le pasó a ese
     * documento; lo que cambia es que ya no vale dinero. El PMS pone su `PmsPagoFinanciero` a
     * cero y le añade una nota — la fila se queda, el saldo vuelve solo por el recálculo de
     * coherencia, y el histórico no pierde nada.
     *
     * Es la misma regla que gobierna el resto del módulo: no se borra, se marca.
     *
     * Debe ser **idempotente**: un enlace ya `REEMBOLSADO` no vuelve a pasar por aquí (Finanzas
     * lo frena antes), pero no confíes sólo en eso.
     *
     * `$motivo` es el texto que escribió el operador, para que quede en el asiento del módulo.
     * A la pasarela no viaja: allí `reason` es un enum cerrado de tres valores.
     */
    public function registrarDevolucion(FinEnlacePago $enlace, string $motivo): void;
}
