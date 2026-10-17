<?php

declare(strict_types=1);

namespace App\Agent\Skill;

use App\Agent\Access\ActorInterface;

/**
 * Skill que sólo funciona dentro de un CONTEXTO concreto: la conversación de una reserva, un hilo
 * de chat (16/10/2026).
 *
 * `anotar_peticion` y `confirmar_hora` escriben sobre la reserva de la conversación;
 * `anotar_pedido_cotizacion`, sobre el hilo. Se ofrecían también al equipo (`MENSAJES_SHOW`) en el
 * panel, en Alexa y en su hilo de avisos, donde no hay ni una cosa ni otra: el modelo las elegía,
 * fallaban siempre y gastaba una vuelta. Misma regla que {@see SkillConmutableInterface}: **lo que
 * no se puede usar ni se le menciona al modelo**.
 *
 * Es opcional. ⚠️ Como aquélla, filtra el catálogo, no la ejecución: `ejecutar()` sigue comprobando
 * lo suyo.
 */
interface SkillConContextoInterface
{
    /** ¿Tiene este actor el contexto que la skill necesita? `false` la quita de SU catálogo. */
    public function disponiblePara(ActorInterface $actor): bool;
}
