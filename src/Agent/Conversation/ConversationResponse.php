<?php

declare(strict_types=1);

namespace App\Agent\Conversation;

/**
 * Lo que devuelve un motor. El adaptador decide qué hacer: el panel puede mostrar un «no sé
 * hacer eso», y el chat del huésped prefiere callarse y dejar que conteste una persona.
 *
 * Por eso el motor **informa y no decide**.
 */
final readonly class ConversationResponse
{
    /**
     * @param list<string> $skillsUsadas
     */
    private function __construct(
        public ?string $texto,
        public array $skillsUsadas,
        public string $motivo,
    ) {}

    /** @param list<string> $skills */
    public static function ok(string $texto, array $skills): self
    {
        return new self($texto, $skills, 'ok');
    }

    /**
     * Respondió, pero **sin usar ninguna skill**.
     *
     * Es la señal de «esto cae fuera de lo que sé hacer»: o era pura cortesía, o falta la
     * capacidad. **El motivo no distingue las dos cosas**, y por eso ningún adaptador debería
     * tirar el texto por venir marcado así: el chat del huésped lo hizo un tiempo y acabó
     * contestando «un compañero te responderá en breve» a un «hola». Ver
     * `AiConversationProcessor::generar()`.
     *
     * Sirve para MEDIR, no para censurar: los `sin_skill` acumulados en el log son la lista de
     * skills que faltan por construir, ordenada por frecuencia real.
     */
    public static function sinSkill(?string $texto): self
    {
        return new self($texto, [], 'sin_skill');
    }

    public static function sinPermisos(): self
    {
        return new self(null, [], 'sin_permisos');
    }

    /** Los clasificadores del proveedor declinaron la petición. */
    public static function rechazada(): self
    {
        return new self(null, [], 'rechazado');
    }

    public static function vacia(): self
    {
        return new self(null, [], 'sin_respuesta');
    }

    public static function noDisponible(): self
    {
        return new self(null, [], 'motor_no_disponible');
    }

    public function tieneTexto(): bool
    {
        return $this->texto !== null && trim($this->texto) !== '';
    }

    public function usoSkills(): bool
    {
        return $this->skillsUsadas !== [];
    }
}
