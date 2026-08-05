<?php

declare(strict_types=1);

namespace App\Agent\Service;

use Anthropic\Client;

/**
 * Punto ÚNICO donde se construye el cliente de Anthropic.
 *
 * Existe para que la clave de API y el modelo se lean en un solo sitio, y sobre todo para
 * que la ausencia de credenciales no sea un error: en local o en un entorno recién montado
 * `ANTHROPIC_API_KEY` puede estar vacía, y eso tiene que degradar el sistema (el bot no
 * contesta) en vez de tumbar el webhook que trae el mensaje del huésped.
 *
 * Ver docs/Mensajeria.md §10.
 */
final class AnthropicClientFactory
{
    private ?Client $cliente = null;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $modelo,
    ) {}

    /**
     * `null` cuando no hay credenciales configuradas. Quien lo consuma debe tratarlo como
     * «la IA no está disponible», no como un fallo.
     */
    public function crear(): ?Client
    {
        if (trim($this->apiKey) === '') {
            return null;
        }

        return $this->cliente ??= new Client(apiKey: $this->apiKey);
    }

    public function estaConfigurado(): bool
    {
        return trim($this->apiKey) !== '';
    }

    /**
     * Modelo por defecto de todo el proyecto. Se centraliza aquí para que cambiarlo sea una
     * variable de entorno y no una búsqueda por el código.
     */
    public function modelo(): string
    {
        return $this->modelo;
    }
}
