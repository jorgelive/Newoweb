<?php

declare(strict_types=1);

namespace App\Finanzas\Contract;

use App\Finanzas\Dto\FinDeudaDeContacto;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * ¿Debe algo esta persona en algún módulo? (10/10/2026)
 *
 * Lo pregunta el «Cobro manual» de Finanzas antes de emitir: un cobro suelto no cuelga de ningún
 * documento, así que si lo que se cobra es en realidad la deuda de una reserva, el dinero no llega
 * a su cuenta y la persona puede acabar pagando dos veces. No se bloquea —el cobro suelto existe
 * para vender cualquier cosa a cualquiera—: se avisa.
 *
 * Finanzas no sabe qué es una reserva: cada módulo con deudas implementa esto y el contenedor lo
 * enchufa solo, como `FinOrigenCobroResolverInterface`.
 */
#[AutoconfigureTag('finanzas.deudas_de_contacto')]
interface FinDeudasDeContactoInterface
{
    /** @return list<FinDeudaDeContacto> Lo que debe quien tenga ese teléfono o ese correo. */
    public function deudasDe(?string $telefono, ?string $email): array;
}
