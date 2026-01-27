<?php

declare(strict_types=1);

namespace App\Attribute;

use Attribute;

/**
 * Atributo para marcar campos JSON que deben ser traducidos automáticamente.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class AutoTranslate
{
    /**
     * @param string $sourceLanguage Idioma origen. [cite: 2026-01-14]
     * @param string $format 'text' o 'html'.
     * @param bool $overwrite Forzar traducción sobre valores existentes.
     */
    public function __construct(
        public string $sourceLanguage = 'es',
        public string $format = 'text',
        public bool $overwrite = false
    ) {}

    public function getSourceLanguage(): string
    {
        return $this->sourceLanguage;
    }

    public function getFormat(): string
    {
        return $this->format === 'html' ? 'text/html' : 'text/plain';
    }
}