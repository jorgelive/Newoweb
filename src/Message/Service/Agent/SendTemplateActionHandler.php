<?php

declare(strict_types=1);

namespace App\Message\Service\Agent;

use App\Agent\Action\BotActionHandlerInterface;
use App\Agent\Action\ParametrosDeAccion;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Entity\MessageTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * La acción «enviar una plantilla» de una regla de intención del agente.
 *
 * ### Por qué vive en mensajería y no en `src/Agent/`
 *
 * Porque lo que hace es **de mensajería de cabo a rabo**: busca una `MessageTemplate`, resuelve
 * el canal de salida y encola un `Message`. Del agente sólo toma el contrato que la hace
 * enchufable ({@see BotActionHandlerInterface}), igual que el PMS implementa
 * `IndiceDeTemasInterface` desde `src/Pms/Service/Agent/`.
 *
 * Estuvo en `src/Agent/Action/` hasta el 19/08/2026, y era lo que obligaba al agente a importar
 * tres entidades de mensajería. Ahora el agente se queda con el contrato —que ya no lleva
 * ninguna entidad dentro— y el trabajo vive donde están los datos.
 *
 * Se autolocaliza por `#[AutoconfigureTag]`: cambiar de sitio la clase no toca ningún registro.
 * ⚠️ Lo que NO puede cambiar es {@see self::getActionKey()}: `send_template` está guardado en
 * `agent_autoresponder_rule.action_type` y es lo que empareja la regla con esta clase.
 */
final readonly class SendTemplateActionHandler implements BotActionHandlerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {}

    public function getActionKey(): string
    {
        return 'send_template';
    }

    public function getActionLabel(): string
    {
        return 'Enviar Plantilla (Permite Forzar Canal)';
    }

    public function execute(string $mensajeEntranteId, ParametrosDeAccion $parametros): void
    {
        // 1. Extraemos los parámetros configurados en tu regla (EasyAdmin)
        $templateCode = $parametros->texto('template_code');
        $forceChannelCode = $parametros->texto('force_channel'); // Ej: 'beds24'

        if ($templateCode === null) {
            $this->logger->error("Bot: SendTemplateAction falló. No se definió 'template_code'.");
            return;
        }

        // El contrato trae el ID y no la entidad: esta acción ES de mensajería, así que buscarla
        // en su propio repositorio no es una fuga. Puede haber desaparecido entre el disparo de
        // la regla y esta ejecución, y entonces no hay nada que contestar.
        $inboundMessage = $this->em->getRepository(Message::class)->find($mensajeEntranteId);

        if (!$inboundMessage instanceof Message) {
            $this->logger->warning(
                "Bot: SendTemplateAction no encontró el mensaje entrante {$mensajeEntranteId}."
            );

            return;
        }

        // 2. Buscamos la plantilla en la BD
        $template = $this->em->getRepository(MessageTemplate::class)->findOneBy([
            'code' => $templateCode
        ]);

        if (!$template) {
            $this->logger->warning("Bot: SendTemplateAction falló. La plantilla '{$templateCode}' no existe.");
            return;
        }

        $conversation = $inboundMessage->getConversation();

        // 3. 🧠 RESOLUCIÓN DEL CANAL DE SALIDA
        $targetChannel = null;
        $transientChannelIds = [];

        if ($forceChannelCode !== null) {
            // Si la regla dice "forzar envío por Beds24", buscamos ese canal y lo imponemos
            $targetChannel = $this->em->getRepository(MessageChannel::class)->find($forceChannelCode);
            if ($targetChannel) {
                $transientChannelIds[] = $forceChannelCode;
            }
        }

        // Si no hay un canal forzado, o no se encontró en BD, respondemos por el mismo canal por donde nos hablaron
        if (!$targetChannel) {
            $targetChannel = $inboundMessage->getChannel();
            if ($targetChannel) {
                $transientChannelIds[] = (string) $targetChannel->getId();
            }
        }

        // 4. Instanciamos el nuevo mensaje de respuesta
        $outboundMessage = new Message();
        $outboundMessage->setConversation($conversation);
        $outboundMessage->setChannel($targetChannel);
        $outboundMessage->setTransientChannels($transientChannelIds); // 🔥 CRÍTICO para que los Enqueuers lo atrapen
        $outboundMessage->setTemplate($template);

        // Configuraciones vitales
        $outboundMessage->setDirection(Message::DIRECTION_OUTGOING);
        $outboundMessage->setSenderType(Message::SENDER_SYSTEM);
        $outboundMessage->setStatus(Message::STATUS_PENDING);

        // 5. Persistimos (El Router o el Worker hará el flush final)
        $this->em->persist($outboundMessage);

        $this->logger->info("Bot: Plantilla '{$templateCode}' encolada para la conv. {$conversation->getId()} vía canal " . ($targetChannel ? $targetChannel->getId() : 'desconocido'));
    }
}