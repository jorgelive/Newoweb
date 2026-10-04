<?php

declare(strict_types=1);

namespace App\Message\Contract;

use App\Message\Dto\CandidatoDeAsunto;
use App\Message\Entity\MessageConversation;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * «¿De qué asunto es esta persona?»: los asuntos de un dominio que podrían colgar de un hilo.
 *
 * Lo pide el chat cuando alguien escribe desde un número que no casa con nada, o cuando se le
 * quiere colgar otro asunto a un hilo (el acompañante que escribe por la reserva de otro). El
 * chat no sabe qué es una reserva ni un viaje: cada dominio propone los suyos con su etiqueta, y
 * añadir uno es implementar esta interfaz.
 *
 * El enlace lo crea después `AperturaDeHilo::enlazarAcompanante()` con el `contextType` y el
 * `contextId` que se devuelvan aquí.
 */
#[AutoconfigureTag('app.message.candidatos_asunto')]
interface CandidatosDeAsuntoInterface
{
    /**
     * Sin búsqueda, los asuntos «de estos días», primero los más probables para este hilo. Con
     * búsqueda, lo que case con el texto.
     *
     * @return list<CandidatoDeAsunto>
     */
    public function candidatos(MessageConversation $hilo, ?string $busqueda): array;
}
