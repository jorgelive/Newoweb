<?php

declare(strict_types=1);

namespace App\Message\Service\Exchange\Tasks\WhatsappMetaReceive;

use App\Entity\Maestro\MaestroIdioma;
use App\Exchange\Entity\MetaConfig;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Entity\MessageConversation;
use App\Message\Factory\MessageAttachmentFactory;
use App\Message\Service\MessageJsonMerger;
use App\Message\Service\Translation\GuestLanguageDetectorService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Motor central de persistencia para WhatsApp Meta.
 * SRP: Recibe, detecta idioma localmente, enruta intención, bloquea canal si hay rebote duro y persiste rápido.
 */
readonly class WhatsappMetaReceivePersister
{
    public function __construct(
        private EntityManagerInterface       $em,
        private MessageAttachmentFactory     $attachmentFactory,
        private HttpClientInterface          $httpClient,
        private LoggerInterface              $logger,
        private MessageJsonMerger            $merger,
        private GuestLanguageDetectorService $languageDetector
    ) {}

    /**
     * Procesa un evento entrante (mensaje de texto, multimedia, botones o reacciones) de un huésped.
     * * ¿Por qué existe este método?
     * Es el punto de entrada único para los webhooks de Meta. Se encarga de la deduplicación,
     * resolución de la conversación (ya sea asociándola a una reserva activa o creando un Walk-in),
     * y de la persistencia inicial del mensaje en la base de datos para que la UI pueda reaccionar.
     * * Efectos secundarios:
     * - Puede crear una nueva entidad MessageConversation si el remitente no existe.
     * - Puede actualizar el idioma de la conversación si detecta un cambio.
     * - Activa la ventana de sesión de 24 horas de WhatsApp Meta al persistir un mensaje nuevo.
     * * @param array $messageData El nodo 'messages[0]' del payload del webhook de Meta.
     * @param array $contactData El nodo 'contacts[0]' del payload del webhook de Meta.
     */
    public function upsertInboundMessage(array $messageData, array $contactData): void
    {
        $metaMessageId = $messageData['id'] ?? null;
        if (!$metaMessageId) return;

        // 1. Deduplicación en BD (Evita duplicados si Meta reenvía el Webhook por delays)
        $existingMessage = $this->findMessageByMetaId($metaMessageId);
        if ($existingMessage) return;

        $type = $messageData['type'] ?? 'text';

        // =====================================================================
        // CASO ESPECIAL: REACCIONES (Mutación atómica, no crea mensaje nuevo)
        // =====================================================================
        if ($type === 'reaction') {
            $this->handleReaction($messageData, $contactData);
            return;
        }

        // 2. Extraer Remitente
        $guestPhone = $contactData['wa_id'] ?? null;
        if (!$guestPhone) return;

        $guestName = $contactData['profile']['name'] ?? 'Desconocido (WhatsApp)';

        // 3. Resolución de Conversación
        $conversation = $this->resolveConversation($guestPhone, $guestName);

        // Es imperativo instanciar el canal antes de agregarlo, ya que la entidad
        // MessageConversation evalúa el ID del canal para abrir la ventana de 24 horas.
        $channel = $this->em->getReference(MessageChannel::class, 'whatsapp_meta');

        // 4. Instanciar Mensaje
        $message = new Message();
        $message->setChannel($channel);
        $message->setDirection(Message::DIRECTION_INCOMING);
        $message->setSenderType(Message::SENDER_GUEST);
        $message->setStatus(Message::STATUS_RECEIVED);
        $message->setWhatsappMetaExternalId($metaMessageId);

        // Fecha original en la que el usuario envió el mensaje
        $timestamp = $messageData['timestamp'] ?? time();
        $message->setCreatedAt(new DateTimeImmutable()->setTimestamp((int)$timestamp));

        // =====================================================================
        // 5. PROCESAMIENTO DE CONTENIDO
        // =====================================================================
        $currentConversationLang = $conversation->getIdioma()?->getId() ?? 'es';

        $baseIntent = [
            'context_type'   => $conversation->getContextType(),
            'context_id'     => $conversation->getContextId(),
            'source_channel' => 'whatsapp_meta',
            'source_ota'     => $conversation->getContextOrigin(),
            'resolved'       => false
        ];

        if ($type === 'text') {
            $textoRecibido = trim($messageData['text']['body'] ?? '');

            // 🔥 PROTECCIÓN DE LONGITUD: Evitamos el "Data too long" en MySQL
            $safeContent = mb_substr($textoRecibido, 0, 60000, 'UTF-8');
            if (mb_strlen($textoRecibido, 'UTF-8') > 60000) {
                $safeContent .= '... [CONTENIDO TRUNCADO POR SEGURIDAD]';
            }

            $message->setContentExternal($safeContent);
            // ELIMINADO: $message->setContentLocal($textoRecibido); para permitir traducción automática.

            // =================================================================
            // 🧠 DETECCIÓN DINÁMICA DE IDIOMA (Blindada y Normalizada)
            // =================================================================
            $detectedLangCode = 'es';
            if (!empty($textoRecibido)) {
                $rawDetected = $this->languageDetector->detectLanguageCode($textoRecibido, $currentConversationLang);

                // 🔥 Normalizamos extrayendo solo las 2 primeras letras (ej: 'en-US' -> 'en')
                $iso2LangCode = substr(strtolower($rawDetected), 0, 2);

                $repoIdioma = $this->em->getRepository(MaestroIdioma::class);

                // Buscamos el detectado, o aplicamos fallback a inglés
                $newIdiomaEntity = $repoIdioma->find($iso2LangCode) ?? $repoIdioma->find('en');

                if ($newIdiomaEntity instanceof MaestroIdioma) {
                    $detectedLangCode = $newIdiomaEntity->getId();

                    // Solo actualizamos la conversación si el idioma es válido y diferente
                    if ($detectedLangCode !== $currentConversationLang) {
                        $conversation->setIdioma($newIdiomaEntity);
                    }
                }
            }

            $message->setLanguageCode($detectedLangCode);

            $intent = array_merge($baseIntent, [
                'category'    => 'free_text',
                'action_code' => 'TXT_FREE'
            ]);

            // 🎯 INTERCEPTOR DE MENÚS (Basado estrictamente en el último estado absoluto)
            if (strlen($textoRecibido) <= 20) {
                if (preg_match('/^(?:opci[oó]n|opc|opt|option|n[uú]mero|num|#)?\s*(\d{1,2})$/i', $textoRecibido, $matches)) {
                    $opcionElegida = (int) $matches[1];

                    // Buscamos el ABSOLUTO ÚLTIMO mensaje (sin importar dirección ni tipo)
                    $lastMessage = $this->em->getRepository(Message::class)->findOneBy(
                        ['conversation' => $conversation],
                        ['createdAt' => 'DESC']
                    );

                    // Solo procesamos si el mensaje INMEDIATAMENTE ANTERIOR fue la plantilla
                    if ($lastMessage && $lastMessage->getTemplate() !== null) {
                        $metaJson = $lastMessage->getTemplate()->getWhatsappMetaTmpl();
                        $quickReplies = array_filter(
                            $metaJson['buttons_map'] ?? [],
                            fn($b) => strtolower($b['type'] ?? '') === 'quick_reply'
                        );
                        $quickRepliesList = array_values($quickReplies);

                        if (isset($quickRepliesList[$opcionElegida - 1])) {
                            $matchedButton = $quickRepliesList[$opcionElegida - 1];
                            $payloadOriginal = $matchedButton['payload'] ?? $matchedButton['resolver_key'] ?? null;

                            if ($payloadOriginal) {
                                $intent['category'] = 'deterministic';
                                $intent['action_code'] = $payloadOriginal;
                            }
                        }
                    }
                }
            }
            $message->setInboundIntent($intent);

        } elseif ($type === 'button') {
            $payload = $messageData['button']['payload'] ?? 'BTN_UNKNOWN';
            $btnText = $messageData['button']['text'] ?? 'Botón';
            $textoBoton = "🔘 [Respuesta rápida]: " . $btnText;

            $message->setContentExternal($textoBoton);
            // ELIMINADO: $message->setContentLocal($textoBoton);
            $message->setLanguageCode($currentConversationLang);

            $message->setInboundIntent(array_merge($baseIntent, [
                'category'    => 'deterministic',
                'action_code' => $payload
            ]));

        } elseif ($type === 'interactive') {
            // Respuestas a botones nuevos o listas de opciones
            $intType = $messageData['interactive']['type'] ?? '';
            $textoInt = '🤖 [Interacción no soportada]';
            $payload = 'UNKNOWN';

            if ($intType === 'button_reply') {
                $payload = $messageData['interactive']['button_reply']['id'] ?? 'BTN_UNKNOWN';
                $textoInt = "🔘 [Botón interactivo]: " . ($messageData['interactive']['button_reply']['title'] ?? 'Botón');
            } elseif ($intType === 'list_reply') {
                $payload = $messageData['interactive']['list_reply']['id'] ?? 'LST_UNKNOWN';
                $textoInt = "📋 [Opción de lista]: " . ($messageData['interactive']['list_reply']['title'] ?? 'Opción');
            }

            $message->setContentExternal($textoInt);
            // ELIMINADO: $message->setContentLocal($textoInt);
            $message->setLanguageCode($currentConversationLang);

            $message->setInboundIntent(array_merge($baseIntent, [
                'category'    => 'deterministic',
                'action_code' => $payload
            ]));

        } elseif (in_array($type, ['image', 'document', 'audio', 'video', 'sticker'])) {
            // Manejo de Archivos Multimedia
            $mediaId = $messageData[$type]['id'] ?? null;
            $mimeType = $messageData[$type]['mime_type'] ?? 'application/octet-stream';
            $fileName = $messageData[$type]['filename'] ?? ($type . '_' . uniqid() . '.file');
            $textoMedia = "🚫 [Archivo multimedia sin ID válido]";

            if ($mediaId) {
                // Descargamos el binario de los servidores de Meta
                $base64Data = $this->downloadMediaFromMeta($mediaId);
                if ($base64Data) {
                    try {
                        $attachment = $this->attachmentFactory->createFromBase64($base64Data, $fileName, $mimeType);
                        $message->addAttachment($attachment);
                        $this->em->persist($attachment);
                        $textoMedia = "📦 [" . ucfirst($type) . " recibido]";

                        $message->setInboundIntent(array_merge($baseIntent, [
                            'category'    => 'free_text',
                            'action_code' => 'MULTIMEDIA'
                        ]));
                    } catch (Throwable $e) {
                        $this->logger->error("Error creando adjunto WA: " . $e->getMessage());
                        $textoMedia = "🚫 [Error procesando archivo multimedia]";
                    }
                } else {
                    $textoMedia = "🚫 [Archivo multimedia expirado o inaccesible]";
                }
            }

            $message->setContentExternal($textoMedia);
            // ELIMINADO: $message->setContentLocal($textoMedia);
            $message->setLanguageCode($currentConversationLang);

        } elseif ($type === 'location') {
            $lat = $messageData['location']['latitude'] ?? '';
            $lng = $messageData['location']['longitude'] ?? '';
            $textoLoc = "📍 [Ubicación compartida]: https://maps.google.com/?q={$lat},{$lng}";

            $message->setContentExternal($textoLoc);
            // ELIMINADO: $message->setContentLocal($textoLoc);
            $message->setLanguageCode($currentConversationLang);
        } else {
            $textoFail = "🤖 [Tipo de mensaje no soportado: {$type}]";
            $message->setContentExternal($textoFail);
            // ELIMINADO: $message->setContentLocal($textoFail);
            $message->setLanguageCode($currentConversationLang);
        }

        // =====================================================================
        // 6. GUARDAR Y NOTIFICAR
        // =====================================================================

        // 🔥 MAGIA DE LA ENTIDAD: Al hacer addMessage, la entidad MessageConversation
        // automáticamente incrementa los no leídos, actualiza el lastInboundAt y,
        // al ser un mensaje de whatsapp_meta, activa la sesión de 24 horas.
        $conversation->addMessage($message);
        $this->em->persist($message);

        // El flush() local se mantiene, pero el commit() lo hará el FastTrackService
        $this->em->flush();
    }

    /**
     * Procesa y persiste una reacción a un mensaje existente utilizando un merge atómico.
     * * ¿Por qué existe este método?
     * Para evitar crear filas basura en la base de datos por cada "👍". Este método
     * inyecta la reacción directamente en el JSON de metadatos del mensaje original.
     * Utiliza el número de teléfono como clave para soportar múltiples reacciones
     * y permitir deduplicación/eliminación natural mediante JSON_MERGE_PATCH.
     * * Ejemplo de JSON resultante en BD:
     * {"reactions": {"51970393305": "👍", "51999888777": "❤️"}}
     * Si el valor recibido es vacío (''), JSON_MERGE_PATCH recibirá un null y eliminará la clave.
     * * @param array $messageData Datos de la reacción provenientes de Meta.
     * @param array $contactData Datos del remitente para extraer el wa_id.
     */
    private function handleReaction(array $messageData, array $contactData): void
    {
        $targetMetaId = $messageData['reaction']['message_id'] ?? null;
        $emoji = $messageData['reaction']['emoji'] ?? null;
        $reactorPhone = $contactData['wa_id'] ?? 'unknown';

        if (!$targetMetaId) return;

        // Buscamos el mensaje al que se reaccionó
        $originalMessage = $this->findMessageByMetaId($targetMetaId);

        if ($originalMessage) {
            // Si el emoji viene vacío, Meta indica que el usuario removió la reacción.
            // Al pasar null, JSON_MERGE_PATCH borrará físicamente esa clave del JSON.
            $reactionValue = $emoji !== '' ? $emoji : null;

            $metaDataToMerge = [
                'reactions' => [
                    $reactorPhone => $reactionValue
                ]
            ];

            // Mutación Atómica en BD delegada al motor de base de datos
            $this->merger->merge(
                $originalMessage,
                'whatsappMeta',
                $metaDataToMerge
            );

            // 🔥 SOLUCIÓN: Hacemos un "touch" al mensaje para forzar el UoW de Doctrine.
            // Esto marca la entidad como "sucia" (dirty) y obligará a Doctrine a disparar
            // el evento postUpdate, lo que a su vez detonará tu MercureBroadcaster.
            $originalMessage->setUpdatedAt(new DateTimeImmutable());

            // Actualizamos la conversación para que suba en el listado visual (Touch)
            $conversation = $originalMessage->getConversation();
            if ($conversation !== null) {
                $conversation->setLastInboundAt(new DateTimeImmutable());
                $this->em->flush();
            }
        }
    }

    /**
     * Actualiza la metadata (Enviado, Entregado, Leído, Fallido) de un mensaje saliente.
     * * ¿Por qué existe este método?
     * Los webhooks de estado de Meta son asíncronos. Este método recibe el status
     * y actualiza el estado global del mensaje, gestionando además alertas críticas
     * (como baneos o ventanas cerradas) y bloqueando el canal si el error es permanente.
     * * PROTECCIÓN ACTIVA: Usa bloqueo pesimista o merge atómico dependiendo de la implementación.
     * Se apoya en la transacción del FastTrackService.
     */
    public function updateMessageStatus(array $statusData): void
    {
        $metaMessageId = $statusData['id'] ?? null;
        $status = $statusData['status'] ?? null;
        $timestamp = $statusData['timestamp'] ?? null;

        if (!$metaMessageId || !$status) return;

        // Buscamos el mensaje original en nuestra BD usando el ID de Meta
        $message = $this->findMessageByMetaId($metaMessageId);
        if (!$message) return;

        $isoDate = $timestamp
            ? new DateTimeImmutable("@$timestamp")->format('Y-m-d\TH:i:s\Z')
            : new DateTimeImmutable()->format('Y-m-d\TH:i:s\Z');

        $metaDataToMerge = [];
        $newStatus = null;

        // Preparar Datos
        if ($status === 'read') {
            if (!$message->getWhatsappMetaReadAt()) {
                $metaDataToMerge['read_at'] = $isoDate;
                if ($message->getStatus() !== Message::STATUS_READ) {
                    $newStatus = Message::STATUS_READ;
                }
            }
        } elseif ($status === 'delivered') {
            if (!$message->getWhatsappMetaDeliveredAt()) {
                $metaDataToMerge['delivered_at'] = $isoDate;
            }
        } elseif ($status === 'sent') {
            if (!$message->getWhatsappMetaSentAt()) {
                $metaDataToMerge['sent_at'] = $isoDate;
            }
        } elseif ($status === 'failed') {
            $errorInfo = $statusData['errors'][0] ?? [];
            $errorCode = (string)($errorInfo['code'] ?? 'unknown');

            $metaDataToMerge['error_code'] = $errorCode;
            $metaDataToMerge['error_reason'] = $errorInfo['message'] ?? json_encode($statusData['errors'] ?? [], JSON_UNESCAPED_UNICODE);

            // Si falla, aquí sí es válido forzar el status global a FAILED
            // para que visualmente resalte si no fue leído por el otro canal.
            if ($message->getStatus() !== Message::STATUS_READ) {
                $newStatus = Message::STATUS_FAILED;
            }

            // 🚩 ALERTAS DE ENRUTAMIENTO Y BLOQUEO CRÍTICO
            $criticalErrors = [
                '131026', // El número no existe (No perder más tiempo aquí)
                '131051', // Bloqueo por Salud del Ecosistema (Peligro de baneo)
                '131049', // Ventana cerrada (Obligatorio usar plantilla)
                '131056', // Sandbox / No verificado
                '131030', // Recipiente inválido
            ];

            if (in_array($errorCode, $criticalErrors, true)) {
                $metaDataToMerge['inbound_intent'] = [
                    'category'       => 'system_alert',
                    'action_code'    => 'ERR_' . $errorCode,
                    'source_channel' => 'whatsapp_meta',
                    'context_id'     => $message->getConversation()->getContextId(),
                    'resolved'       => false,
                    'payload'        => [
                        'error_message' => $metaDataToMerge['error_reason'] ?? 'Error desconocido'
                    ]
                ];
            }

            // 🛑 BLOQUEO PERMANENTE DEL CANAL EN LA CONVERSACIÓN
            $permanentErrors = ['131026', '131051', '131030'];
            if (in_array($errorCode, $permanentErrors, true)) {
                $conversation = $message->getConversation();
                if ($conversation !== null) {
                    // Solo actualizamos si no estaba ya deshabilitado para no sobreescribir el primer motivo original
                    if (!$conversation->isWhatsappDisabled()) {
                        $conversation->setWhatsappDisabled(true);
                        $conversation->setWhatsappDisabledReason(sprintf('Meta Error %s: %s', $errorCode, $metaDataToMerge['error_reason'] ?? 'Número inválido'));
                        $this->em->persist($conversation);
                    }
                }
            }
        }

        if (!empty($metaDataToMerge)) {
            // Operación Atómica para fusionar los datos de Webhook sin borrar lo de Beds24
            $this->merger->merge(
                $message,
                'whatsappMeta',
                $metaDataToMerge,
                'whatsapp_meta', // Se asume que externalIdKey es 'whatsapp_meta'
                $metaMessageId   // Se asume que externalIdValue es el ID de Meta
            );
        }

        // 2. Transiciones PHP + Touch
        if ($newStatus) {
            $message->setStatus($newStatus);
        } elseif (!empty($metaDataToMerge)) {
            // Touch explícito si hubo un merge pero no cambió el Status (ej. Delivered)
            $message->setUpdatedAt(new DateTimeImmutable());
        }
    }

    /**
     * Registra una llamada perdida.
     * * ¿Por qué existe este método?
     * La API Cloud de WhatsApp no soporta audio/video llamadas. Cuando un usuario
     * intenta llamar, Meta envía un evento de tipo 'call' (o un mensaje de sistema
     * indicando llamada perdida). Este método materializa ese evento como un mensaje
     * informativo en la UI para alertar al anfitrión.
     */
    public function processCall(array $callData, array $contactData): void
    {
        $guestPhone = $contactData['wa_id'] ?? null;
        if (!$guestPhone) return;

        $guestName = $contactData['profile']['name'] ?? 'Huésped';
        $conversation = $this->resolveConversation($guestPhone, $guestName);

        $message = new Message();
        $message->setConversation($conversation);
        $message->setDirection(Message::DIRECTION_INCOMING);
        $message->setSenderType(Message::SENDER_SYSTEM);
        $message->setStatus(Message::STATUS_RECEIVED);
        $message->setContentExternal("📞 [Llamada perdida]: El huésped intentó llamarte por WhatsApp.");
        // ELIMINADO: $message->setContentLocal("📞 [Llamada perdida]: El huésped intentó llamarte por WhatsApp.");
        $message->setLanguageCode($conversation->getIdioma()?->getId() ?? 'es');

        $timestamp = $callData['timestamp'] ?? time();
        $message->setCreatedAt(new DateTimeImmutable()->setTimestamp((int)$timestamp));

        $this->em->persist($message);
        $this->em->flush();
    }

    /**
     * Busca una conversación abierta por teléfono, o crea una nueva genérica (Walk-in).
     * Implementa un "Fuzzy Match" para sortear números sucios provenientes de OTAs (ej. doble código de país).
     *
     * @param string $phone El número de teléfono del remitente (wa_id exacto de Meta).
     * @param string $guestName El nombre extraído del perfil de WhatsApp.
     * @return MessageConversation
     */
    private function resolveConversation(string $phone, string $guestName): MessageConversation
    {
        $repo = $this->em->getRepository(MessageConversation::class);

        // A. Búsqueda Exacta (El escenario ideal)
        // Búsqueda Unificada: Prioriza el número exacto o el "corazón" del número (fallback para OTAs).
        // Se ordena por fecha de creación (DESC) para asegurar que siempre obtenemos la conversación más reciente.
        $qb = $repo->createQueryBuilder('c')
            ->where('c.status = :status')
            ->setParameter('status', MessageConversation::STATUS_OPEN)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults(1);

        if (strlen($phone) >= 9) {
            // Extraemos los últimos 8 dígitos para ignorar prefijos internacionales duplicados o signos colados
            $coreNumber = substr($phone, -8);
            $qb->andWhere('c.guestPhone = :exactPhone OR c.guestPhone LIKE :phoneTail')
                ->setParameter('exactPhone', $phone)
                ->setParameter('phoneTail', '%' . $coreNumber);
        } else {
            // Si el número es muy corto, forzamos solo la búsqueda exacta para evitar falsos positivos
            $qb->andWhere('c.guestPhone = :exactPhone')
                ->setParameter('exactPhone', $phone);
        }

        /** @var MessageConversation|null $conversation */
        $conversation = $qb->getQuery()->getOneOrNullResult();

        if ($conversation) {
            // Actualizamos el nombre si el de WhatsApp es más fresco o si la OTA nos dejó "Guest"
            $currentName = $conversation->getGuestName();
            if (empty($currentName) || stripos($currentName, 'Guest') !== false || $currentName === 'Desconocido (WhatsApp)') {
                $conversation->setGuestName($guestName);
            }
            return $conversation;
        }

        // C. Si no hay reserva abierta, CREAMOS UN WALK-IN (Contexto Manual)
        // El constructor exige obligatoriamente contextType y contextId
        $conversation = new MessageConversation('manual', $phone);
        $conversation->setContextOrigin('whatsapp');
        $conversation->setGuestPhone($phone);
        $conversation->setGuestName($guestName);
        $conversation->setStatus(MessageConversation::STATUS_OPEN);

        // Asignación de MaestroIdioma obligatoria por base de datos (nullable: false).
        // Intentamos asignar español ('es') por defecto, o el primero que exista en la tabla.
        $repoIdioma = $this->em->getRepository(MaestroIdioma::class);
        $idiomaDefault = $repoIdioma->find('es') ?? $repoIdioma->findOneBy([]);

        if ($idiomaDefault) {
            $conversation->setIdioma($idiomaDefault);
        }

        $this->em->persist($conversation);

        return $conversation;
    }

    /**
     * Busca un mensaje en BD usando SQL Nativo.
     * Esto evita el Syntax Error de DQL con JSON_EXTRACT y permite que mensajes
     * enviados fuera del PMS (como desde el portal de Meta) se descarten sin error.
     */
    private function findMessageByMetaId(string $metaMessageId): ?Message
    {
        $rsm = new ResultSetMappingBuilder($this->em);
        $rsm->addRootEntityFromClassMetadata(Message::class, 'm');

        // Usamos SQL puro para que MySQL maneje el JSON_EXTRACT directamente
        $sql = 'SELECT * FROM msg_message m WHERE JSON_EXTRACT(m.external_ids, :path) = :metaId LIMIT 1';

        $query = $this->em->createNativeQuery($sql, $rsm);
        $query->setParameter('path', '$."whatsapp_meta"');
        $query->setParameter('metaId', $metaMessageId);

        return $query->getOneOrNullResult();
    }

    /**
     * Descarga el binario de un archivo multimedia desde la Graph API de Meta.
     * Retorna el contenido codificado en Base64 listo para el Factory.
     */
    private function downloadMediaFromMeta(string $mediaId): ?string
    {
        $config = $this->em->getRepository(MetaConfig::class)->findOneBy(['activo' => true]);
        $apiKey = $config?->getCredential('apiKey');
        $baseUrl = rtrim($config?->getBaseUrl() ?? 'https://graph.facebook.com/v19.0', '/');

        if (!$apiKey) {
            $this->logger->error("Intento de descarga de Media WA sin API Key configurada.");
            return null;
        }

        try {
            // Paso 1: Obtener la URL temporal del CDN de Meta usando el Media ID
            $response = $this->httpClient->request('GET', "{$baseUrl}/{$mediaId}", [
                'headers' => ['Authorization' => 'Bearer ' . $apiKey]
            ]);

            $mediaData = $response->toArray(false);
            $url = $mediaData['url'] ?? null;

            if (!$url) {
                $this->logger->warning("Meta no devolvió una URL válida para el Media ID: {$mediaId}");
                return null;
            }

            // Paso 2: Descargar el binario desde la URL (Requiere el Bearer Token)
            $fileResponse = $this->httpClient->request('GET', $url, [
                'headers' => ['Authorization' => 'Bearer ' . $apiKey]
            ]);

            $content = $fileResponse->getContent(false);

            if ($fileResponse->getStatusCode() !== 200 || empty($content)) {
                $this->logger->warning("Fallo al descargar el contenido del CDN. HTTP Code: " . $fileResponse->getStatusCode());
                return null;
            }

            return base64_encode($content);

        } catch (Throwable $e) {
            $this->logger->error("Excepción al descargar Media de Meta ({$mediaId}): " . $e->getMessage());
            return null;
        }
    }
}