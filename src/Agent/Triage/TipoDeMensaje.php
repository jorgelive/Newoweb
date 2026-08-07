<?php

declare(strict_types=1);

namespace App\Agent\Triage;

/**
 * Qué clase de mensaje acaba de llegar.
 *
 * Son las tres cosas que un huésped hace en un chat de reserva, y cada una cuesta un dinero
 * distinto de atender:
 *
 * - **Conversación** — «hola», «gracias», «qué tal». No hay nada que consultar. Hoy esto paga
 *   el catálogo entero de skills para acabar diciendo «hola» de vuelta.
 * - **Petición** — quiere saber o quiere que pase algo. Aquí sí hay que trabajar.
 * - **Emergencia** — hay alguien en peligro, o algo que no puede esperar a mañana. No se
 *   negocia con el modelo: se avisa al equipo.
 *
 * Y un cuarto valor que no es una clase de mensaje sino una **confesión**:
 *
 * - **Indeterminado** — el triaje no pudo decidir (sin motor, respuesta vacía, JSON raro). No
 *   es «ninguna de las anteriores»: es «no lo sé», y lleva al camino largo de siempre. Es lo
 *   que permite que este mecanismo no pueda dejar a nadie sin respuesta: cuando falla, el
 *   agente se comporta exactamente como antes de que existiera.
 */
enum TipoDeMensaje: string
{
    case Conversacion = 'conversacion';
    case Peticion = 'peticion';
    case Emergencia = 'emergencia';
    case Indeterminado = 'indeterminado';

    /**
     * Lo que el modelo puede contestar, sin el valor de confesión.
     *
     * `indeterminado` NO se le ofrece a propósito: teniendo la salida fácil disponible, un
     * clasificador la usa para todo lo que le da pereza, y el triaje deja de ahorrar nada. Que
     * no sepa decidir tiene que notarse como un fallo, no como una opción legítima.
     *
     * @return list<string>
     */
    public static function opciones(): array
    {
        return [self::Conversacion->value, self::Peticion->value, self::Emergencia->value];
    }
}
