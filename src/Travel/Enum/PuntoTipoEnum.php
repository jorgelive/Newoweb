<?php

declare(strict_types=1);

namespace App\Travel\Enum;

/**
 * Qué clase de sitio es un {@see \App\Travel\Entity\TravelPunto}.
 *
 * No tiene consecuencias de negocio: sirve para agrupar y buscar en un desplegable que va a
 * crecer, y para que el operador distinga de un vistazo «Ollantaytambo» estación de
 * «Ollantaytambo» pueblo. Si algún día una regla depende del tipo, que sea un método aquí y no
 * un `match` repartido.
 */
enum PuntoTipoEnum: string
{
    case HOTEL = 'hotel';
    case ESTACION_TREN = 'estacion_tren';
    case AEROPUERTO = 'aeropuerto';
    case TERMINAL_BUS = 'terminal_bus';
    case PLAZA = 'plaza';
    case OFICINA = 'oficina';
    case MUELLE = 'muelle';
    case OTRO = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::HOTEL         => 'Hotel / alojamiento',
            self::ESTACION_TREN => 'Estación de tren',
            self::AEROPUERTO    => 'Aeropuerto',
            self::TERMINAL_BUS  => 'Terminal terrestre',
            self::PLAZA         => 'Plaza / punto urbano',
            self::OFICINA       => 'Oficina',
            self::MUELLE        => 'Muelle / embarcadero',
            self::OTRO          => 'Otro',
        };
    }
}
