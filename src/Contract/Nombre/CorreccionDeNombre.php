<?php

declare(strict_types=1);

namespace App\Contract\Nombre;

/**
 * El nombre de alguien cambió en su ficha de origen, y hay copias por ahí que no se han enterado.
 *
 * ### Por qué existe esto y no una llamada directa
 *
 * El nombre de un huésped se COPIA a otras filas —el título del calendario, un contacto del
 * maestro— porque leerlo en vivo cada vez costaría un JOIN en sitios donde no compensa. Copiar
 * está bien; lo que faltaba es qué pasa cuando el original cambia **después**.
 *
 * Y cambia: un canal manda «uylenbroeck / robin» —el par cruzado y en minúsculas— y un corrector
 * asíncrono lo endereza dos segundos más tarde. Todo lo que copió en esos dos segundos se queda
 * con la versión mala **para siempre**, sin un error en ningún log. Pasó con el título del
 * calendario y con el enlace de pago el 12/09/2026.
 *
 * ⚠️ **El origen NO puede conocer a sus copias.** Que `src/Pms/` llamara a Finanzas y al maestro
 * de contactos sería exactamente el acoplamiento que este proyecto no admite: incorporar un
 * consumidor nuevo tiene que ser **crear una clase**, no tocar el emisor. Por eso esto viaja como
 * un aviso que cada módulo recoge si le incumbe ({@see CopiaDelNombre}).
 *
 * ⚠️ **`origenTipo` es opaco.** `pms_reserva` se transporta, no se interpreta: quien sabe lo que
 * significa es el dominio que lo emitió y el que decide si le interesa es cada copia.
 */
final readonly class CorreccionDeNombre
{
    public function __construct(
        /** Qué clase de ficha cambió. Opaco: `pms_reserva`, y mañana lo que sea. */
        public string $origenTipo,
        /** Su identificador, en texto. Opaco igual. */
        public string $origenId,
        public string $nombreAntes,
        public string $apellidoAntes,
        public string $nombreAhora,
        public string $apellidoAhora,
    ) {}

    /** El par de antes, junto y limpio: es contra esto contra lo que se reconoce una copia nuestra. */
    public function completoAntes(): string
    {
        return trim(trim($this->nombreAntes) . ' ' . trim($this->apellidoAntes));
    }

    public function completoAhora(): string
    {
        return trim(trim($this->nombreAhora) . ' ' . trim($this->apellidoAhora));
    }

    /**
     * ¿Hay algo que propagar?
     *
     * Un cambio que deja el nombre vacío no se propaga: borraría copias buenas a cambio de nada.
     * Y si el par no cambió, no hay aviso que dar.
     */
    public function valeLaPena(): bool
    {
        return $this->completoAhora() !== '' && $this->completoAhora() !== $this->completoAntes();
    }
}
