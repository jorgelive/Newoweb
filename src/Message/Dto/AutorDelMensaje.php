<?php

declare(strict_types=1);

namespace App\Message\Dto;

/**
 * Quién habló en un saliente: el TIPO decide, el nombre sólo se pinta.
 *
 * El agente distinguía al equipo de sí mismo leyendo la etiqueta de texto («Agente»,
 * «Escrito en …»): cambiar una palabra le habría hecho perder la distinción sin un solo error. Con
 * el tipo aparte, el texto es libre y la decisión no depende de él.
 *
 * `nombre` lo pone quien lo sabe —el usuario del panel, o el canal de integración que trajo el
 * mensaje escrito en la plataforma (`escrito_en` en la metadata)—; el núcleo no traduce ningún
 * identificador de dominio para obtenerlo.
 */
final readonly class AutorDelMensaje
{
    public const string PERSONA = 'persona';
    /** Del equipo, de antes de guardar quién: no se sabe qué persona fue. */
    public const string EQUIPO = 'equipo';
    /** Del equipo, escrito FUERA del sistema (la app o la extranet de una plataforma). */
    public const string EXTERNO = 'externo';
    public const string AGENTE = 'agente';
    public const string AUTOMATICO = 'automatico';
    public const string SISTEMA = 'sistema';

    private function __construct(public string $tipo, public ?string $nombre = null) {}

    public static function persona(string $nombre): self { return new self(self::PERSONA, $nombre); }
    public static function equipo(): self { return new self(self::EQUIPO); }
    public static function externo(?string $donde): self { return new self(self::EXTERNO, $donde); }
    public static function agente(): self { return new self(self::AGENTE); }
    public static function automatico(): self { return new self(self::AUTOMATICO); }
    public static function sistema(): self { return new self(self::SISTEMA); }

    /** Lo que pinta el chat bajo la burbuja. */
    public function etiqueta(): string
    {
        return match ($this->tipo) {
            self::PERSONA => (string) $this->nombre,
            self::EXTERNO => $this->nombre !== null && $this->nombre !== '' ? 'Escrito en ' . $this->nombre : 'Escrito fuera del sistema',
            self::AGENTE => 'Agente',
            self::AUTOMATICO => 'Automático',
            self::SISTEMA => 'Sistema',
            default => 'Equipo',
        };
    }

    /** ¿Lo escribió alguien del alojamiento (y no el agente ni un automático)? */
    public function esDelEquipo(): bool
    {
        return in_array($this->tipo, [self::PERSONA, self::EQUIPO, self::EXTERNO], true);
    }
}
