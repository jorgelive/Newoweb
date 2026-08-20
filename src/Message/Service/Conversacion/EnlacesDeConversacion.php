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
     * Los canales por los que se puede alcanzar un asunto. **Vacío = sin acotar.**
     *
     * Con `$asuntoType`/`$asuntoId` responde por ESE asunto; sin ellos devuelve la UNIÓN de
     * los de todo el hilo, que es lo correcto cuando no se sabe a cuál va el mensaje: acotar
     * de más callaría un canal legítimo del otro asunto, y eso no se descubre —nadie echa de
     * menos un canal que nunca se le ofreció—.
     *
     * ⚠️ La unión es deliberadamente PERMISIVA. El corte fino lo hace quien sí sabe a qué
     * asunto va el mensaje: `MessageDispatcher` pasa el asunto del `Message` cuando lo lleva.
     *
     * @return list<string>
     */
    public function canalesPosibles(
        MessageConversation $conversacion,
        ?string $asuntoType = null,
        ?string $asuntoId = null,
    ): array {
        $union = [];

        foreach ($this->de($conversacion) as $enlace) {
            if ($asuntoType !== null && $asuntoId !== null
                && ($enlace->getContextType() !== $asuntoType || $enlace->getContextId() !== $asuntoId)) {
                continue;
            }

            $canales = $enlace->canalesPosibles();

            // Un solo asunto sin acotar abre el hilo entero: no hay nada que intersecar.
            if ($canales === []) {
                return [];
            }

            foreach ($canales as $canal) {
                $union[$canal] = true;
            }
        }

        return array_keys($union);
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
