<?php

declare(strict_types=1);

namespace App\Agent\Router;

use App\Agent\Action\BotActionHandlerInterface;
use App\Agent\Entity\AutoResponderRule;
use App\Agent\Service\AiConversationProcessor;
use App\Message\Entity\Message;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

/**
 * Enruta los mensajes entrantes hacia el motor determinista (AutoResponder)
 * o hacia el procesador de Inteligencia Artificial, basándose en la intención (Intent).
 *
 * Ver docs/Mensajeria.md §9.
 */
final readonly class IntentRouter
{
    /**
     * @param iterable<BotActionHandlerInterface> $actionHandlers
     */
    public function __construct(
        private EntityManagerInterface $em,
        #[AutowireIterator('app.bot_action_handler')]
        private iterable $actionHandlers,
        private AiConversationProcessor $aiProcessor,
        private LoggerInterface $logger,
    ) {}

    /**
     * Evalúa la intención del mensaje y decide qué sistema debe procesarlo.
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

        // 1. RUTA DE INTELIGENCIA ARTIFICIAL
        // Texto libre: no hay regla que casar, lo atiende el modelo (o nadie, si está
        // desactivado). El procesador decide si responde y deja el motivo en el intent.
        if ($category === 'free_text') {
            $this->marcarResuelto($message, $this->aiProcessor->process($message));
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
                        // 🔥 El intent se marca resuelto PASE LO QUE PASE con el envío.
                        // Antes `execute()` iba antes del marcado y sin try: si el enqueuer
                        // rechazaba el mensaje (plantilla sin ID oficial fuera de la ventana
                        // de 24 h, por ejemplo) la excepción se llevaba por delante el
                        // marcado, y el mensaje se quedaba `resolved: false` para siempre —
                        // re-despachándose en cada postUpdate. En producción había 7 casos así.
                        try {
                            $handler->execute($message, $rule->getActionParameters() ?? []);
                            $this->marcarResuelto($message, 'regla:' . $rule->getTriggerValue());
                        } catch (Throwable $e) {
                            $this->logger->error(sprintf(
                                'Agent: la acción "%s" de la regla "%s" falló para el mensaje %s: %s',
                                $rule->getActionType(),
                                $rule->getTriggerValue(),
                                $message->getId(),
                                $e->getMessage()
                            ));
                            $this->marcarResuelto($message, 'error_accion');
                        }

                        return;
                    }
                }

                $this->logger->warning(sprintf(
                    'Agent: la regla "%s" pide la acción "%s", que no tiene handler registrado.',
                    $rule->getTriggerValue(),
                    $rule->getActionType()
                ));
                $this->marcarResuelto($message, 'sin_handler');

                return;
            }
        }

        // 3. NADA QUE HACER — pero hay que dejar constancia.
        // Sin esto el intent quedaba `resolved: false` indefinidamente y CADA postUpdate del
        // mensaje (marcarlo leído, una reacción, un cambio de estado) volvía a despachar y a
        // consultar la BD. Con el procesador de IA enchufado sería peor: volvería a pagar el
        // modelo en cada actualización.
        $this->marcarResuelto($message, 'sin_regla');
    }

    /**
     * Cierra la intención y anota POR QUÉ. El motivo es lo que permite distinguir después
     * «lo contestó el bot» de «nadie lo atendió», que en el JSON antiguo eran indistinguibles:
     * todo acababa en `resolved: true` sin más.
     */
    private function marcarResuelto(Message $message, string $motivo): void
    {
        $intent = $message->getInboundIntent() ?? [];
        $intent['resolved'] = true;
        $intent['resolution'] = $motivo;
        $intent['resolved_at'] = (new \DateTimeImmutable())->format('Y-m-d\TH:i:sP');

        $message->setInboundIntent($intent);

        $this->em->flush();
    }
}
