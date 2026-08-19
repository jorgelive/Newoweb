<?php

declare(strict_types=1);

namespace App\Pms\DispatchHandler;

use App\Agent\Access\AgentActor;
use App\Agent\Conversation\ConversationRequest;
use App\Agent\Conversation\PotenciaRequerida;
use App\Agent\Conversation\SelectorDePotencia;
use App\Pms\Dispatch\RevisarOrdenDelNombreDispatch;
use App\Pms\Entity\PmsReserva;
use App\Pms\Nombre\OrdenDelNombre;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Endereza el nombre y el apellido de una reserva cuando el canal los mandó cruzados.
 *
 * ### Por qué un handler y no una skill
 *
 * Una skill es una herramienta que **elige el modelo** dentro de una conversación, con su
 * descripción escrita para que la reconozca. Aquí no hay conversación ni nadie a quien
 * preguntar: es un trabajo de sistema que se dispara solo al entrar una reserva. El molde
 * correcto es el de {@see \App\Agent\Dispatch\ProcessInboundIntentDispatch} — un dispatch
 * enrutado a `async` y su handler—, y así además no aparece en el catálogo de ninguna skill ni
 * gasta tokens del prompt de nadie.
 *
 * ### Por qué asíncrono, y qué margen hay de verdad
 *
 * Va aparte porque una llamada al modelo dentro del webhook lo alargaría varios segundos, y
 * Beds24 y Meta reintentan los webhooks lentos — el mismo motivo por el que
 * `ProcessInboundIntentDispatch` está en `async` (ver `config/packages/messenger.yaml`).
 *
 * El margen existe porque **el texto del mensaje se renderiza al ENVIAR, no al encolar**: la
 * fila de `msg_beds24_send_queue` guarda `message_id`, no el cuerpo, y quien lo compone es
 * `exchange:run beds24_message_send`, un comando de cron. Hasta que ese cron pase, corregir el
 * nombre todavía cambia lo que va a leer el huésped.
 *
 * ⚠️ **Ese margen NO está garantizado por nada de este repositorio**: es la cadencia del
 * crontab del servidor. Si algún día se aprieta, la bienvenida puede adelantarse. La forma de
 * volverlo determinista sin tocar código es dar a las reglas «Bienvenida a…» un
 * `offset_minutes` de unos pocos minutos, que hoy es 0.
 *
 * ### El cierre es código
 *
 * El modelo contesta un booleano y una confianza; **nunca el nombre**. Quien intercambia las dos
 * cadenas es {@see OrdenDelNombre::resultado()}, con las que ya estaban guardadas. Así el peor
 * fallo posible es un intercambio equivocado, no un nombre inventado.
 */
#[AsMessageHandler]
final readonly class RevisarOrdenDelNombreDispatchHandler
{
    /** El presupuesto va holgado: los modelos que razonan gastan parte pensando. */
    private const int MAX_TOKENS = 400;

    public function __construct(
        private EntityManagerInterface $em,
        private SelectorDePotencia $potencias,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(RevisarOrdenDelNombreDispatch $dispatch): void
    {
        if (!Uuid::isValid($dispatch->reservaId)) {
            return;
        }

        $reserva = $this->em->find(PmsReserva::class, Uuid::fromString($dispatch->reservaId));

        if (!$reserva instanceof PmsReserva) {
            return;
        }

        // Tramo bajo: es una pregunta cerrada de dos campos, no un juicio de negocio.
        $elegido = $this->potencias->elegir(PotenciaRequerida::Baja);

        if ($elegido === null) {
            $this->logger->warning('[OrdenNombre] Ningún motor disponible; la reserva se queda como vino.');

            return;
        }

        try {
            $datos = $elegido->motor->turnoDirecto(
                new ConversationRequest(
                    // Sin roles y sin contexto: nadie ha preguntado y no hay a quién
                    // contestar. `turnoDirecto()` va sin herramientas, así que no hace
                    // falta ningún permiso. Ver AgentActor::sistema().
                    actor: AgentActor::sistema('pms_orden_nombre'),
                    systemPrompt: $this->reglas(),
                    mensaje: sprintf(
                        "campo_nombre: «%s»\ncampo_apellido: «%s»",
                        $dispatch->nombre,
                        $dispatch->apellido
                    ),
                    permitirEscritura: false,
                    maxTokens: self::MAX_TOKENS,
                    modelo: $elegido->modelo,
                ),
                $this->esquema()
            );
        } catch (Throwable $e) {
            $this->logger->warning(sprintf(
                '[OrdenNombre] El motor falló con la reserva %s (%s); se queda como vino.',
                $dispatch->reservaId,
                $e->getMessage()
            ));

            return;
        }

        // `turnoDirecto()` devuelve el JSON como texto; el esquema sólo obliga a su forma.
        $veredicto = json_decode((string) $datos, true);

        if (!is_array($veredicto)) {
            $this->logger->warning('[OrdenNombre] El motor no devolvió un veredicto legible.');

            return;
        }

        $par = OrdenDelNombre::resultado(
            invertido: (bool) ($veredicto['invertido'] ?? false),
            confianza: (string) ($veredicto['confianza'] ?? ''),
            nombreJuzgado: $dispatch->nombre,
            apellidoJuzgado: $dispatch->apellido,
            nombreActual: $reserva->getNombreCliente(),
            apellidoActual: $reserva->getApellidoCliente(),
        );

        if ($par === null) {
            return;
        }

        [$nombre, $apellido] = $par;

        $reserva->setNombreCliente($nombre);
        $reserva->setApellidoCliente($apellido);
        $this->em->flush();

        $this->logger->info(sprintf(
            '[OrdenNombre] Reserva %s: «%s / %s» venía cruzado y queda «%s / %s» (%s).',
            $dispatch->reservaId,
            $dispatch->nombre,
            $dispatch->apellido,
            $nombre,
            $apellido,
            (string) ($veredicto['motivo'] ?? 'sin motivo')
        ));
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
