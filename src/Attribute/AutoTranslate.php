<?php

declare(strict_types=1);

namespace App\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class AutoTranslate
{
    /**
     * @param string $sourceLanguage Idioma origen (ej: 'es').
     * @param list<string> $nestedFields Claves a buscar si es un objeto complejo, en notación de
     *                                   flecha para bajar niveles (ej: `['titulo', 'items->nombre']`).
     *                                   Vacío = estructura plana de idiomas.
     * @param string $format 'text' o 'html'.
     */
    public function __construct(
        public string $sourceLanguage = 'es',
        public array $nestedFields = [],
        private string $format = 'text',
        public ?string $preventOverwriteIf = null
    ) {}

    public function getFormat(): string
    {
        return $this->format === 'html' ? 'text/html' : 'text/plain';
    }
}