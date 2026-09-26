<?php

declare(strict_types=1);

namespace App\Message\Dto\PlantillaMeta;

use App\Dto\Lee;

/**
 * Un botón de una plantilla TAL COMO LA DEVUELVE META: un elemento de `components[].buttons[]`
 * del componente `BUTTONS`.
 *
 * Sólo lleva lo que el sincronizador copia a nuestro `buttons_map`. Meta manda más —`example`,
 * `phone_number`…— y aquí no se lee porque nada lo usa.
 *
 * ⚠️ **Esto NO es el botón que guardamos.** El nuestro (`BotonDeMenu` en {@see
 * \App\Message\Entity\MessageTemplate}) lleva además `resolver_key`, que Meta no conoce y el
 * sincronizador preserva a propósito.
 */
final readonly class BotonDePlantillaMeta
{
    public function __construct(
        /** `URL`, `QUICK_REPLY`, `PHONE_NUMBER`… en mayúsculas, como lo manda Meta. */
        public ?string $tipo,
        /** La etiqueta que ve el huésped. */
        public ?string $texto,
        /** Sólo en los `URL`; puede llevar `{{1}}`. */
        public ?string $url,
    ) {}

    /** @param array<mixed> $boton */
    public static function fromArray(array $boton): self
    {
        // `texto()` y no `textoLimpio()`: sustituye a `$btn['text'] ?? ''` sin cambiar un carácter
        // de lo que se guarda en la plantilla.
        return new self(
            tipo: Lee::texto($boton['type'] ?? null),
            texto: Lee::texto($boton['text'] ?? null),
            url: Lee::texto($boton['url'] ?? null),
        );
    }
}
