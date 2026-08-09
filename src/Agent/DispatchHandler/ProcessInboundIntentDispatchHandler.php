<?php

declare(strict_types=1);

namespace App\Agent\DispatchHandler;

use App\Agent\Dispatch\ProcessInboundIntentDispatch;
use App\Agent\Router\IntentRouter;
use App\Agent\Triage\PreRouterRafaga;
use App\Message\Entity\Message;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
final readonly class ProcessInboundIntentDispatchHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private IntentRouter $intentRouter,
        private PreRouterRafaga $preRouter,
        private MessageBusInterface $bus,
        #[Autowire('%env(int:AGENT_IA_ESPERA_RAFAGA)%')]
        private int $esperaRafaga,
    ) {}

    public function __invoke(ProcessInboundIntentDispatch $dispatch): void
    {
        $msg = $this->em->getRepository(Message::class)->find($dispatch->messageId);

        // 1. Doble validación de seguridad
        if (!$msg instanceof Message || !$msg->getInboundIntent() || $msg->getInboundIntent()['resolved']) {
            return;
        }

        // 🔥 FIX PARA EL WORKER ASÍNCRONO:
        // Limpiamos la caché del UnitOfWork para esta entidad y traemos los datos reales
        // que el Webhook acaba de guardar (incluyendo la apertura de la ventana de 24h).
        $conversation = $msg->getConversation();
        if ($conversation !== null) {
            $this->em->refresh($conversation);
        }

        // PRE-ROUTER. ¿Ha terminado de escribir, o sigue?
        //
        // Se consulta AQUÍ y no en el listener porque hace una petición de red y el
        // listener corre dentro de un flush de Doctrine. Si dice que espere, el trabajo se
        // reencola con la ventana completa; `yaEsperado` corta el bucle para que no pueda
        // reencolarse dos veces.
        //
        // Si el huésped escribió mientras tanto, este trabajo morirá igual en el guardia de
        // ráfaga de AiConversationProcessor: son dos redes distintas y la segunda sigue
        // puesta. Ver PreRouterRafaga.
        if (!$dispatch->yaEsperado && $this->preRouter->debeEsperar($msg)) {
            $this->bus->dispatch(
                new ProcessInboundIntentDispatch($dispatch->messageId, yaEsperado: true),
                [new DelayStamp($this->esperaRafaga * 1000)]
            );

            return;
        }

        // 2. Le pasamos el control a tu nuevo Router Determinista/IA
        $this->intentRouter->routeIntent($msg);
    }
}