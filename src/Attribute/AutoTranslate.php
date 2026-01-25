<?php

namespace App\Attribute;

use Attribute;

/**
 * Atributo para marcar campos JSON que deben ser traducidos
 * automáticamente por el suscriptor de Google Translate.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class AutoTranslate
{
    public function __construct(
        public string $sourceLanguage = 'es'
    ) {}
}