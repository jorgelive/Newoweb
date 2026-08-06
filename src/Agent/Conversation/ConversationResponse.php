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
     * capacidad. El texto se entrega igualmente para que el adaptador elija — pero marcado,
     * porque un `sin_skill` en el chat de un huésped es una respuesta improvisada, y ahí
     * improvisar es justo lo que no se quiere.
     *
     * Los `sin_skill` acumulados en el log son, además, la lista de skills que faltan por
     * construir, ordenada por frecuencia real.
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
