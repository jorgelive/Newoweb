<?php

declare(strict_types=1);

namespace App\Message\Contract;

use App\Message\Entity\MessageConversation;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Cada dominio dice qué ASUNTOS suyos cuelgan de una conversación.
 *
 * ## Qué problema cierra
 *
 * `MessageConversation` importaba `PmsConversacionEnlace` y tenía una colección tipada a él:
 *
 * ```php
 * use App\Pms\Entity\PmsConversacionEnlace;          // ← el núcleo importaba el PMS
 * #[ORM\OneToMany(targetEntity: PmsConversacionEnlace::class)]
 * private Collection $enlacesPms;                     // ← una colección POR DOMINIO
 * ```
 *
 * Con eso, añadir Travel costaba **cuatro cosas**: la entidad, otra colección, otro `use` en el
 * núcleo y otra línea dentro de `getEnlaces()`. Y un cliente de hotel que además compra tours
 * multiplicaba el problema. Es justo lo que `CLAUDE.md` dice que no debe pasar: *«incorporar un
 * dominio nuevo tiene que ser crear una clase que implemente el contrato, y nada más»*.
 *
 * ## Por qué un proveedor y no una tabla genérica
 *
 * Porque una tabla común convertiría `reserva_id` en un `context_id` de texto, y ahí se pierde
 * una garantía que hoy está puesta a mano: esa clave foránea **no lleva `CASCADE` a propósito**
 * —«borrar una reserva no puede llevarse por delante el hilo de mensajes con esa persona»—.
 * Cada dominio con su tabla conserva su integridad referencial y sus reglas.
 *
 * Y no mueve un solo dato: el acoplamiento estaba en la colección, no en el esquema.
 *
 * ## El contrato
 *
 * Devuelve {@see ConversacionEnlaceInterface}, que ya dice todo lo que el núcleo necesita
 * —vínculo, momento, hitos, procedencia— **sin que sepa de qué dominio viene**.
 */
#[AutoconfigureTag('app.conversacion.enlaces')]
interface ProveedorDeEnlacesInterface
{
    /**
     * El identificador del negocio: `pms`, `travel`. **Opaco para el núcleo**, que lo
     * transporta y no lo interpreta.
     */
    public function getNegocio(): string;

    /**
     * Los asuntos de este dominio que cuelgan del hilo.
     *
     * Vacío es una respuesta normal y frecuente: la mayoría de conversaciones sólo tienen
     * asuntos de un negocio.
     *
     * @return list<ConversacionEnlaceInterface>
     */
    public function paraConversacion(MessageConversation $conversacion): array;
}
