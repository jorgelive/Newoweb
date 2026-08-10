<?php

declare(strict_types=1);

namespace App\Agent\Skill;

/**
 * Skill que puede estar APAGADA por configuración, no por permisos.
 *
 * Es opcional: una skill que no la implementa está siempre disponible, que es el caso normal.
 * La implementan las que dependen de algo que todavía no está listo —una pasarela en modo
 * test, una integración a medio contratar— y que por eso no deben ni ofrecerse.
 *
 * ### Por qué desaparecer y no devolver un error
 *
 * Es la misma regla que ya aplica {@see SkillRegistry::paraActor()} con los permisos: **lo que
 * no se puede usar ni se le menciona al modelo**. Una skill listada que siempre falla es peor
 * que no tenerla: el modelo la elige, gasta un turno y le cuenta al huésped una versión
 * inventada de por qué no pudo. Con el autorespondedor encendido, ese turno sale por WhatsApp.
 *
 * ⚠️ **Esto no es una comprobación de seguridad.** Filtra el catálogo, no la ejecución. Una
 * skill apagada que además escriba tiene que volver a comprobarlo en `ejecutar()`: nada impide
 * llamarla por su nombre desde `app:agent:skill` o desde un plan viejo de una conversación en
 * curso.
 */
interface SkillConmutableInterface
{
    /** ¿Se le ofrece al modelo? `false` la borra del catálogo, para todos los actores. */
    public function estaActiva(): bool;
}
