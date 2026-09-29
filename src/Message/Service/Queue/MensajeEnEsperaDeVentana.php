<?php

declare(strict_types=1);

namespace App\Message\Service\Queue;

use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageTemplate;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Translate\GoogleTranslateService;
use Psr\Log\LoggerInterface;
use Throwable;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * Lo que el operador escribe para WhatsApp con la ventana de 24 h cerrada: se guarda, se pide
 * permiso con una plantilla corta y sale solo cuando el cliente contesta.
 *
 * ── Por qué no una plantilla genérica con el texto dentro ───────────────────
 * Era lo primero que se pidió (28/09/2026, la cotización de Eduardo: tres párrafos y un enlace,
 * ventana cerrada desde el 21/09). No sirve por tres motivos: Meta revisa una plantilla por su
 * texto FIJO y una que es casi todo variable es justo la que se usa para saltarse la ventana; los
 * parámetros no admiten saltos de línea; y el mismo marco valdría para una cotización, un cobro o
 * una promoción, así que su categoría sería una lotería.
 *
 * ```
 * operador escribe → WhatsApp 🔒 → «Enviar cuando conteste»
 *        │
 *        ├─ su mensaje: EN ESPERA, sin cola (se ve en el hilo)
 *        └─ plantilla `mensaje_pendiente`: «Tengo una respuesta para ti. ¿Te la envío por aquí?»
 *                                          [ Sí, envíamela ]
 * cliente pulsa o escribe → se abre la ventana → liberar(): el mensaje sale tal cual
 * ```
 *
 * ⚠️ **Una sola plantilla por espera.** Si ya hay algo esperando en el hilo y su aviso salió, el
 * mensaje nuevo se suma sin mandar otro: dos «¿te lo envío?» seguidos es lo contrario de parecer
 * una persona. Si el aviso NO se puede mandar (plantilla sin aprobar en su idioma), el mensaje no
 * espera: queda `failed` con el motivo, para que se vea en rojo y avise al equipo.
 */
final readonly class MensajeEnEsperaDeVentana
{
    /** El aviso genérico, sin variables. La crea `msg:plantillas:mensaje-pendiente`. */
    public const string PLANTILLA = 'mensaje_pendiente';

    /**
     * El aviso que dice SOBRE QUÉ es la respuesta: «ya tenemos la respuesta sobre {{referencia}}».
     *
     * Existe porque Meta pasó `mensaje_pendiente_v1` de UTILITY a MARKETING (28/09/2026): una
     * plantilla que no nombra ninguna operación concreta es, para su clasificador, un gancho para
     * reabrir la conversación. Se prefiere ésta cuando está aprobada en el idioma del hilo y hay
     * referencia y nombre; si no, la genérica.
     */
    public const string PLANTILLA_CON_REFERENCIA = 'respuesta_pendiente';

    /** Lo que cabe en una variable de Meta sin romper la frase: una línea corta. */
    private const int MAX_REFERENCIA = 60;

    /** Lo que manda su botón. Sin regla de autorespuesta a propósito: la respuesta ES lo liberado. */
    public const string PAYLOAD_BOTON = 'CMD_ENVIAR_PENDIENTE';

    private const string CANAL = 'whatsapp_meta';

    public function __construct(
        private EntityManagerInterface $em,
        private MessageDispatcher $dispatcher,
        private LoggerInterface $logger,
        private GoogleTranslateService $traductor,
    ) {}

    /**
     * Decide, al crearse un mensaje `en_espera`, si de verdad tiene que esperar.
     *
     * Lo llama `MessageEnqueuerEntityListener::prePersist()` ANTES de fabricar colas: si la
     * ventana resulta estar abierta —el cliente escribió mientras el operador tecleaba—, el
     * mensaje vuelve a `pending` y sale por el camino normal en ese mismo guardado.
     */
    public function retener(Message $mensaje): void
    {
        $hilo = $mensaje->getConversation();

        if ($hilo === null
            || $mensaje->getDirection() !== Message::DIRECTION_OUTGOING
            || $hilo->isWhatsappSessionActive()) {
            $mensaje->setStatus(Message::STATUS_PENDING);

            return;
        }

        // Sólo WhatsApp: la espera es de SU ventana. Por Beds24 o correo no hay nada que esperar,
        // y un mensaje tiene un solo estado — mezclarlos dejaría uno de los dos colgado.
        $mensaje->setTransientChannels([self::CANAL]);

        $referencia = $this->limpiarReferencia($mensaje->getReferenciaEspera());
        $avisoVigente = $this->avisoVigente($hilo, $mensaje);

        if ($avisoVigente !== null) {
            // Ya se le pidió permiso y el aviso salió: éste espera con los demás.
            $mensaje->addMetadata('en_espera', ['aviso' => (string) $avisoVigente->getId(), 'referencia' => $referencia]);

            return;
        }

        $idioma = $this->idiomaDePlantilla($hilo);
        $nombre = $this->nombreDePila($hilo);
        [$plantilla, $variables] = $this->elegirAviso($idioma, $referencia, $nombre);

        // ❌ Sin aviso que se pueda mandar, NO se espera: se da por no salido, en rojo y con el
        // motivo. Esperar en silencio era lo peor de los dos mundos —el chat decía «esperando a
        // que conteste» sin que nadie le hubiera pedido nada—, y así además salta el aviso de
        // envío fallido al equipo (`AvisoEnvioFallidoListener`). Pasaba de verdad: Meta aprueba
        // la plantilla idioma por idioma, y el inglés tardó más que el resto (revisión del
        // 28/09/2026).
        if (!$plantilla instanceof MessageTemplate) {
            $motivo = sprintf('ningún aviso («%s», «%s») está aprobado por Meta en «%s»', self::PLANTILLA_CON_REFERENCIA, self::PLANTILLA, $idioma);

            $mensaje->setStatus(Message::STATUS_FAILED);
            $mensaje->addMetadata('dispatch_errors', [sprintf(
                'La ventana de WhatsApp está cerrada y no se le puede pedir permiso: %s. Envíalo con una plantilla o por otro canal.',
                $motivo
            )]);
            $this->logger->warning('Mensaje en espera sin aviso posible: ' . $motivo, [
                'mensaje' => (string) $mensaje->getId(),
            ]);

            return;
        }

        $aviso = (new Message())
            ->setDirection(Message::DIRECTION_OUTGOING)
            ->setSenderType(Message::SENDER_HOST)
            ->setStatus(Message::STATUS_PENDING)
            ->setTemplate($plantilla)
            ->setLanguageCode((string) $hilo->getIdioma()->getId())
            ->setTransientChannels([self::CANAL]);
        $aviso->addMetadata('aviso_de_mensaje_en_espera', (string) $mensaje->getId());

        // Las del propio mensaje ganan a las del resolver del hilo: la referencia no sale del
        // contexto, y el nombre tampoco en un hilo sin resolutor (ver Message::getVariablesPlantilla()).
        if ($variables !== []) {
            $aviso->setVariablesPlantilla($variables);
        }

        $hilo->addMessage($aviso);
        // Dentro del `prePersist` del mensaje del operador: un `persist()` aquí dispara el
        // `prePersist` del aviso, que fabrica su cola, y los dos entran en el mismo flush.
        $this->em->persist($aviso);

        $mensaje->addMetadata('en_espera', ['aviso' => (string) $aviso->getId(), 'referencia' => $referencia]);
    }

    /**
     * Qué aviso sale y con qué variables: el que nombra la referencia si se puede, si no el
     * genérico. `null` como plantilla = ninguno de los dos está aprobado en ese idioma.
     *
     * @return array{0: ?MessageTemplate, 1: array<string, string>}
     */
    private function elegirAviso(string $idioma, ?string $referencia, ?string $nombre): array
    {
        $repo = $this->em->getRepository(MessageTemplate::class);

        if ($referencia !== null && $nombre !== null) {
            $conReferencia = $repo->findOneBy(['code' => self::PLANTILLA_CON_REFERENCIA]);

            if ($conReferencia instanceof MessageTemplate && $conReferencia->hasWhatsappMetaOfficialData($idioma)) {
                return [$conReferencia, ['guest_name' => $nombre, 'referencia' => $this->alIdiomaDelAviso($referencia, $idioma)]];
            }
        }

        $generica = $repo->findOneBy(['code' => self::PLANTILLA]);

        if ($generica instanceof MessageTemplate && $generica->hasWhatsappMetaOfficialData($idioma)) {
            return [$generica, []];
        }

        return [null, []];
    }

    /**
     * Una línea, sin saltos ni espacios repetidos, y corta: Meta rechaza una variable con saltos
     * de línea, y una larga rompe la frase del aviso.
     */
    private function limpiarReferencia(?string $referencia): ?string
    {
        $limpia = trim((string) preg_replace('/\s+/u', ' ', (string) $referencia));

        if ($limpia === '') {
            return null;
        }

        return mb_strlen($limpia) > self::MAX_REFERENCIA
            ? rtrim(mb_substr($limpia, 0, self::MAX_REFERENCIA - 1)) . '…'
            : $limpia;
    }

    /**
     * La referencia la escribe el operador en español, y el aviso sale en el idioma del hilo: sin
     * traducir, un huésped inglés leería «we have the answer regarding los tours que pediste».
     * Si el traductor falla, va tal cual: mejor mezclado que sin aviso.
     */
    private function alIdiomaDelAviso(string $referencia, string $idioma): string
    {
        if ($idioma === 'es') {
            return $referencia;
        }

        try {
            $traducida = $this->traductor->translate([$referencia], $idioma, 'es')[0] ?? null;

            return is_string($traducida) && trim($traducida) !== '' ? $this->limpiarReferencia($traducida) ?? $referencia : $referencia;
        } catch (Throwable $e) {
            $this->logger->warning('No se pudo traducir la referencia del aviso: ' . $e->getMessage());

            return $referencia;
        }
    }

    /** El nombre de pila del hilo; sin nombre, el aviso con referencia no sale («Hola ,»). */
    private function nombreDePila(MessageConversation $hilo): ?string
    {
        $nombre = trim((string) $hilo->getGuestName());

        if ($nombre === '') {
            return null;
        }

        return explode(' ', $nombre)[0];
    }

    /**
     * Suelta lo que esperaba en el hilo. Lo llama la recepción de WhatsApp en cuanto el cliente
     * escribe o pulsa, con la ventana ya abierta. NO hace flush: lo hace quien llama.
     *
     * Las colas se piden aquí y no en el `preUpdate` del mensaje: una entidad persistida dentro
     * de `preUpdate` no entra en ese mismo flush (la misma trampa que obligó a hacerlo así al
     * revivir `sin_canal`, ver `MessageRuleEngine::syncPendingMessage()`).
     *
     * @return int Cuántos mensajes salieron.
     */
    public function liberar(MessageConversation $hilo): int
    {
        $ahora = new DateTimeImmutable();
        $liberados = 0;

        // 🔒 Con bloqueo de fila: si Meta repite el webhook mientras el primero sigue vivo, los
        // dos pasarían la deduplicación y los dos soltarían el mismo mensaje — una cotización
        // enviada dos veces. El segundo espera aquí al commit del primero y ya no encuentra nada.
        foreach ($this->esperando($hilo, bloquear: true) as $mensaje) {
            $mensaje->setStatus(Message::STATUS_PENDING);
            $mensaje->setTransientChannels([self::CANAL]);
            // Ocurre AHORA, no cuando se escribió: en el hilo tiene que quedar debajo de la
            // respuesta del cliente, que es cuando de verdad le llegó.
            $mensaje->setScheduledAt($ahora);
            $mensaje->addMetadata('en_espera_liberado', $ahora->format(DATE_ATOM));

            foreach ($this->dispatcher->dispatch($mensaje) as $cola) {
                $mensaje->addQueue($cola);
                $this->em->persist($cola);
            }

            ++$liberados;
        }

        return $liberados;
    }

    /**
     * El aviso que ya pidió permiso por lo que espera en el hilo, si salió.
     *
     * No basta con que haya algo esperando: si su aviso falló, nadie le ha preguntado nada al
     * cliente y hay que volver a intentarlo.
     */
    private function avisoVigente(MessageConversation $hilo, Message $nuevo): ?Message
    {
        foreach ($this->esperando($hilo) as $mensaje) {
            if ($mensaje === $nuevo) {
                continue;
            }

            $espera = $mensaje->getMetadata()['en_espera'] ?? null;
            $id = is_array($espera) ? ($espera['aviso'] ?? null) : null;

            if (!is_string($id) || !\Symfony\Component\Uid\Uuid::isValid($id)) {
                continue;
            }

            $aviso = $this->em->find(Message::class, \Symfony\Component\Uid\Uuid::fromString($id));

            if ($aviso instanceof Message
                && !in_array($aviso->getStatus(), [...Message::ESTADOS_NO_SALIO, Message::STATUS_CANCELLED], true)) {
                return $aviso;
            }
        }

        return null;
    }

    /**
     * El idioma en que saldrá el aviso: el mismo cálculo que hace el envío
     * (`WhatsappMetaSendMappingStrategy`), porque es el que Meta tiene que tener aprobado.
     */
    private function idiomaDePlantilla(MessageConversation $hilo): string
    {
        $idioma = $hilo->getIdioma();

        return $idioma->getPrioridad() > 0 ? strtolower((string) $idioma->getId()) : 'en';
    }

    /**
     * Los que esperan en el hilo, del más viejo al más nuevo: salen en el orden en que se
     * escribieron.
     *
     * @return list<Message>
     */
    private function esperando(MessageConversation $hilo, bool $bloquear = false): array
    {
        $consulta = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Message::class, 'm')
            ->where('m.conversation = :hilo')
            ->andWhere('m.status = :espera')
            ->andWhere('m.direction = :saliente')
            ->setParameter('hilo', $hilo->getId(), UuidType::NAME)
            ->setParameter('espera', Message::STATUS_EN_ESPERA)
            ->setParameter('saliente', Message::DIRECTION_OUTGOING)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery();

        // El bloqueo exige transacción; la recepción de WhatsApp corre dentro de una.
        if ($bloquear && $this->em->getConnection()->isTransactionActive()) {
            $consulta->setLockMode(\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
        }

        /** @var list<Message> $mensajes */
        $mensajes = $consulta->getResult();

        return $mensajes;
    }
}
