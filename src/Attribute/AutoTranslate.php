<?php

declare(strict_types=1);

namespace App\Attribute;

use Attribute;

/**
 * Marca una propiedad i18n para que `AutoTranslationEventListener` traduzca su español al guardar.
 *
 * ⚠️ **ANTES DE PONER ESTE ATRIBUTO EN UNA ENTIDAD NUEVA, decide qué clase de texto es**
 * (Jorge, 16/10/2026). Hay dos, y su formulario del panel se configura distinto:
 *
 * - **Texto largo** —un ítem de la guía, una nota, la descripción de un segmento—: el traductor
 *   tiene contexto y acierta, y lo que interesa es que corregir el español se propague solo. Nada
 *   especial: los interruptores de traducción del CRUD con sus valores de siempre.
 *
 * - **Maestro de rótulos** —textos CORTOS, reutilizados en muchos sitios y revisados a mano uno a
 *   uno: los de la interfaz de pax (`UiI18n`), los nombres de moneda (`MaestroMoneda`), el
 *   diccionario de ítems (`TravelItemDiccionario`), el catálogo del PMS (`PmsCatalogo`)—: sin
 *   contexto el traductor adivina («Siguiente» → «Following», «Caja fuerte» → «Seguro»), y lo que
 *   interesa es que lo revisado NO se pise. Su CRUD usa
 *   {@see \App\Panel\Field\CamposDeTraduccion::deMaestroDeRotulos()}: al editar, «Traducir
 *   automáticamente» sale desmarcado. Y si la palabra se repite en otros maestros, va también a
 *   `CorreccionesDeTraduccion::FIJAS`, que la traduce por su español sin preguntar a Google.
 *
 * Si dudas, pregunta: es una decisión de negocio, y equivocarse en cualquiera de los dos sentidos
 * falla en silencio —rótulos estropeados, o correcciones que no llegan a los otros seis idiomas—.
 *
 * ⛔ **Nunca se cambia el valor por defecto de `ejecutarTraduccion` en `AutoTranslateControlTrait`**
 * para «proteger» un maestro: lo comparten todas las entidades traducibles y todo lo que las guarda
 * (util, la API, los comandos). Se protege en SU formulario. Ver docs/Autotraduccion.md.
 */
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