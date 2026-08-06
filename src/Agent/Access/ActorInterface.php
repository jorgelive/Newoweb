<?php

declare(strict_types=1);

namespace App\Agent\Access;

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

    /** @param list<string> $roles Vacío ⇒ basta con ser un actor cualquiera. */
    public function tieneAlguno(array $roles): bool;

    /** Para los logs, sin volcar la entidad entera. */
    public function etiqueta(): string;
}
