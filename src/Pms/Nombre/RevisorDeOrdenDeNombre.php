<?php

declare(strict_types=1);

namespace App\Pms\Nombre;

use App\Agent\Access\AgentActor;
use App\Agent\Conversation\ConversationRequest;
use App\Agent\Conversation\PotenciaRequerida;
use App\Agent\Conversation\SelectorDePotencia;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Le pregunta al modelo si un par nombre/apellido viene cruzado. **Sólo pregunta.**
 *
 * ── Por qué existe, si el handler ya lo hacía ───────────────────────────────
 * Estaba dentro de `RevisarOrdenDelNombreDispatchHandler`, con su prompt y su esquema privados.
 * Funcionaba, pero no había forma de **verlo**: el handler corre en un worker, sobre reservas que
 * entran por webhook, y su única línea de éxito es un `info` que producción oculta. Preguntado a
 * mano —«¿esto está funcionando?»— no había nada que mirar.
 *
 * Se saca aquí para que el comando `pms:nombre:revisar` pregunte **exactamente lo mismo** que
 * pregunta el worker. Un simulador que arme su propio prompt no simula nada: comprueba otro
 * sistema parecido.
 *
 * ⚠️ **No decide ni escribe.** Devuelve el veredicto crudo; quién lo aplica y con qué exigencias
 * sigue siendo {@see OrdenDelNombre::resultado()}, y quién guarda es el handler. El modelo nunca
 * devuelve un nombre — sólo un booleano y una confianza. Ver el docblock de `OrdenDelNombre`.
 */
final readonly class RevisorDeOrdenDeNombre
{
    /** El presupuesto va holgado: los modelos que razonan gastan parte pensando. */
    private const int MAX_TOKENS = 400;

    public function __construct(
        private SelectorDePotencia $potencias,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * El veredicto del modelo, o `null` si no se pudo obtener.
     *
     * Los tres motivos de `null` —sin motor, el motor falló, respuesta ilegible— se registran
     * como `warning`, que sí se ve en producción. El veredicto en sí lo registra quien lo use:
     * este servicio no sabe si se está simulando o trabajando.
     *
     * @return array{invertido: bool, confianza: string, motivo: string}|null
     */
    public function veredicto(string $nombre, string $apellido): ?array
    {
        // Tramo bajo: es una pregunta cerrada de dos campos, no un juicio de negocio.
        $elegido = $this->potencias->elegir(PotenciaRequerida::Baja);

        if ($elegido === null) {
            $this->logger->warning('[OrdenNombre] Ningún motor disponible; el par se queda como vino.');

            return null;
        }

        try {
            $datos = $elegido->motor->turnoDirecto(
                new ConversationRequest(
                    // Sin roles y sin contexto: nadie ha preguntado y no hay a quién contestar.
                    // `turnoDirecto()` va sin herramientas, así que no hace falta ningún permiso.
                    actor: AgentActor::sistema('pms_orden_nombre'),
                    systemPrompt: $this->reglas(),
                    mensaje: sprintf("campo_nombre: «%s»\ncampo_apellido: «%s»", $nombre, $apellido),
                    permitirEscritura: false,
                    maxTokens: self::MAX_TOKENS,
                    modelo: $elegido->modelo,
                ),
                $this->esquema()
            );
        } catch (Throwable $e) {
            $this->logger->warning(sprintf(
                '[OrdenNombre] El motor falló con «%s / %s» (%s); se queda como vino.',
                $nombre,
                $apellido,
                $e->getMessage()
            ));

            return null;
        }

        // `turnoDirecto()` devuelve el JSON como texto; el esquema sólo obliga a su forma.
        $veredicto = json_decode((string) $datos, true);

        if (!is_array($veredicto)) {
            $this->logger->warning('[OrdenNombre] El motor no devolvió un veredicto legible.');

            return null;
        }

        return [
            'invertido' => (bool) ($veredicto['invertido'] ?? false),
            'confianza' => (string) ($veredicto['confianza'] ?? ''),
            'motivo' => (string) ($veredicto['motivo'] ?? 'sin motivo'),
        ];
    }

    /** Qué modelo contestaría hoy, para que el simulador lo diga en pantalla. */
    public function modeloElegido(): ?string
    {
        return $this->potencias->elegir(PotenciaRequerida::Baja)?->modelo;
    }

    /**
     * Escrito en positivo y por ramas, que es como se bifurca bien. En negativo —«no te
     * inventes»— es supresión, y aquí la supresión no hace falta: el modelo no devuelve texto
     * que se guarde.
     */
    private function reglas(): string
    {
        return <<<PROMPT
        Recibes dos campos de una reserva de hotel, tal y como los mandó el canal de venta
        (Booking, Airbnb). A veces vienen cruzados: el campo del nombre trae los apellidos y el
        del apellido trae los nombres de pila.

        Tu único trabajo es decir si están cruzados.

        Cómo se decide:
        - Piensa en qué cultura encaja cada token. «Rodriguez Barrera» son dos apellidos
          hispanos; «Alisson Angelica» son dos nombres de pila. Ahí están cruzados.
        - En muchos países el apellido va primero y es lo correcto. Sólo estás juzgando si los
          DOS CAMPOS están al revés entre sí, no el orden en que los escribiría alguien.
        - Si el par funciona igual de bien en los dos sentidos, o no reconoces la procedencia,
          la confianza es «baja» y se queda como está. Dejarlo quieto no cuesta nada; cruzarlo
          mal le cambia el nombre a una persona.

        Responde sólo con el veredicto.
        PROMPT;
    }

    /** @return array<string, mixed> */
    private function esquema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'invertido' => [
                    'type' => 'boolean',
                    'description' => 'true SÓLO si el campo del nombre trae los apellidos y el '
                        . 'del apellido trae los nombres de pila.',
                ],
                'confianza' => [
                    'type' => 'string',
                    'enum' => ['alta', 'media', 'baja'],
                    'description' => '«alta» sólo si reconoces claramente qué token es nombre y '
                        . 'cuál apellido. Ante la duda, «baja».',
                ],
                'motivo' => [
                    'type' => 'string',
                    'description' => 'Media línea en español explicando la decisión.',
                ],
            ],
            'required' => ['invertido', 'confianza', 'motivo'],
        ];
    }
}
