<?php

declare(strict_types=1);

namespace App\Agent\Service;

use App\Agent\Conversation\FalloDelMotor;
use App\Agent\Conversation\AgentEngineRegistry;
use App\Agent\Conversation\MotorSondeableInterface;

/**
 * Le pregunta «¿respondes?» a cada motor con credenciales. Lo comparten la tarea programada
 * (`app:agent:vigilar-motor`, cada 30 minutos) y el botón «comprobar» del panel (22/10/2026), que
 * existe para no esperar a la siguiente media hora tras recargar créditos.
 *
 * Los dos pasan por el MISMO `VigilanteDelMotor::resultadosDelSondeo()`: un motor que vuelve por el
 * botón sale de `MotoresFueraDeServicio` y el equipo recibe el «vuelve a responder» igual que si lo
 * hubiera visto el cron. Dos caminos con estados propios acabarían diciendo cosas distintas.
 */
final readonly class SondeoDeMotores
{
    public function __construct(
        private AgentEngineRegistry $motores,
        private VigilanteDelMotor $vigilante,
    ) {}

    /**
     * Sólo pregunta: ni avisa ni toca el estado.
     *
     * @return array<string, FalloDelMotor|null> motor => fallo, o `null` si respondió.
     */
    public function comprobar(): array
    {
        $fallos = [];

        foreach ($this->motores->disponibles() as $motor) {
            if ($motor instanceof MotorSondeableInterface) {
                $fallos[$motor->nombre()] = $motor->sondear();
            }
        }

        return $fallos;
    }

    /**
     * Pregunta, guarda el estado, saca o devuelve motores y avisa al equipo si toca.
     *
     * @return array<string, array{fallo: string|null, accion: string}>
     */
    public function sondear(): array
    {
        $fallos = $this->comprobar();
        $acciones = $this->vigilante->resultadosDelSondeo($fallos);
        $resultado = [];

        foreach ($fallos as $motor => $fallo) {
            $resultado[$motor] = ['fallo' => $fallo?->getMessage(), 'accion' => $acciones[$motor] ?? DecisionDelSondeo::NADA];
        }

        return $resultado;
    }
}
