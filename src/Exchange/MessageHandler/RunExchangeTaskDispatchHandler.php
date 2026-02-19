<?php


declare(strict_types=1);

namespace App\Exchange\MessageHandler;

use App\Exchange\Dispatch\RunExchangeTaskDispatch;

// ✅ Importamos el Dispatch
use App\Exchange\Service\Common\ExchangeTaskLocator;
use App\Exchange\Service\Engine\ExchangeOrchestrator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Maneja la orden de despacho recibida desde el Listener.
 * Agrupa los IDs por credenciales y ejecuta el Orquestador.
 */
#[AsMessageHandler]
final readonly class RunExchangeTaskDispatchHandler
{
    public function __construct(
        private ExchangeOrchestrator $orchestrator,
        private ExchangeTaskLocator  $taskLocator
    )
    {
    }

    public function __invoke(RunExchangeTaskDispatch $dispatch): void
    {
        $taskName = $dispatch->taskName;
        $ids = $dispatch->ids;

        if (empty($ids)) {
            return;
        }

        // 1. Obtener la Tarea y su Repositorio
        // (Asumimos que la Tarea expone el repositorio o el Provider lo hace)
        $task = $this->taskLocator->get($taskName);

        // 2. Agrupación Inteligente (Pre-Sorting)
        // El repositorio se encarga de la conversión Binario <-> Texto internamente.
        // Retorna: ['uuid_string' => ['config_id' => 'hex', 'endpoint_id' => 'hex']]
        $metadata = $task->getGroupingMetadata($ids);

        if (empty($metadata)) {
            return;
        }

        // 3. Organizar lotes homogéneos (Mismo Config + Mismo Endpoint)
        $batches = [];
        foreach ($metadata as $idStr => $info) {
            $groupKey = $info['config_id'] . '_' . $info['endpoint_id'];
            $batches[$groupKey][] = $idStr;
        }

        // 4. Ejecución Orquestada
        foreach ($batches as $groupKey => $batchIds) {
            // El Orquestador recibe IDs específicos para no procesar nada extra.
            $this->orchestrator->run(
                taskName: $taskName,
                requestedLimit : count($batchIds),
                specificIds: $batchIds // Pasamos los IDs filtrados
            );
        }
    }
}