<?php

declare(strict_types=1);

namespace App\Message\Service\Translation;

use LanguageDetection\Language;

/**
 * Detecta el idioma del texto entrante de forma local.
 * Cero costo de API, procesado en memoria.
 */
class GuestLanguageDetectorService
{
    private Language $detector;

    public function __construct()
    {
        // Restringimos la detección SOLO a los idiomas que tu PMS y plantillas soportan.
        // Esto evita que un error ortográfico se detecte como un idioma exótico.
        $this->detector = new Language(['es', 'en', 'pt', 'fr', 'it', 'de']);
    }

    /**
     * Devuelve el código ISO (ej: 'pt', 'en') del texto,
     * o el $fallback si no hay certeza o el texto es muy corto.
     */
    public function detectLanguageCode(string $text, string $fallback): string
    {
        $cleanText = trim(strip_tags($text));

        // Textos cortos o comandos numéricos ("1", "ok", "yes") asumen el idioma actual
        if (strlen($cleanText) < 15) {
            return $fallback;
        }

        $result = $this->detector->detect($cleanText)->bestResults()->first();

        // Pedimos un nivel de confianza estricto (80%) para evitar falsos positivos
        if ($result && $result->getScore() > 0.8) {
            return (string) $result->getLanguage();
        }

        return $fallback;
    }
}