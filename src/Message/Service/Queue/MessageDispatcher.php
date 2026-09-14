<?php

declare(strict_types=1);

namespace App\Message\Service\Queue;

use App\Message\Contract\ChannelEnqueuerInterface;
use App\Message\Contract\MessageQueueItemInterface;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Throwable;
use Psr\Log\LoggerInterface;

/**
 * Orquesta la creación de ítems en las colas (Outbox) usando el patrón Strategy.
 * Delega la creación física a los Encoladores Específicos según los canales activos.
 * Implementa resiliencia multicanal tolerando fallos parciales de encolamiento.
 */
readonly class MessageDispatcher
{
    /**
     * @param iterable<ChannelEnqueuerInterface> $enqueuers Colección de encoladores etiquetados inyectados por Symfony.
     */
    public function __construct(
        #[TaggedIterator('app.message.enqueuer')]
        private iterable                $enqueuers,
        private EntityManagerInterface  $em,
        private LoggerInterface         $logger,
        private EnlacesDeConversacion   $enlaces
    ) {}

    /**
     * Evalúa canales y crea colas físicas, respetando la idempotencia dictada por los Enqueuers.
     * Si un canal falla, los demás continúan (Tolerancia a fallos parciales).
     *
     * @param Message $message La entidad mensaje original.
     *
     * ⚠️ Había **dos `@return` contradictorios** —«las entidades de cola creadas» y
     * `array<string, mixed>` «resultado por canal»— y ninguno era cierto: devuelve una LISTA
     * numerada (`$queues[] = $queue`), que es como la consumen los dos llamadores
     * (`foreach ($this->dispatcher->dispatch($message) as $queue)`). Quien se hubiera fiado del
     * segundo habría indexado por nombre de canal algo que viene numerado.
     *
     * @return list<MessageQueueItemInterface> Las colas creadas, en el orden en que se crearon.
     */
    public function dispatch(Message $message): array
    {
        // 🔥 Un mensaje sin nada dentro no sale. Comprobado antes de tocar los canales.
        //
        // No es hipotético: hay cuatro en producción sin texto, sin adjunto y sin plantilla, y
        // TRES llegaron a Beds24 con `sent_at` — o sea que Airbnb y Booking recibieron un
        // mensaje en blanco de nuestra parte. Nada lo impedía: ni la entidad ni el controlador
        // comprueban que haya contenido, y el encolador tampoco.
        //
        // Se admite el mensaje SIN texto cuando lleva plantilla —el cuerpo se hidrata al
        // enviar— o cuando lleva adjunto, que es una foto y ya es contenido.
        if ($this->estaVacio($message)) {
            $this->anotarDesenlace($message, Message::STATUS_FAILED, 'dispatch_errors', [
                'El mensaje no tiene texto, ni plantilla, ni adjunto: no se envía nada vacío.',
            ]);

            $this->logger->warning(sprintf(
                'Mensaje %s descartado por vacío: sin texto, sin plantilla y sin adjuntos.',
                $message->getId()?->toRfc4122() ?? 'N/A'
            ));

            return [];
        }

        $queues = [];
        $errors = [];
        $yaEncolados = 0;
        $channels = $this->resolveChannels($message);
        $runAt = $message->getScheduledAt() ?? new DateTimeImmutable();

        foreach ($channels as $channel) {
            foreach ($this->enqueuers as $enqueuer) {
                if ($enqueuer->supports($channel)) {

                    // 🛡️ BARRERA DE IDEMPOTENCIA
                    if ($enqueuer->isAlreadyEnqueued($message)) {
                        // Se CUENTA, no sólo se ignora: más abajo distingue «ya estaba encolado»
                        // de «no se pudo encolar», que acabaron siendo el mismo final y no lo son.
                        ++$yaEncolados;

                        $this->logger->info(sprintf(
                            'Idempotencia: La cola %s para el mensaje %s ya existe en BD/UoW. Ignorando.',
                            $channel->getId(),
                            $message->getId()?->toRfc4122() ?? 'N/A'
                        ));
                        break;
                    }

                    try {
                        // Pasamos el $runAt exacto (presente o futuro) al Enqueuer
                        $queue = $enqueuer->createQueueEntity($message, $channel, $runAt);

                        if ($queue !== null) {
                            $queues[] = $queue;
                        }
                    } catch (Throwable $e) {
                        // Atrapamos el error específico del canal, pero NO rompemos el bucle
                        $errors[] = sprintf('[%s] %s', $channel->getName(), $e->getMessage());
                    }

                    // Ya encontramos el encolador para este canal, no seguimos iterando enqueuers
                    break;
                }
            }
        }

        // =====================================================================
        // 🔥 LÓGICA DE FALLO Y ÉXITO MEJORADA (Resiliencia Parcial)
        // =====================================================================
        if (empty($channels)) {
            // NI UN CANAL. Antes caía en el `else` y el mensaje se quedaba `QUEUED` sin una
            // sola cola detrás: en el panel se veía «encolado» y no salía nunca, que es el
            // peor de los tres finales posibles porque nadie lo va a ir a buscar.
            //
            // Se vuelve alcanzable de verdad con el corte por asunto —un expediente de viaje
            // con sólo Beds24 marcado se queda sin nada—, así que el final tiene que decirlo.
            // `sin_canal`, no `failed`: nadie se rompió, es que no había a dónde mandarlo.
            $this->anotarDesenlace($message, Message::STATUS_SIN_CANAL, 'dispatch_errors', [
                'Ningún canal disponible para este mensaje: o no se marcó ninguno, o los marcados no existen para este asunto.',
            ]);

            $this->logger->warning(sprintf(
                'Mensaje %s sin ningún canal que despachar.',
                $message->getId()?->toRfc4122() ?? 'N/A'
            ));

            return [];
        }

        if (empty($queues) && $yaEncolados > 0) {
            // 🔁 NO ES UN FALLO: ES EL SEGUNDO PASE.
            //
            // `dispatch()` se llama DOS veces sobre el mismo mensaje —`prePersist` lo encola y
            // `preUpdate` vuelve a pedir colas por si apareció un canal nuevo
            // ({@see \App\Message\EventListener\Queue\MessageEnqueuerEntityListener::fabricarColas})—.
            // En el segundo, la barrera de idempotencia hace `break` sin crear nada, `$queues`
            // sale vacío y el mensaje terminaba en `failed`… con sus colas vivas y a punto de
            // salir. El panel decía una cosa y la cola hacía la otra.
            //
            // Y no se quedaba en la etiqueta. Un mensaje `failed` deja de ser el intento vigente
            // de su regla, así que el motor fabricaba OTRO en la pasada siguiente, con su propia
            // cola, y otro, y otro. Medido el 14/09/2026 antes de tocar nada:
            //
            // | Huésped | Plantilla | Sale el | Colas vivas idénticas |
            // |---|---|---|---|
            // | Vanessa (2KRERH) | `recordatorio_llegada` | 04/10 08:00 | **71** por WhatsApp y 71 por Booking |
            // | Vanessa (2KRERH) | `check_out` | 10/10 12:00 | 71 y 71 |
            // | Vanessa (2KRERH) | `despedida_booking` | 11/10 11:00 | 71 y 71 |
            // | Karina (P9Y2XK) | `recordatorio_llegada` | 29/09 08:00 | 37 por WhatsApp |
            //
            // Crecían tres por pasada del motor. La prueba de que las colas estaban vivas y el
            // mensaje mentía: `01A0A0B7F38F71…`, creado a las 11:20 de ese día, `failed`, con sus
            // dos colas en `pending` para el 4 de octubre.
            //
            // ⚠️ Lo que cierra el bucle no es sólo la etiqueta: con el mensaje en `queued`, la
            // pasada siguiente del motor lo reconoce como suyo y, si la regla ya no aplica, le
            // cancela las colas en cascada. Marcado `failed` nadie las tocaba nunca.
            $this->anotarDesenlace($message, Message::STATUS_QUEUED, 'dispatch_partial_errors', $errors);

            return [];
        }

        if (empty($queues)) {
            // FRACASO TOTAL: Había canales previstos, pero NINGUNO generó una cola.
            // (Ya sea porque todos lanzaron excepción, o todos retornaron null por reglas de negocio)
            // ⚠️ **Sin errores significa que TODOS los encoladores dijeron «yo no».**
            // `createQueueEntity()` devuelve `null` cuando el canal no aplica y lanza cuando algo
            // se rompe, así que la lista vacía ya distingue los dos casos — sólo faltaba que el
            // estado lo dijera. Ver `Message::STATUS_SIN_CANAL`.
            $sinCanalAplicable = empty($errors);

            $motivo = $sinCanalAplicable
                ? ['Ningún canal aplicaba a este asunto: todos los encoladores declinaron.']
                : $errors;

            $this->anotarDesenlace(
                $message,
                $sinCanalAplicable ? Message::STATUS_SIN_CANAL : Message::STATUS_FAILED,
                'dispatch_errors',
                $motivo
            );

        } else {
            // ÉXITO (Total o Parcial): Al menos una cola se generó correctamente.
            $this->anotarDesenlace($message, Message::STATUS_QUEUED, 'dispatch_partial_errors', $errors);

            // Si hubo éxito, pero algún otro canal falló, dejamos registro de auditoría
            if (!empty($errors)) {
                $this->logger->warning(sprintf(
                    'Mensaje %s encolado con fallos parciales: %s',
                    $message->getId()?->toRfc4122() ?? 'N/A',
                    implode(' | ', $errors)
                ));
            }
        }

        return $queues;
    }

    /**
     * ¿Este mensaje no lleva absolutamente nada que enviar?
     *
     * Los tres contenidos posibles, y basta con uno:
     *
     * - **texto**, en local o en el externo ya traducido;
     * - **plantilla**, porque el cuerpo se hidrata en el momento del envío y aquí todavía no
     *   existe — es el caso normal de todo lo automático;
     * - **adjunto**, que es una foto y se envía sin una sola palabra.
     *
     * `trim()` a propósito: un cuerpo con sólo espacios o saltos de línea es tan vacío como uno
     * nulo, y llega igual desde un textarea al que se le dio a enviar sin querer.
     */
    private function estaVacio(Message $message): bool
    {
        if ($message->getTemplate() !== null) {
            return false;
        }

        if (!$message->getAttachments()->isEmpty()) {
            return false;
        }

        return trim((string) $message->getContentLocal()) === ''
            && trim((string) $message->getContentExternal()) === '';
    }

    /**
     * Escribe el desenlace del despacho en el mensaje — salvo que el mensaje NO SEA NUESTRO.
     *
     * Un mensaje ENTRANTE llega a `dispatch()` por una sola puerta: el acuse de lectura de
     * `MarkConversationReadController`, que le reinyecta su canal de origen para fabricar el
     * recibo hacia la OTA (el patrón proactivo que explica allí un comentario largo). Pero ahí
     * el mensaje es **la pregunta del huésped**, recién marcada como `read`, y el desenlace que
     * se está calculando es el del RECIBO, no el suyo.
     *
     * Escribirlo encima decía dos mentiras distintas, las dos medidas en producción:
     *
     * | qué se escribía | cuántos | qué parecía |
     * |---|---|---|
     * | `failed` + `dispatch_errors` | 17 | la pregunta del huésped, fallida y con icono rojo |
     * | `queued` → `sent` | 9 | «enviado» sobre algo que él escribió |
     *
     * El primero salta siempre que Beds24 está vetado —o sea, **en las reservas directas**— y
     * además esconde el mensaje de cualquier consulta que filtre por `received`/`read`. El
     * segundo es peor por silencioso: «enviado» sobre un entrante ni siquiera se lee como raro.
     *
     * ⚠️ El recibo se sigue encolando igual. Lo único que no se toca es el estado de quien
     * escribió: un fallo al acusar recibo es un problema NUESTRO, y contarlo en su mensaje es
     * contarlo en el sitio de otro.
     *
     * @param list<string> $motivos Vacío = no se anota metadata, sólo el estado.
     */
    private function anotarDesenlace(
        Message $message,
        string $estado,
        string $clave,
        array $motivos = []
    ): void {
        if ($message->getDirection() === Message::DIRECTION_INCOMING) {
            $this->logger->info('Desenlace no anotado: el mensaje es entrante y el despacho era su acuse de lectura.', [
                'mensaje' => $message->getId()?->toRfc4122(),
                'estado_que_se_iba_a_escribir' => $estado,
                'motivos' => $motivos,
            ]);

            return;
        }

        $message->setStatus($estado);

        if ($motivos !== []) {
            $message->addMetadata($clave, $motivos);
        }
    }

    /**
     * Aplica las reglas de negocio para determinar los canales destino finales.
     * Analiza las plantillas, la selección manual del usuario y hace un fallback si es necesario.
     * * @param Message $message
     * @return MessageChannel[] Arreglo de canales resultantes a despachar.
     */
    private function resolveChannels(Message $message): array
    {
        $channelRepo = $this->em->getRepository(MessageChannel::class);
        $transientIds = $message->getTransientChannels();
        $resolvedChannels = [];

        // =====================================================================
        // REGLA 1: PLANTILLA ACTÚA COMO EL "MÁXIMO PERMITIDO"
        // =====================================================================
        if ($template = $message->getTemplate()) {
            // Buscamos todos los canales activos en el sistema
            $allActiveChannels = $channelRepo->findBy(['isActive' => true]);

            // 1A. Identificamos qué canales permite la plantilla
            foreach ($allActiveChannels as $channel) {
                $column = $channel->getTemplateColumn();
                $getter = 'get' . ucfirst($column);

                if (method_exists($template, $getter)) {
                    $tmplData = $template->$getter();

                    // Si el interruptor is_active dentro del JSON está encendido, lo sumamos
                    if (is_array($tmplData) && ($tmplData['is_active'] ?? false) === true) {
                        $resolvedChannels[] = $channel;
                    }
                }
            }

            // 1B. 🔥 INTERSECCIÓN CON LA DECISIÓN DEL OPERADOR
            // Si el request trajo canales explícitos (la UI envió sus checkboxes),
            // filtramos para respetar si el operador desmarcó voluntariamente alguno.
            if (!empty($transientIds)) {
                $resolvedChannels = array_filter($resolvedChannels, function (MessageChannel $c) use ($transientIds) {
                    return in_array($c->getId(), $transientIds, true);
                });
            }

            return $this->acotarAlAsunto($message, array_values($resolvedChannels));
        }

        // =====================================================================
        // REGLA 2: SELECCIÓN MANUAL DEL OPERADOR (Texto Libre)
        // =====================================================================
        if (!empty($transientIds)) {
            return $this->acotarAlAsunto($message, $channelRepo->findBy([
                'id' => $transientIds,
                'isActive' => true
            ]));
        }

        // =====================================================================
        // REGLA 3: FALLBACK
        // =====================================================================
        if ($message->getChannel() && $message->getChannel()->isActive()) {
            return $this->acotarAlAsunto($message, [$message->getChannel()]);
        }

        return [];
    }

    /**
     * Quita los canales que NO existen para el asunto al que va este mensaje.
     *
     * ── Por qué hace falta y por qué aquí ───────────────────────────────────
     * Las tres reglas de arriba no saben de dominios: cruzan lo que permite la plantilla con
     * lo que marcó el operador. Con un solo negocio daba igual; con Turismo dentro, Beds24
     * entraba en la lista de un expediente de viaje —que no tiene `bookId` ni lo tendrá— y el
     * corte llegaba tarde, dentro de `Beds24SendEnqueuer::isBusinessValid()`: como ese
     * encolador devuelve `null` en vez de encolar, si era el único canal el mensaje acababa
     * en `STATUS_FAILED` con «posible restricción de negocio por canal». Un canal que no
     * existe no es un envío fallido.
     *
     * Va aquí y no en el front porque el front es una PETICIÓN: el operador manda ids en
     * `transientChannels` y una skill del agente también. El cierre es esto.
     *
     * ── Con qué asunto se acota ─────────────────────────────────────────────
     * Con el del MENSAJE cuando lo lleva. Si no —la mayoría hoy—, con la unión de los del
     * hilo, que no acota nada mientras haya un asunto de alojamiento: se prefiere ofrecer un
     * canal de más, que es visible y se corrige, a callar uno legítimo, que no se descubre.
     *
     * @param MessageChannel[] $canales
     * @return MessageChannel[]
     */
    private function acotarAlAsunto(Message $message, array $canales): array
    {
        $conversacion = $message->getConversation();

        if ($conversacion === null || $canales === []) {
            return $canales;
        }

        $posibles = $this->enlaces->canalesPosibles(
            $conversacion,
            $message->getAsuntoType(),
            $message->getAsuntoId(),
        );

        // Sin acotar, o un hilo sin ningún asunto colgado: no se toca nada.
        if ($posibles === []) {
            return $canales;
        }

        $permitidos = array_values(array_filter(
            $canales,
            static fn (MessageChannel $c): bool => in_array($c->getId(), $posibles, true)
        ));

        if (count($permitidos) !== count($canales)) {
            $this->logger->info('Canales descartados: no existen para el asunto de este mensaje.', [
                'mensaje'   => $message->getId()?->toRfc4122(),
                'asunto'    => $message->getAsuntoType(),
                'pedidos'   => array_map(static fn (MessageChannel $c): ?string => $c->getId(), $canales),
                'posibles'  => $posibles,
            ]);
        }

        return $permitidos;
    }
}