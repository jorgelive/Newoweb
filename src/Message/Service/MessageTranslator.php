<?php

declare(strict_types=1);

namespace App\Message\Service;

use App\Message\Entity\Message;
use App\Service\GoogleTranslateService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class MessageTranslator
{
    public function __construct(
        private readonly GoogleTranslateService $googleTranslator,
        #[Autowire('%app.default_language%')]
        private readonly string $baseLanguage,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Evalúa el estado del mensaje y lo completa traduciendo el campo faltante.
     * Funciona idéntico para Inbound (Webhooks) y Outbound (EasyAdmin).
     */
    public function process(Message $message): void
    {
        // Se asume que la conversación ya tiene cargado su idioma Maestro
        $guestLang = $message->getConversation()->getIdioma()->getId();

        $hasLocal = !empty($message->getContentLocal());
        $hasExternal = !empty($message->getContentExternal());

        // 0. HUMANO HIZO TODO: Si ambos están llenos, no hacemos nada.
        if ($hasLocal && $hasExternal) {
            return;
        }

        // 1. MISMO IDIOMA: No gastamos saldo de Google, copiamos y listo.
        if ($guestLang === $this->baseLanguage) {
            if ($hasLocal && !$hasExternal) {
                $message->setContentExternal($message->getContentLocal());
                $message->setSubjectExternal($message->getSubjectLocal());
            } elseif ($hasExternal && !$hasLocal) {
                $message->setContentLocal($message->getContentExternal());
                $message->setSubjectLocal($message->getSubjectExternal());
            }
            return;
        }

        // 2. FLUJO DE SALIDA (o Normal en EasyAdmin): Tenemos Local, falta External
        if ($hasLocal && !$hasExternal) {
            $this->translateToExternal($message, $guestLang);
            return;
        }

        // 3. FLUJO DE ENTRADA (Webhooks o Override manual): Tenemos External, falta Local
        if ($hasExternal && !$hasLocal) {
            $this->translateToLocal($message, $guestLang);
            return;
        }
    }

    private function translateToExternal(Message $message, string $targetLang): void
    {
        try {
            $texts = [$message->getContentLocal()];
            if ($message->getSubjectLocal()) {
                $texts[] = $message->getSubjectLocal();
            }

            // Llamada única a Google Cloud V3
            $results = $this->googleTranslator->translate($texts, $targetLang, $this->baseLanguage);

            $message->setContentExternal($results[0] ?? null);
            if (isset($results[1])) {
                $message->setSubjectExternal($results[1]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Error traduciendo a External: ' . $e->getMessage());
            // Fallback
            $message->setContentExternal($message->getContentLocal());
            $message->setSubjectExternal($message->getSubjectLocal());
        }
    }

    private function translateToLocal(Message $message, string $sourceLang): void
    {
        try {
            $texts = [$message->getContentExternal()];
            if ($message->getSubjectExternal()) {
                $texts[] = $message->getSubjectExternal();
            }

            // Llamada única a Google Cloud V3
            $results = $this->googleTranslator->translate($texts, $this->baseLanguage, $sourceLang);

            $message->setContentLocal($results[0] ?? null);
            if (isset($results[1])) {
                $message->setSubjectLocal($results[1]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Error traduciendo a Local: ' . $e->getMessage());
            // Fallback
            $message->setContentLocal($message->getContentExternal());
            $message->setSubjectLocal($message->getSubjectExternal());
        }
    }
}