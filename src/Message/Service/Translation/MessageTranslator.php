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
        if ($hasExternal && !$hasLocal) {
            $cleanExternal = trim(strip_tags($message->getContentExternal()));

            // 🔥 CORTAFUEGOS NUMÉRICO ESTRICTO:
            // Los números puros ("2", "1") no tienen idioma ni van a Google. Heredan el actual.
            if (is_numeric($cleanExternal)) {
                $message->setContentLocal($message->getContentExternal());
                $message->setLanguageCode($storedGuestLang);
                return;
            }

            // Textos cortos ("ok", "gracias") y largos pasarán a Google para su traducción,
            // pero controlaremos el cambio de idioma maestro dentro del método.
            $this->translateToLocalWithDetection($message, $storedGuestLang);
            return;
        }

        // 2. FLUJO SALIENTE (EasyAdmin): Escrito aquí (Local), falta External.
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
            $rawExternal = $message->getContentExternal();
            $results = $this->googleTranslator->translateWithDetection(
                [$rawExternal],
                $this->baseLanguage
            );

            // Guardamos la traducción (si era "gracias", se traducirá según baseLanguage)
            $message->setContentLocal($results['translations'][0] ?? null);

            $detectedLang = $results['detectedLanguage'] ?? $currentConvLang;

            // 🔥 FILTRO DE ENTROPÍA LINGÜÍSTICA
            // Verificamos si el texto tiene "peso" real para justificar un cambio de idioma.
            // Más de 15 caracteres o más de 2 palabras descarta casos como "ok", "yes", "muchas gracias".
            $cleanText = trim(strip_tags($rawExternal));
            $isLongEnoughToSwitch = mb_strlen($cleanText) > 15 || str_word_count($cleanText) > 2;

            if ($detectedLang && $detectedLang !== $currentConvLang) {
                if ($isLongEnoughToSwitch) {
                    // El texto es largo, el cambio de idioma es legítimo
                    $iso2LangCode = substr(strtolower($detectedLang), 0, 2);
                    $idiomaEntity = $this->entityManager->getRepository(MaestroIdioma::class)->find($iso2LangCode)
                        ?? $this->entityManager->getRepository(MaestroIdioma::class)->find('en');

                    if ($idiomaEntity instanceof MaestroIdioma) {
                        $detectedLang = $idiomaEntity->getId();
                        $message->getConversation()->setIdioma($idiomaEntity);
                        $this->logger->info("Language mismatch: Conversation updated to {$detectedLang}");
                    } else {
                        $detectedLang = $currentConvLang;
                    }
                } else {
                    // 🛡️ SALVAGUARDA: El texto es muy corto ("gracias", "ok").
                    // Aunque Google detectó otro idioma, NO cambiamos la conversación.
                    // Forzamos a que el mensaje herede el idioma en el que ya veníamos hablando.
                    $detectedLang = $currentConvLang;
                }
            }

            // SETEAMOS EL CÓDIGO EN EL MENSAJE (después de validar y normalizar)
            $message->setLanguageCode($detectedLang);

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