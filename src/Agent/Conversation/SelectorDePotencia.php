<?php

declare(strict_types=1);

namespace App\Agent\Conversation;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Traduce un tramo de potencia a un motor y un modelo de verdad.
 *
 * La configuración es una línea por tramo, en la forma `proveedor:modelo`:
 *
 *     AGENT_IA_POTENCIA_ALTA=anthropic:claude-opus-5
 *     AGENT_IA_POTENCIA_MEDIA=anthropic:claude-sonnet-5
 *     AGENT_IA_POTENCIA_BAJA=anthropic:claude-haiku-4-5-20251001
 *
 * ⚠️ **Ese tercer ejemplo no cachearía nunca con los prefijos de aquí, y no lo dice.** El mínimo
 * de Haiku 4.5 son 4 096 tokens; los tramos cortos no llegan, así que devuelve
 * `cache_creation_input_tokens: 0` y sigue como si nada. Opus 5 lo tiene en 512 y Sonnet 5 en
 * 1 024: **el mismo prefijo cachea o no según qué modelo haya en el tramo.** Ver
 * `docs/Mensajeria.md` §13.5 bis.
 *
 * **Los tramos pueden cruzar proveedores.** Es el motivo de que la clave lleve el proveedor
 * dentro en vez de heredar el de `AGENT_IA_PROVEEDOR`: el tramo bajo en Gemini Flash Lite y el
 * alto en Opus es una combinación razonable, y con una clave por tramo se prueba sin desplegar.
 *
 * ⚠️ **Pero que se pueda no quiere decir que convenga, y el motivo NO es el que se supone.** La
 * caché es **por modelo**, no por proveedor: un salto Sonnet→Opus la tira exactamente igual que
 * Anthropic→Google. Quedarse dentro de un proveedor no compra ni un token. Lo que sí se paga al
 * cruzar es otra cosa: los prompts no son portables ({@see AgentEngineInterface}, «cambiar de
 * motor obliga a recalibrarlos») y la paridad de funciones está rota entre proveedores. Por eso
 * la recomendación operativa es **mismo proveedor en los tres tramos, por configuración**, y no
 * prohibirlo aquí: prohibirlo quitaría la única forma de probar un proveedor nuevo en un tramo.
 *
 * ### 🔻 Aquí SÍ se cae hacia otro motor, al revés que en el registro
 *
 * {@see AgentEngineRegistry::elegir()} no hace fallback a propósito: quien elige proveedor en
 * el desplegable del panel está comparando, y recibir en silencio la respuesta del otro le
 * arruina la comparación. Aquí la situación es la contraria. El tramo de potencia es una
 * **optimización interna** que el huésped no pidió: si el tramo bajo apunta a un proveedor sin
 * credenciales, la alternativa correcta no es dejar de contestarle, es contestarle con el motor
 * de siempre y dejar constancia en el log. Un ajuste de coste mal configurado no puede tumbar
 * el chat.
 *
 * Por eso todos los caminos degradan y **todos avisan**: un `warning` por consulta es molesto a
 * propósito, porque significa que se está pagando el tramo que no era.
 */
final readonly class SelectorDePotencia
{
    public function __construct(
        private AgentEngineRegistry $registro,
        private LoggerInterface $logger,
        private MotoresFueraDeServicio $fuera,
        private string $alta,
        private string $media,
        private string $baja,
    ) {}

    /**
     * @return MotorElegido|null `null` sólo cuando NO hay ningún motor con credenciales en todo
     *         el entorno; es decir, cuando la IA está apagada de hecho.
     */
    public function elegir(PotenciaRequerida $potencia): ?MotorElegido
    {
        $elegido = $this->resolver($potencia);

        // 🔁 SUPLENCIA: el motor del tramo está sin servicio (créditos, clave) y contesta otro hasta
        // que el sondeo lo vea responder. Ver `MotoresFueraDeServicio` y `suplenteDe()`.
        if ($elegido === null || !$this->fuera->estaFuera($elegido->motor->nombre())) {
            return $elegido;
        }

        $suplente = $this->suplenteDe($elegido->motor->nombre());

        if ($suplente === null) {
            return $elegido;
        }

        $this->logger->warning(sprintf(
            'Agent: «%s» está fuera de servicio; el tramo %s lo atiende «%s» hasta que vuelva.',
            $elegido->motor->nombre(),
            $potencia->value,
            $suplente->nombre()
        ));

        return new MotorElegido($suplente, null, $potencia);
    }

    /**
     * Quién cubre a un motor sin servicio (22/10/2026), por este orden: el de por defecto; si el caído
     * ES el de por defecto, el de otro tramo, que ya contesta en producción; y en último lugar
     * cualquier otro con credenciales (DeepSeek), que el sondeo también vigila, así que si está aquí
     * es que responde. Siempre con SU modelo por defecto, no el del tramo que cubre: el tramo bajo
     * suele ser un modelo pequeño.
     *
     * `null` = no hay quien lo cubra; el turno sigue con el caído y acaba en el acuse de recibo.
     */
    public function suplenteDe(string $caido): ?AgentEngineInterface
    {
        foreach ([$this->registro->porDefecto(), ...$this->motoresEnUso(), ...$this->registro->disponibles()] as $candidato) {
            if ($candidato !== null && $candidato->nombre() !== $caido && !$this->fuera->estaFuera($candidato->nombre())) {
                return $candidato;
            }
        }

        return null;
    }

    /** El tramo tal como está configurado, sin suplencia. */
    private function resolver(PotenciaRequerida $potencia): ?MotorElegido
    {
        $spec = trim(match ($potencia) {
            PotenciaRequerida::Alta => $this->alta,
            PotenciaRequerida::Media => $this->media,
            PotenciaRequerida::Baja => $this->baja,
        });

        // Tramo sin configurar = el comportamiento de siempre. Es lo que hace que este
        // mecanismo se pueda desplegar sin cambiar nada: con las tres claves vacías, todos los
        // pasos siguen atendidos por `AGENT_IA_PROVEEDOR` con su modelo por defecto.
        if ($spec === '') {
            return $this->deReserva($potencia, null);
        }

        [$nombre, $modelo] = array_pad(explode(':', $spec, 2), 2, null);
        $nombre = trim((string) $nombre);
        $modelo = trim((string) $modelo);

        try {
            $motor = $this->registro->elegir($nombre);
        } catch (InvalidArgumentException $e) {
            return $this->deReserva($potencia, sprintf('«%s»: %s', $spec, $e->getMessage()));
        }

        if ($motor === null) {
            return $this->deReserva($potencia, sprintf('«%s» no resolvió ningún motor.', $spec));
        }

        try {
            // El modelo se valida contra la lista blanca del motor que lo va a ejecutar. Sin
            // esto, un `anthropic:gemini-3.6-flash` copiado de la línea de al lado se descubre
            // en producción, con un 404 del proveedor a mitad de una conversación.
            $modelo = $this->registro->validarModelo($motor, $modelo);
        } catch (InvalidArgumentException $e) {
            // El motor sí vale; el modelo no. Se usa el motor con SU modelo por defecto en vez
            // de saltar a otro proveedor: es lo más parecido a lo que se pidió.
            $this->logger->warning(sprintf(
                'Agent: potencia %s mal configurada (%s). Se usa %s con su modelo por defecto.',
                $potencia->value,
                $e->getMessage(),
                $motor->nombre()
            ));

            $modelo = null;
        }

        return new MotorElegido($motor, $modelo, $potencia);
    }

    /**
     * Los motores que de verdad atienden algún tramo, sin repetir y **sin suplencia**: los segundos
     * candidatos de `suplenteDe()`, detrás del de por defecto. Un tramo mal configurado cuenta con
     * el motor al que cae, que es el que contestaría.
     *
     * @return list<AgentEngineInterface>
     */
    public function motoresEnUso(): array
    {
        $motores = [];

        foreach (PotenciaRequerida::cases() as $potencia) {
            $elegido = $this->resolver($potencia);

            if ($elegido !== null) {
                $motores[$elegido->motor->nombre()] = $elegido->motor;
            }
        }

        return array_values($motores);
    }

    /**
     * El motor de siempre, con o sin queja.
     *
     * `$motivo` a `null` es el caso normal —tramo sin configurar—, y no se registra nada: no
     * hay nada mal. Con motivo es una configuración rota, y sí se avisa.
     */
    private function deReserva(PotenciaRequerida $potencia, ?string $motivo): ?MotorElegido
    {
        $motor = $this->registro->porDefecto();

        if ($motor === null) {
            return null;
        }

        if ($motivo !== null) {
            $this->logger->warning(sprintf(
                'Agent: potencia %s mal configurada (%s). Se cae al motor por defecto (%s).',
                $potencia->value,
                $motivo,
                $motor->nombre()
            ));
        }

        return new MotorElegido($motor, null, $potencia);
    }
}
