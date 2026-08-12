<?php

declare(strict_types=1);

namespace App\Agent\Access;

use App\Entity\User;

/**
 * Quién pregunta.
 *
 * Es una interfaz y no sólo la clase concreta porque el origen se amplía: hoy panel y chat,
 * mañana un widget en la web del huésped o un endpoint de socio. Lo que no cambia es lo que
 * el resto del sistema necesita saber — qué roles trae y a qué contexto está atado.
 */
interface ActorInterface
{
    /** @return list<string> */
    public function roles(): array;

    /** `panel`, `whatsapp_meta`, `beds24`… De dónde llegó la pregunta. */
    public function origen(): string;

    /**
     * Contexto al que está atado, si lo hay.
     *
     * Para un huésped es la frontera de lo que puede consultar: sus skills lo usan en lugar
     * de aceptar un parámetro con el que apuntar a otra reserva.
     */
    public function contextoTipo(): ?string;

    public function contextoId(): ?string;

    public function esDelEquipo(): bool;

    /**
     * ¿Pregunta sin ser todavía cliente de nada?
     *
     * En la interfaz y no sólo en `AgentActor` por el mismo motivo que {@see self::usuario()}:
     * `PerfilConversacion::deActor()` recibe el contrato, no la clase concreta, y sin esto una
     * segunda implementación reventaría con un método indefinido.
     */
    public function esProspecto(): bool;

    /**
     * El usuario del equipo que está detrás, o `null` si quien pregunta es un huésped.
     *
     * Lo necesitan las skills que registran QUIÉN hizo algo, no sólo qué se hizo:
     * `registrar_pago` resuelve con esto el «me pagó a mí» del operador. Está en la interfaz
     * y no sólo en `AgentActor` porque una skill recibe el contrato, no la clase concreta, y
     * la alternativa era un `instanceof` en cada una.
     *
     * ⚠️ Es quien MANEJA el chat, que no siempre es quien hizo la acción del mundo real: el
     * cobrador de un pago sólo coincide con él si el operador dice explícitamente que cobró él.
     */
    public function usuario(): ?User;

    /** @param list<string> $roles Vacío ⇒ basta con ser un actor cualquiera. */
    public function tieneAlguno(array $roles): bool;

    /** Para los logs, sin volcar la entidad entera. */
    public function etiqueta(): string;
}
