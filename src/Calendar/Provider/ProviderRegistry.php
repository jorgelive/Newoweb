<?php
declare(strict_types=1);

namespace App\Calendar\Provider;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Resuelve el provider adecuado para una configuración de calendario.
 *
 * Reglas:
 * - 0 matches => 500 (config inválida)
 * - 1 match  => OK
 * - >1 match => 500 (config ambigua: obliga a definir provider explícito en YAML)
 */
final class ProviderRegistry
{
    /**
     * @param iterable<CalendarProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
    ) {}

    /**
     * @param array<string,mixed> $config
     */
    public function getProviderForConfig(array $config): CalendarProviderInterface
    {
        /** @var list<CalendarProviderInterface> $matches */
        $matches = [];

        foreach ($this->providers as $provider) {
            if ($provider->supports($config)) {
                $matches[] = $provider;
            }
        }

        $count = count($matches);

        if ($count === 1) {
            return $matches[0];
        }

        if ($count === 0) {
            // Mensaje útil para debug: muestra keys disponibles.
            throw new HttpException(
                500,
                sprintf(
                    'No existe provider para este calendario (config inválida). Claves detectadas: %s',
                    implode(', ', array_keys($config))
                )
            );
        }

        // Si llegas aquí, hay ambigüedad: dos providers dicen "yo lo soporto".
        // Es mejor fallar que escoger uno arbitrario.
        $names = array_map(
            static fn (CalendarProviderInterface $p): string => $p::class,
            $matches
        );

        throw new HttpException(
            500,
            sprintf(
                'Config ambigua: %d providers soportan este calendario: %s. Define "provider:" explícito en la config.',
                $count,
                implode(' | ', $names)
            )
        );
    }
}