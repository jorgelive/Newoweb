<?php

declare(strict_types=1);

namespace App\Agent\Action;

use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Entity\MessageTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

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

    public function execute(Message $inboundMessage, array $parameters): void
    {
        // 1. Extraemos los parámetros configurados en tu regla (EasyAdmin)
        $templateCode = $parameters['template_code'] ?? null;
        $forceChannelCode = $parameters['force_channel'] ?? null; // Ej: 'beds24'

        if (!$templateCode) {
            $this->logger->error("Bot: SendTemplateAction falló. No se definió 'template_code'.");
            return;
        }

        // 2. Buscamos la plantilla en la BD
        $template = $this->em->getRepository(MessageTemplate::class)->findOneBy([
            'code' => $templateCode,
            'isActive' => true
        ]);

        if (!$template) {
            $this->logger->warning("Bot: SendTemplateAction falló. La plantilla '{$templateCode}' no existe.");
            return;
        }

        $conversation = $inboundMessage->getConversation();

        // 3. 🧠 RESOLUCIÓN DEL CANAL DE SALIDA
        $targetChannel = null;
        $transientChannelIds = [];

        if ($forceChannelCode) {
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