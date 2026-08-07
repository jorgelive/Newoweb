<?php

declare(strict_types=1);

namespace App\Agent\Conversation;

use InvalidArgumentException;

/**
 * Quién contesta: elige motor y modelo.
 *
 * Antes había un alias en `services_agent.yaml` apuntando a un único motor. Eso obligaba a
 * desplegar para cambiar de proveedor, y sobre todo impedía lo que de verdad hace falta:
 * **hacerle la misma pregunta a dos proveedores seguidos** y comparar. Ahora los motores se
 * registran con la etiqueta `app.agent_engine` y aquí se resuelve cuál usa cada llamada.
 *
 * Reglas de resolución, y el porqué de cada una:
 *
 * - **Sin nombre** (autoresponder del huésped, comandos): el de `AGENT_IA_PROVEEDOR`; si ese
 *   no tiene credenciales, el primero que sí las tenga. Un entorno a medio configurar debe
 *   seguir contestando.
 * - **Con nombre** (el desplegable del panel): ese y sólo ese. Aquí **no** se cae hacia otro:
 *   quien está comparando proveedores necesita saber que el que pidió no está configurado,
 *   no recibir en silencio la respuesta del otro.
 *
 * Ver docs/Mensajeria.md §12.
 */
final readonly class AgentEngineRegistry
{
    /**
     * @param iterable<AgentEngineInterface> $motores Todo servicio etiquetado `app.agent_engine`.
     * @param string $proveedorPorDefecto Nombre del motor preferido (`AGENT_IA_PROVEEDOR`).
     */
    public function __construct(
        private iterable $motores,
        private string $proveedorPorDefecto,
    ) {}

    /** ¿Hay alguna IA utilizable en este entorno? */
    public function hayDisponible(): bool
    {
        return $this->porDefecto() !== null;
    }

    /**
     * El motor que atiende cuando nadie pide uno concreto.
     */
    public function porDefecto(): ?AgentEngineInterface
    {
        $preferido = $this->buscar(trim($this->proveedorPorDefecto));
        if ($preferido !== null && $preferido->estaDisponible()) {
            return $preferido;
        }

        foreach ($this->motores as $motor) {
            if ($motor->estaDisponible()) {
                return $motor;
            }
        }

        return null;
    }

    /**
     * Resuelve el motor de una petición.
     *
     * @throws InvalidArgumentException Si se pide un proveedor que no existe o no está
     *         configurado. Es un error del que quien pregunta debe enterarse, no un fallback.
     */
    public function elegir(?string $nombre): ?AgentEngineInterface
    {
        $nombre = $nombre === null ? '' : trim($nombre);

        if ($nombre === '') {
            return $this->porDefecto();
        }

        $motor = $this->buscar($nombre);
        if ($motor === null) {
            throw new InvalidArgumentException(sprintf('No existe el proveedor de IA "%s".', $nombre));
        }

        if (!$motor->estaDisponible()) {
            throw new InvalidArgumentException(sprintf(
                'El proveedor "%s" no tiene credenciales configuradas en este entorno.',
                $motor->etiqueta()
            ));
        }

        return $motor;
    }

    /**
     * Comprueba que el modelo pedido pertenece al motor.
     *
     * @return string|null El modelo validado, o `null` para «usa el tuyo por defecto».
     * @throws InvalidArgumentException Si el modelo no está en la lista blanca del motor.
     */
    public function validarModelo(AgentEngineInterface $motor, ?string $modelo): ?string
    {
        $modelo = $modelo === null ? '' : trim($modelo);

        if ($modelo === '') {
            return null;
        }

        if (!in_array($modelo, $motor->modelos(), true)) {
            throw new InvalidArgumentException(sprintf(
                'El modelo "%s" no está habilitado para %s.',
                $modelo,
                $motor->etiqueta()
            ));
        }

        return $modelo;
    }

    /**
     * Catálogo para el desplegable del panel. Incluye los motores SIN credenciales, marcados
     * como no disponibles: ver que Google existe pero está apagado es información útil.
     *
     * @return list<array{
     *     proveedor: string,
     *     etiqueta: string,
     *     disponible: bool,
     *     modelos: list<string>,
     *     modeloPorDefecto: string,
     *     porDefecto: bool
     * }>
     */
    public function catalogo(): array
    {
        $activo = $this->porDefecto()?->nombre();
        $catalogo = [];

        foreach ($this->motores as $motor) {
            $catalogo[] = [
                'proveedor' => $motor->nombre(),
                'etiqueta' => $motor->etiqueta(),
                'disponible' => $motor->estaDisponible(),
                'modelos' => $motor->modelos(),
                'modeloPorDefecto' => $motor->modeloPorDefecto(),
                'porDefecto' => $motor->nombre() === $activo,
            ];
        }

        return $catalogo;
    }

    private function buscar(string $nombre): ?AgentEngineInterface
    {
        if ($nombre === '') {
            return null;
        }

        foreach ($this->motores as $motor) {
            if ($motor->nombre() === $nombre) {
                return $motor;
            }
        }

        return null;
    }
}
