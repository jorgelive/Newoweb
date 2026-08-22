<?php

declare(strict_types=1);

namespace App\Travel\Service;

use App\Travel\Entity\TravelPunto;
use App\Travel\Enum\PuntoModoEnum;

/**
 * La respuesta a «¿dónde recojo y dónde dejo?» para UN servicio concreto.
 *
 * Es un objeto y no un `array{inicio: …, fin: …}` porque hay tres estados por extremo —resuelto,
 * «es el hotel del pasajero» y sin declarar— y un array los aplasta a «hay algo o no hay nada».
 * El segundo estado es el que importa: no es un hueco, es un dato que sólo se puede terminar de
 * resolver con un pasajero delante, y la orden tiene que poder decirlo con esas palabras en vez
 * de dejar el renglón en blanco.
 */
final readonly class PuntosResueltos
{
    public function __construct(
        public PuntoModoEnum $inicioModo,
        public ?TravelPunto $inicioPunto,
        public PuntoModoEnum $finModo,
        public ?TravelPunto $finPunto,
        /** ¿Este tipo de servicio siquiera tiene extremos? Un ticket no recoge a nadie. */
        public bool $aplica,
        /** El guiado tiene punto de presentación pero no de entrega — {@see PuntosDeServicio}. */
        public bool $tieneFin,
    ) {}

    public static function noAplica(): self
    {
        return new self(PuntoModoEnum::SIN_DEFINIR, null, PuntoModoEnum::SIN_DEFINIR, null, false, false);
    }

    /** ¿Se puede mandar la orden sin que nadie tenga que preguntar dónde es? */
    public function estaCompleto(): bool
    {
        if (!$this->aplica) {
            return true;
        }

        return $this->extremoCompleto($this->inicioModo, $this->inicioPunto)
            && (!$this->tieneFin || $this->extremoCompleto($this->finModo, $this->finPunto));
    }

    /**
     * `ALOJAMIENTO` cuenta como completo aquí a propósito: el catálogo ha dicho todo lo que
     * podía decir, y lo que falta lo pone la reserva. Marcarlo como incompleto llenaría el
     * informe de avisos que nadie puede resolver, y un informe así se deja de mirar.
     */
    private function extremoCompleto(PuntoModoEnum $modo, ?TravelPunto $punto): bool
    {
        return match ($modo) {
            PuntoModoEnum::SIN_DEFINIR => false,
            PuntoModoEnum::ALOJAMIENTO => true,
            PuntoModoEnum::FIJO => $punto !== null && $punto->estaCompleto(),
        };
    }

    /** @return list<string> Qué falta, en palabras, para el informe del comando. */
    public function faltantes(): array
    {
        if (!$this->aplica) {
            return [];
        }

        $faltan = [];

        if (!$this->extremoCompleto($this->inicioModo, $this->inicioPunto)) {
            $faltan[] = $this->inicioModo === PuntoModoEnum::SIN_DEFINIR
                ? 'inicio sin declarar'
                : 'punto de inicio incompleto';
        }

        if ($this->tieneFin && !$this->extremoCompleto($this->finModo, $this->finPunto)) {
            $faltan[] = $this->finModo === PuntoModoEnum::SIN_DEFINIR
                ? 'fin sin declarar'
                : 'punto de fin incompleto';
        }

        return $faltan;
    }
}
