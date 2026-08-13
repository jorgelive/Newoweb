<?php

declare(strict_types=1);

namespace App\Agent\Skill;

/**
 * A qué NEGOCIO pertenece una skill. Opcional: la skill que no la implemente es transversal.
 *
 * Mismo patrón que {@see SkillConmutableInterface} — se implementa sólo cuando aporta algo, y
 * `SkillRegistry` comprueba con `instanceof`. Así ninguna skill existente tiene que cambiar
 * para que el mecanismo exista.
 *
 * ── Un solo eje: el negocio ─────────────────────────────────────────────────
 * `hotelero`, `turistico`… y **nunca** el momento (venta / operación). Se estudió etiquetarlas
 * por el par negocio×momento y se descartó con un caso concreto: `consultar_disponibilidad`
 * sería «venta hotelera», y entonces un huésped alojado que quiere ampliar su estadía —que está
 * en operación— se quedaría fuera. Es exactamente el agujero que se acaba de cerrar dándole el
 * rol de huésped a esa skill; etiquetar por momento lo habría reabierto por la puerta de atrás.
 *
 * Quién puede usar qué en cada momento ya lo deciden los roles y `PerfilConversacion`. Esto
 * responde a otra pregunta: **de qué negocio es esta herramienta**.
 *
 * ── No es un permiso ────────────────────────────────────────────────────────
 * Es un recorte de catálogo, no un control de acceso. Lo que de verdad impide leer datos ajenos
 * siguen siendo los roles y el contexto del actor.
 *
 * ── ⚠️ HOY NO RECORTA NADA, y hay que saberlo ───────────────────────────────
 * `AgentActorFactory` puebla `ActorInterface::dominios()` con **los negocios vendibles ∪ el del
 * contexto**, y el alojamiento es vendible, así que todo actor real lleva `hotelero` y la
 * intersección pasa siempre. El día que exista turismo, un pasajero llevará
 * `['hotelero','turistico']` y **seguirá recibiendo el catálogo hotelero de operación** —
 * `consultar_mi_reserva`, `consultar_wifi`…— que morirá con «esta conversación no está asociada
 * a ninguna reserva».
 *
 * La causa es que las skills se etiquetan sólo por NEGOCIO, sin el eje venta/operación —
 * decisión deliberada, porque etiquetarlas por momento habría dejado fuera al huésped que
 * quiere ampliar—. La tensión se resuelve cuando los asuntos de cada negocio sean entidades
 * propias y `dominios()` se derive de ellas, no de «lo vendible». **Hasta entonces este filtro
 * es una red que todavía no atrapa nada**, y conviene no construirle encima dando por hecho lo
 * contrario. Ver docs/Mensajeria.md §19.14.
 */
interface SkillDominioInterface
{
    /**
     * @return list<string> Vacío ⇒ transversal: vale para cualquier negocio.
     */
    public function dominios(): array;
}
