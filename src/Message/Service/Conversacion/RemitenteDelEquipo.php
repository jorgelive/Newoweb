<?php

declare(strict_types=1);

namespace App\Message\Service\Conversacion;

use App\Entity\User;
use App\Message\Entity\Message;
use App\Repository\UserRepository;
use App\Service\Phone\PhoneSanitizer;

/**
 * ¿Quién del equipo escribió ESTE mensaje? La única puerta para reconocerlo (16/10/2026).
 *
 * Que un mensaje sea del equipo le da al agente los roles de esa persona —con escritura:
 * `registrar_pago`, `cambiar_codigo_caja`…—, así que el número de teléfono es una credencial.
 * Lo es sólo cuando el número lo VERIFICA alguien: en WhatsApp lo pone Meta dentro de un webhook
 * firmado (`MetaWebhookController::firmaValida()`) y no se puede suplantar.
 *
 * ### Las dos condiciones, y por qué faltaban
 *
 * 1. **El canal es WhatsApp de Meta.** Se miraba en cualquier canal. En un hilo de OTA el teléfono
 *    de la cabecera es el que el huésped TECLEÓ en el formulario de la reserva
 *    (`MessageConversationFactory`): con el móvil de un operador ahí —por malicia, o porque el
 *    operador metió una reserva directa con el suyo—, quien escribía por el chat de Booking o
 *    Airbnb recibía los roles de ese operador.
 * 2. **El número es el del REMITENTE** (`Message::getTelefonoOrigen()`), no el de la cabecera
 *    (`guestPhone`). Un hilo puede tener varios números —un acompañante, uno que entró por
 *    localizador— y la cabecera no cambia con ellos: se evaluaba el teléfono de otra persona.
 *
 * Sin `telefono_origen` (lo estampa `WhatsappMetaReceivePersister` desde el 12/10/2026) no hay
 * remitente verificado y se trata como huésped. Caer a la cabecera reabriría el agujero 2.
 *
 * Lo usan las cuatro copias que había: el actor (`AiConversationProcessor::generar()`), el «leído»
 * del equipo (`marcarLeidaSiEsDelEquipo()`) y las dos esperas de ráfaga
 * (`MessageAutoResponderListener`, `ProcessInboundIntentDispatchHandler`). Ver docs/Agent.md.
 */
final readonly class RemitenteDelEquipo
{
    /** El único canal que verifica el número del remitente. */
    public const string CANAL_VERIFICADO = 'whatsapp_meta';

    public function __construct(
        private UserRepository $usuarios,
        private PhoneSanitizer $telefonos,
    ) {}

    /**
     * La persona del equipo que escribió el mensaje, o `null` si no es del equipo o no se puede
     * saber. `findByTelefono()` devuelve también a los deshabilitados: retirarles la escritura es
     * cosa de `AgentActorFactory::delEquipoPorChat()`.
     */
    public function de(Message $mensaje): ?User
    {
        if ($mensaje->getChannel()?->getId() !== self::CANAL_VERIFICADO) {
            return null;
        }

        $telefono = $mensaje->getTelefonoOrigen();

        return $telefono === null ? null : $this->usuarios->findByTelefono($telefono, $this->telefonos);
    }

    public function esDelEquipo(Message $mensaje): bool
    {
        return $this->de($mensaje) !== null;
    }
}
