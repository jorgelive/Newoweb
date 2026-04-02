<?php

declare(strict_types=1);

namespace App\Message\Service\Translation;

use App\Entity\Maestro\MaestroIdioma;
use App\Message\Entity\Message;
use App\Service\GoogleTranslateService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Servicio encargado de la traducción automática y sincronización de idiomas.
 * Asegura que tanto el Mensaje como la Conversación reflejen el idioma real detectado,
 * utilizando la detección de idioma sin costo de Google Cloud Translation V3.
 */
class MessageTranslator
{
    /**
     * @param GoogleTranslateService $googleTranslator Servicio de traducción.
     * @param EntityManagerInterface $entityManager Gestor de entidades de Doctrine.
     * @param string $baseLanguage Idioma base de la aplicación.
     * @param LoggerInterface $logger Servicio de log.
     */
    public function __construct(
        private readonly GoogleTranslateService $googleTranslator,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%app.default_language%')]
        private readonly string $baseLanguage,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Evalúa el estado del mensaje y lo completa traduciendo el campo faltante.
     * Si el mensaje es entrante (tiene External pero no Local), delega la detección de idioma a Google
     * y actualiza la entidad Conversacion con el idioma real detectado.
     *
     * @param Message $message La entidad del mensaje a procesar.
     */
    public function process(Message $message): void
    {
        $conversation = $message->getConversation();
        $storedGuestLang = $conversation->getIdioma() ? $conversation->getIdioma()->getId() : null;

        $hasLocal = !empty($message->getContentLocal());
        $hasExternal = !empty($message->getContentExternal());

        // 0. Si ya tiene ambos, solo nos aseguramos de que el LanguageCode no sea nulo.
        if ($hasLocal && $hasExternal) {
            if (!$message->getLanguageCode()) {
                $message->setLanguageCode($storedGuestLang);
            }
            return;
        }

        // 1. FLUJO ENTRANTE (Webhooks): Viene de afuera (External), falta Local.
        // Dejamos que Google detecte el idioma real.
        if ($hasExternal && !$hasLocal) {
            $this->translateToLocalWithDetection($message, $storedGuestLang);
            return;
        }

        // 2. FLUJO SALIENTE (EasyAdmin): Escrito aquí (Local), falta External.
        // Usamos el idioma que ya conocemos del cliente.
        if ($hasLocal && !$hasExternal) {
            $message->setLanguageCode($storedGuestLang);

            // Si el idioma es el mismo que el base, bypass de API.
            if ($storedGuestLang === $this->baseLanguage) {
                $message->setContentExternal($message->getContentLocal());
                $message->setSubjectExternal($message->getSubjectLocal());
                return;
            }

            $this->translateToExternal($message, $storedGuestLang);
            return;
        }
    }

    /**
     * Traduce hacia el idioma local y sincroniza el idioma detectado por Google.
     * Actualiza el LanguageCode del mensaje y, si difiere del actual, actualiza la entidad Conversación.
     *
     * @param Message $message La entidad que contiene los campos Local y External.
     * @param string|null $currentConvLang Código del idioma actual en la conversación.
     */
    private function translateToLocalWithDetection(Message $message, ?string $currentConvLang): void
    {
        try {
            $results = $this->googleTranslator->translateWithDetection(
                [$message->getContentExternal()],
                $this->baseLanguage
            );

            $message->setContentLocal($results['translations'][0] ?? null);

            // Si Google falla en la detección por ser un texto muy corto, usamos el de la conversación.
            $detectedLang = $results['detectedLanguage'] ?? $currentConvLang;

            // SETEAMOS EL CÓDIGO EN EL MENSAJE
            $message->setLanguageCode($detectedLang);

            // ACTUALIZACIÓN DE LA CONVERSACIÓN (Master)
            if ($detectedLang && $detectedLang !== $currentConvLang) {
                // Obtenemos referencia proxy del MaestroIdioma (ID es el código natural: 'en', 'es')
                $idiomaEntity = $this->entityManager->getReference(MaestroIdioma::class, $detectedLang);

                // Validación estricta para resolver el warning del IDE (Expected MaestroIdioma, got object|null)
                // Además asegura la integridad si el código detectado no existe en la DB.
                if ($idiomaEntity instanceof MaestroIdioma) {
                    $message->getConversation()->setIdioma($idiomaEntity);
                    $this->logger->info("Language mismatch: Conversation updated to {$detectedLang}");
                } else {
                    $this->logger->warning("Se detectó el idioma {$detectedLang}, pero no se encontró la referencia de MaestroIdioma.");
                }
            }

        } catch (Throwable $e) {
            $this->logger->error('Error en detección/traducción entrante: ' . $e->getMessage());
            $message->setContentLocal($message->getContentExternal());
            $message->setLanguageCode($currentConvLang);
        }
    }

    /**
     * Traduce contenido local al idioma externo definido para enviarlo al cliente.
     *
     * @param Message $message La entidad con el contenido local a traducir.
     * @param string $targetLang Código del idioma de destino.
     */
    private function translateToExternal(Message $message, string $targetLang): void
    {
        try {
            $results = $this->googleTranslator->translate(
                [$message->getContentLocal()],
                $targetLang,
                $this->baseLanguage
            );

            $message->setContentExternal($results[0] ?? null);
        } catch (Throwable $e) {
            $this->logger->error('Error en traducción externa: ' . $e->getMessage());
            $message->setContentExternal($message->getContentLocal());
        }
    }
}