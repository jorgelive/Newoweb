<?php

declare(strict_types=1);

namespace App\Message\Service\Conversacion;

use App\Message\Contract\ConversacionEnlaceInterface;
use App\Message\Contract\ProveedorDeEnlacesInterface;
use App\Message\Entity\MessageConversation;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Todos los asuntos de un hilo, vengan del dominio que vengan.
 *
 * Sustituye a `MessageConversation::getEnlaces()`, que recorría una colección tipada al PMS.
 * Vivir aquí y no en la entidad es además lo correcto por sí solo: **una entidad no consulta**,
 * y para juntar lo de varios dominios hay que preguntarle a cada uno.
 *
 * Se autolocaliza con `#[AutowireIterator]`, el patrón dominante del repo —`SkillRegistry`,
 * `IntentRouter`, `FinOrigenCobroRegistry`—: un dominio nuevo se enchufa solo, sin tocar esto
 * ni el registro.
 */
final readonly class EnlacesDeConversacion
{
    /** @param iterable<ProveedorDeEnlacesInterface> $proveedores */
    public function __construct(
        #[AutowireIterator('app.conversacion.enlaces')]
        private iterable $proveedores,
    ) {
    }

    /**
     * @return list<ConversacionEnlaceInterface>
     */
    public function de(MessageConversation $conversacion): array
    {
        $enlaces = [];

        foreach ($this->proveedores as $proveedor) {
            foreach ($proveedor->paraConversacion($conversacion) as $enlace) {
                $enlaces[] = $enlace;
            }
        }

        return $enlaces;
    }

    /**
     * ¿Este hilo tiene algún asunto de un negocio concreto?
     *
     * El negocio es una cadena opaca para el núcleo: se compara, no se interpreta.
     */
    public function tieneNegocio(MessageConversation $conversacion, string $negocio): bool
    {
        foreach ($this->proveedores as $proveedor) {
            if ($proveedor->getNegocio() === $negocio && $proveedor->paraConversacion($conversacion) !== []) {
                return true;
            }
        }

        return false;
    }
}
