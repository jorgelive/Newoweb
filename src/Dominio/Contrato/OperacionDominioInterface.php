<?php

declare(strict_types=1);

namespace App\Dominio\Contrato;

/**
 * Una operación del dominio compartido: una regla escrita en TypeScript que PHP puede invocar.
 *
 * ── Una operación = una clase, y ningún registro ────────────────────────────
 * Añadir una operación es crear una clase que implemente esto. No hay lista que mantener, ni
 * `match` que ampliar, ni configuración que tocar — mismo criterio que las skills del agente y los
 * resolvedores de cobro. Si algún día hace falta editar un archivo compartido para añadir una, la
 * costura está mal.
 *
 * ⚠️ **Quien la implementa es el DOMINIO, no el núcleo.** `EjecutorDeDominio` es infraestructura,
 * como el `EntityManager`: lo inyectan las clases de `src/Cotizacion/`, `src/Travel/`… y nunca el
 * núcleo de `src/Agent/`, `src/Exchange/` o `src/Message/`. Ver `docs/PlanProcesamientoCompartido.md` §7.
 */
interface OperacionDominioInterface
{
    /**
     * La ruta del `.cli.ts`, relativa a `dominio/`.
     *
     * Ejemplo: `cotizacion/itinerario.cli.ts`.
     */
    public function puntoDeEntrada(): string;

    /**
     * El contrato que esta operación espera, p. ej. `itinerario@1`.
     *
     * ⚠️ Viaja en cada petición y el módulo lo compara. Sin esto, un campo renombrado en PHP
     * llegaría como `undefined` al cálculo y el resultado saldría mal **sin un solo error**.
     * Se versiona **por operación**: una versión global se subiría cada semana y dejaría de
     * significar algo.
     */
    public function contrato(): string;
}
