<?php

declare(strict_types=1);

namespace App\Message\Service\Conversacion;

use App\Message\Contract\ProveedorDeContextoInterface;
use App\Message\Entity\MessageConversation;
use App\Message\Factory\MessageConversationFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Abre el hilo de un asunto, o devuelve el que ya había.
 *
 * ── Por qué hacía falta ─────────────────────────────────────────────────────
 * «Escríbele a este cliente» no existía como operación. La conversación sólo nacía de rebote
 * —al guardar la reserva, al guardar el expediente, o cuando el cliente escribía primero— y si
 * se cortaba, el operador se quedaba mirando un chat que no se podía abrir. Para los
 * proveedores no nacía nunca, porque nadie guarda una organización «con motivo».
 *
 * ── Idempotente, y por eso no hay riesgo en llamarlo ────────────────────────
 * Por dentro es {@see MessageConversationFactory::upsertFromContext()}, que resuelve por enlace
 * titular, por identidad y por la llave legada **antes** de crear nada. Llamarlo sobre un asunto
 * que ya tiene hilo devuelve ese hilo, no uno nuevo. Es lo que permite que el panel lo llame sin
 * comprobar antes.
 *
 * Y por eso mismo hace lo que hace el camino normal: siembra identidades, crea el enlace del
 * asunto vía {@see \App\Message\Contract\SincronizadorDeEnlaceInterface} y vuelca el
 * `contextData`. Un hilo abierto por aquí es indistinguible de uno nacido solo — que es justo lo
 * que no conseguía el alta a mano de EasyAdmin.
 *
 * ── ⚠️ Sin identificadores NO se abre ───────────────────────────────────────
 * Un hilo cuyo dueño no se puede reconocer por ningún teléfono, correo ni `bookId` no resuelve
 * nada: no recibe, no sale, y ensucia la bandeja con una fila que nadie puede cerrar. Es la
 * misma guarda que `CotizacionFileConversacionListener` ya aplicaba en silencio; aquí se lanza,
 * porque aquí hay alguien delante esperando una respuesta.
 */
final readonly class AperturaDeHilo
{
    /** @param iterable<ProveedorDeContextoInterface> $proveedores */
    public function __construct(
        private MessageConversationFactory $factory,
        private LoggerInterface $logger,
        #[AutowireIterator('app.message.proveedor_contexto')]
        private iterable $proveedores,
    ) {
    }

    /**
     * El hilo de este asunto, creándolo si no lo había.
     *
     * @throws RuntimeException con el motivo, para que el panel pueda enseñarlo tal cual
     */
    public function abrir(string $contextType, string $contextId): MessageConversation
    {
        $contexto = null;

        foreach ($this->proveedores as $proveedor) {
            if ($proveedor->supports($contextType)) {
                $contexto = $proveedor->para($contextId);
                break;
            }
        }

        if ($contexto === null) {
            // Se distinguen los dos motivos: que el dominio no sepa abrir hilos es cosa nuestra
            // —falta implementar el contrato— y que el asunto no exista es cosa de quien pidió.
            throw new RuntimeException($this->soportado($contextType)
                ? 'Ese asunto ya no existe, así que no hay a quién escribirle.'
                : sprintf('Todavía no se pueden abrir conversaciones de «%s».', $contextType));
        }

        if ($contexto->getIdentificadores() === []) {
            throw new RuntimeException(
                'No hay ni teléfono ni correo registrados, así que el hilo no resolvería a nadie. '
                . 'Añade un dato de contacto y vuelve a intentarlo.'
            );
        }

        $hilo = $this->factory->upsertFromContext($contexto, flush: true);

        $this->logger->info('Hilo abierto a petición desde el panel.', [
            'contextType' => $contextType,
            'contextId' => $contextId,
            'conversacion' => (string) $hilo->getId(),
        ]);

        return $hilo;
    }

    private function soportado(string $contextType): bool
    {
        foreach ($this->proveedores as $proveedor) {
            if ($proveedor->supports($contextType)) {
                return true;
            }
        }

        return false;
    }
}
