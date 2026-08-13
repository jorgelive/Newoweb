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
 * Es un recorte de catálogo, no un control de acceso: sirve para que a un pasajero de tours no
 * le aparezca `consultar_mi_reserva` entre las herramientas ofrecidas. Lo que de verdad impide
 * leer datos ajenos siguen siendo los roles y el contexto del actor.
 */
interface SkillDominioInterface
{
    /**
     * @return list<string> Vacío ⇒ transversal: vale para cualquier negocio.
     */
    public function dominios(): array;
}
