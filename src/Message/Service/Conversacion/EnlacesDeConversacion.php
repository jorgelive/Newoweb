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
     * El hilo por el que se ATIENDE un asunto, sin pasar por ninguna identidad de persona.
     *
     * ── El camino duradero ──────────────────────────────────────────────────
     * Quien llega con la dirección de un asunto —el `bookId` de Beds24— necesita saber en qué
     * hilo aterriza. Resolverlo por identidad no aguanta: un `bookId` es de la ESTANCIA, no de
     * nadie. Hay 46 repartidos en 38 hilos y una misma persona acumula siete, así que como
     * identificador de persona es sencillamente falso.
     *
     * Aquí la cadena es `asunto → enlace titular → hilo`, y sobrevive a que la persona cambie
     * de número, tenga dos, o no tenga ninguno.
     *
     * `null` mientras el asunto no tenga hilo — que es lo que pasa la primera vez, antes de que
     * el sincronizador lo cuelgue. Ahí manda la identidad, que es para lo que sirve: descubrir
     * a la persona. Después, manda esto.
     */
    public function hiloTitularDe(string $contextType, string $contextId): ?MessageConversation
    {
        foreach ($this->proveedores as $proveedor) {
            $enlace = $proveedor->titularDeAsunto($contextType, $contextId);

            if ($enlace !== null) {
                return $enlace->getConversacion();
            }
        }

        return null;
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
     * Mueve el papel de TITULAR de un asunto a este hilo.
     *
     * ── Por qué no basta con poner el flag ──────────────────────────────────
     * Un asunto con **dos titulares** programaría su agenda dos veces —la bienvenida, el
     * recordatorio de saldo y el check-out, duplicados—, que es exactamente lo que el papel vino
     * a evitar. Así que promover uno exige degradar al otro, y eso hay que hacerlo mirando
     * TODOS los hilos del asunto, no sólo éste: por eso vive aquí y no en la entidad.
     *
     * Idempotente: si ya era el titular, no pasa nada.
     *
     * @return bool `false` si este hilo no tiene ese asunto colgado — no se inventa el enlace,
     *              porque un titular sin enlace es un asunto atendido desde una conversación que
     *              no lo tiene.
     */
    public function cambiarTitular(MessageConversation $hilo, string $contextType, string $contextId): bool
    {
        $nuevo = null;

        foreach ($this->proveedores as $proveedor) {
            $nuevo = $proveedor->enlaceDeAsunto($hilo, $contextType, $contextId);

            if ($nuevo !== null) {
                break;
            }
        }

        if ($nuevo === null) {
            return false;
        }

        // Primero se degrada al anterior. Al revés habría un instante con dos titulares, y si
        // algo fallara en medio se quedaría así — con la agenda duplicada, que es el daño.
        $anterior = $this->hiloTitularDe($contextType, $contextId);

        if ($anterior !== null && $anterior !== $hilo) {
            foreach ($this->proveedores as $proveedor) {
                $proveedor->enlaceDeAsunto($anterior, $contextType, $contextId)?->marcarTitular(false);
            }
        }

        $nuevo->marcarTitular(true);

        return true;
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
