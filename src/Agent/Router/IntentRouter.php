<?php

declare(strict_types=1);

namespace App\Agent\Router;

use App\Agent\Action\BotActionHandlerInterface;
use App\Agent\Entity\AutoResponderRule;
use App\Agent\Service\AiConversationProcessor;
use App\Message\Entity\Message;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Enruta los mensajes entrantes hacia el motor determinista (AutoResponder)
 * o hacia el orquestador de Inteligencia Artificial, basándose en la intención (Intent).
 */
final readonly class IntentRouter
{
    /**
     * @param iterable<BotActionHandlerInterface> $actionHandlers
     */
    public function __construct(
        private EntityManagerInterface $em,
        #[AutowireIterator(BotActionHandlerInterface::class)]
        private iterable $actionHandlers,
        //private AiConversationProcessor $aiProcessor
    ) {}

    /**
     * Evalúa la intención del mensaje y decide qué sistema debe procesarlo.
     * Evita consultas a la base de datos si el mensaje es explícitamente texto libre.
     *
     * @param Message $message El mensaje entrante a procesar.
     */
    public function routeIntent(Message $message): void
    {
        $intent = $message->getInboundIntent();
        if (!$intent) {
            return;
        }

        $category = $intent['category'] ?? null;
        $actionCode = $intent['action_code'] ?? null;

        // 1. RUTA DE INTELIGENCIA ARTIFICIAL (Cortocircuito rápido)
        // Si sabemos que es texto libre, evitamos golpear la base de datos buscando reglas.
        if ($category === 'free_text') {
            //$this->aiProcessor->process($message);
            $this->markAsResolved($message);
            return;
        }

        // 2. RUTA DETERMINISTA
        // Solo buscamos en la base de datos si es una acción de botón o una alerta del sistema.
        if (in_array($category, ['deterministic', 'system_alert'], true) && $actionCode) {
            $rule = $this->em->getRepository(AutoResponderRule::class)->findOneBy([
                'triggerValue' => $actionCode,
                'isActive' => true
            ]);

            if ($rule instanceof AutoResponderRule) {
                foreach ($this->actionHandlers as $handler) {
                    if ($handler->getActionKey() === $rule->getActionType()) {
                        $handler->execute($message, $rule->getActionParameters() ?? []);
                        $this->markAsResolved($message);
                        return;
                    }
                }
            }
        }
    }

    /**
     * Marca la intención del mensaje como resuelta para evitar reprocesamientos.
     */
    private function markAsResolved(Message $message): void
    {
        $intent = $message->getInboundIntent();
        $intent['resolved'] = true;
        $message->setInboundIntent($intent);

        $this->em->flush();
    }
}