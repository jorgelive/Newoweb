<?php

declare(strict_types=1);

namespace App\Exchange\Service\Contract;

/**
 * El ítem de cola apunta a una reserva concreta del canal.
 *
 * No todos lo hacen: una cola de tarifas empuja precios por unidad y fecha, y no hay reserva a la
 * que apuntar. Por eso esto **no** está en `ExchangeQueueItemInterface` —obligaría a inventar un
 * `getTargetBookId()` a colas que no tienen ninguno— sino en un contrato aparte que implementa
 * quien sí lo tiene.
 *
 * ⚠️ **El identificador es opaco para el núcleo.** Se transporta, no se interpreta: quién sabe
 * qué significa ese string es el canal que lo emitió. Ver la regla de dominios y contratos en
 * `CLAUDE.md`.
 *
 * Nació de una entrada de la baseline de PHPStan: dos estrategias de mapeo llamaban a
 * `getTargetBookId()` sobre `ExchangeQueueItemInterface`, que no lo declara. Funcionaba porque el
 * lote siempre traía la clase concreta — y habría dejado de funcionar en cuanto llegara otra.
 */
interface TargetBookAwareInterface
{
    /**
     * Identificador de la reserva en el canal, tal cual lo dio el canal.
     *
     * `null` si el ítem todavía no lo tiene resuelto.
     */
    public function getTargetBookId(): ?string;
}
