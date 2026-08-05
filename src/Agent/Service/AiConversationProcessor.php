<?php

declare(strict_types=1);

namespace App\Agent\Service;

use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Message\Service\MessageDataResolverRegistry;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Contesta con IA los mensajes de texto libre del huésped.
 *
 * Es la rama `free_text` del IntentRouter: la que hasta ahora marcaba el mensaje como
 * «resuelto» sin hacer nada ni dejar rastro (835 mensajes de Beds24 y 685 de WhatsApp).
 *
 * NO genera y envía sin más: cinco guardias deciden antes si toca callarse. Cada salida
 * devuelve un motivo, que el router guarda en `inbound_intent.resolution` — así se puede
 * medir después cuántos contestó el bot y cuántos se dejaron pasar, y por qué.
 *
 * Ver docs/Mensajeria.md §10.
 */
final readonly class AiConversationProcessor
{
    /** Mensajes de historial que se mandan al modelo. Acota coste y latencia. */
    private const int HISTORIAL_MAX = 20;

    /**
     * Si un operador humano escribió hace menos de esto, el bot no interviene.
     *
     * Es el guardia más importante de todos: nada enfada más a un huésped —ni deja peor al
     * hotel— que un bot pisando a la persona que ya le está atendiendo.
     */
    private const string HUMANO_AL_MANDO = '-30 minutes';

    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private AnthropicClientFactory $anthropic,
        private MessageDataResolverRegistry $resolvers,
        private bool $habilitado,
    ) {}

    /**
     * @return string Motivo de resolución, que acaba en `inbound_intent.resolution`.
     */
    public function process(Message $message): string
    {
        if (!$this->habilitado) {
            return 'ia_desactivada';
        }

        if (!$this->anthropic->estaConfigurado()) {
            $this->logger->warning('IA: habilitada pero sin ANTHROPIC_API_KEY; no se responde.');
            return 'ia_sin_credenciales';
        }

        $conversacion = $message->getConversation();
        if ($conversacion === null) {
            return 'sin_conversacion';
        }

        if ($motivo = $this->motivoParaCallarse($conversacion, $message)) {
            return $motivo;
        }

        try {
            $respuesta = $this->generar($conversacion, $message);
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'IA: fallo generando respuesta para la conversación %s: %s',
                $conversacion->getId(),
                $e->getMessage()
            ));

            return 'error_ia';
        }

        if ($respuesta === null || trim($respuesta) === '') {
            return 'ia_sin_respuesta';
        }

        $this->encolarRespuesta($conversacion, $message, $respuesta);

        return 'ia';
    }

    /**
     * Los guardias. Devuelve el motivo por el que NO hay que contestar, o null para seguir.
     */
    private function motivoParaCallarse(MessageConversation $conversacion, Message $entrante): ?string
    {
        // 1. La conversación ya terminó su ciclo.
        if ($conversacion->getStatus() !== MessageConversation::STATUS_OPEN) {
            return 'conversacion_no_abierta';
        }

        // 2. HUMANO AL MANDO. Si un operador contestó hace poco, la conversación es suya.
        if ($this->hayHumanoAtendiendo($conversacion)) {
            return 'humano_atendiendo';
        }

        // 3. IDEMPOTENCIA. El transporte async reintenta hasta 3 veces (messenger.yaml), y un
        // reintento después de haber enviado duplicaría el mensaje al huésped. Si ya hay una
        // respuesta del sistema posterior a este mensaje, el trabajo está hecho.
        if ($this->yaSeRespondio($conversacion, $entrante)) {
            return 'ya_respondido';
        }

        // 4. VENTANA DE 24 H DE WHATSAPP. Fuera de sesión Meta sólo acepta plantillas
        // aprobadas, así que un texto generado sería rechazado por el enqueuer y acabaría
        // como mensaje FAILED. Mejor no generarlo: ahorra la llamada al modelo y el ruido.
        $canal = (string) ($entrante->getChannel()?->getId() ?? '');
        if ($canal === 'whatsapp_meta' && !$conversacion->isWhatsappSessionActive()) {
            return 'fuera_de_ventana_24h';
        }

        // 5. Canal deshabilitado por rebote duro (número inválido, bloqueo de Meta).
        if ($canal === 'whatsapp_meta' && $conversacion->isWhatsappDisabled()) {
            return 'canal_deshabilitado';
        }

        return null;
    }

    private function hayHumanoAtendiendo(MessageConversation $conversacion): bool
    {
        $desde = new DateTimeImmutable(self::HUMANO_AL_MANDO);

        foreach ($conversacion->getMessages() as $m) {
            if ($m->getSenderType() !== Message::SENDER_HOST) {
                continue;
            }

            $cuando = $m->getScheduledAt() ?? $m->getCreatedAt();
            if ($cuando !== null && $cuando >= $desde) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Hay ya una respuesta automática posterior al mensaje del huésped?
     */
    private function yaSeRespondio(MessageConversation $conversacion, Message $entrante): bool
    {
        $referencia = $entrante->getCreatedAt();
        if ($referencia === null) {
            return false;
        }

        foreach ($conversacion->getMessages() as $m) {
            if ($m->getSenderType() !== Message::SENDER_SYSTEM
                || $m->getDirection() !== Message::DIRECTION_OUTGOING) {
                continue;
            }

            if ($m->getStatus() === Message::STATUS_CANCELLED) {
                continue;
            }

            $cuando = $m->getCreatedAt();
            if ($cuando !== null && $cuando >= $referencia) {
                return true;
            }
        }

        return false;
    }

    /**
     * Llama al modelo con el contexto de la reserva y el hilo reciente.
     */
    private function generar(MessageConversation $conversacion, Message $entrante): ?string
    {
        $cliente = $this->anthropic->crear();
        if ($cliente === null) {
            return null;
        }

        $respuesta = $cliente->messages->create(
            model: $this->anthropic->modelo(),
            maxTokens: 1024,
            system: [[
                'type' => 'text',
                'text' => $this->systemPrompt($conversacion),
                // El prompt de sistema es idéntico para toda la conversación: cachearlo
                // ahorra la mayor parte del coste de entrada a partir del segundo mensaje.
                'cacheControl' => ['type' => 'ephemeral'],
            ]],
            messages: $this->historial($conversacion, $entrante),
        );

        // Los clasificadores pueden declinar una petición: llega un 200 con stopReason
        // 'refusal' y `content` vacío. Leer content[0] sin comprobarlo revienta.
        if ($respuesta->stopReason === 'refusal') {
            $this->logger->warning(sprintf(
                'IA: petición declinada por los clasificadores en la conversación %s.',
                $conversacion->getId()
            ));

            return null;
        }

        foreach ($respuesta->content as $bloque) {
            if ($bloque->type === 'text') {
                return $bloque->text;
            }
        }

        return null;
    }

    private function systemPrompt(MessageConversation $conversacion): string
    {
        $idioma = $conversacion->getIdioma()?->getId() ?? 'es';
        $huesped = $conversacion->getGuestName() ?? 'el huésped';

        $datos = '';
        $resolver = $this->resolvers->getResolver($conversacion->getContextType());
        if ($resolver !== null) {
            $variables = $resolver->getMessageVariables($conversacion->getContextId());
            foreach ($variables as $clave => $valor) {
                if (is_scalar($valor) && (string) $valor !== '') {
                    $datos .= sprintf("- %s: %s\n", $clave, $valor);
                }
            }
        }

        return <<<PROMPT
        Eres el asistente de reservas de un alojamiento en Cusco, Perú. Hablas con {$huesped}
        por el chat de su reserva.

        Responde SIEMPRE en el idioma con código "{$idioma}".

        Datos de la reserva (úsalos, no los inventes ni los repitas enteros):
        {$datos}

        Reglas:
        - Sé breve y concreto: es un chat, no un correo. Dos o tres frases bastan.
        - Responde SOLO con lo que puedas fundamentar en los datos de arriba o en información
          general del alojamiento. Si no lo sabes, dilo y ofrece que un compañero lo confirme.
        - NUNCA inventes precios, disponibilidad, horarios ni políticas que no estén en los datos.
        - No prometas cambios de reserva, reembolsos ni excepciones: eso lo decide una persona.
        - Si el huésped se queja, está molesto o pide algo delicado, no intentes resolverlo:
          discúlpate brevemente y dile que un compañero le atiende enseguida.
        - No menciones que eres una IA salvo que te lo pregunten directamente.
        PROMPT;
    }

    /**
     * Hilo reciente en el formato de la API. El huésped es `user`; todo lo que salió del
     * alojamiento —operador, plantilla automática o el propio bot— es `assistant`.
     *
     * @return list<array{role: string, content: string}>
     */
    private function historial(MessageConversation $conversacion, Message $entrante): array
    {
        $mensajes = [];

        foreach ($conversacion->getMessages() as $m) {
            if ($m->getStatus() === Message::STATUS_CANCELLED) {
                continue;
            }

            $texto = trim((string) ($m->getContentExternal() ?? $m->getContentLocal() ?? ''));
            if ($texto === '') {
                continue;
            }

            $mensajes[] = [
                'role' => $m->getDirection() === Message::DIRECTION_INCOMING ? 'user' : 'assistant',
                'content' => $texto,
            ];
        }

        $mensajes = array_slice($mensajes, -self::HISTORIAL_MAX);

        // La API exige que el hilo empiece por `user` y termine por `user`. Recortar por el
        // final puede dejar un `assistant` al principio, y el mensaje que dispara todo esto
        // puede no estar aún en la colección si el flush no lo ha materializado.
        while ($mensajes !== [] && $mensajes[0]['role'] !== 'user') {
            array_shift($mensajes);
        }

        $ultimo = trim((string) ($entrante->getContentExternal() ?? ''));
        if ($mensajes === [] || end($mensajes)['content'] !== $ultimo) {
            if ($ultimo !== '') {
                $mensajes[] = ['role' => 'user', 'content' => $ultimo];
            }
        }

        return $mensajes;
    }

    /**
     * Crea el mensaje de respuesta. No lo envía: al persistirlo, el
     * MessageEnqueuerEntityListener genera las colas del canal por el que llegó la consulta.
     */
    private function encolarRespuesta(MessageConversation $conversacion, Message $entrante, string $texto): void
    {
        $canal = $entrante->getChannel();

        $salida = new Message();
        $salida->setConversation($conversacion);
        $salida->setChannel($canal);
        $salida->setTransientChannels($canal !== null ? [(string) $canal->getId()] : []);
        $salida->setDirection(Message::DIRECTION_OUTGOING);
        $salida->setSenderType(Message::SENDER_SYSTEM);
        $salida->setStatus(Message::STATUS_PENDING);
        $salida->setContentLocal($texto);
        $salida->setContentExternal($texto);
        $salida->setLanguageCode($conversacion->getIdioma()?->getId() ?? 'es');
        $salida->addMetadata('generado_por', 'ia');

        $conversacion->addMessage($salida);
        $this->em->persist($salida);

        $this->logger->info(sprintf(
            'IA: respuesta encolada para la conversación %s por el canal %s.',
            $conversacion->getId(),
            $canal?->getId() ?? '¿?'
        ));
    }
}
