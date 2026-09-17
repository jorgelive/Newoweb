<?php

declare(strict_types=1);

namespace App\Message\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Message\Entity\MessageTemplate;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Las plantillas que se pueden elegir en el chat: sólo las que están en circulación.
 *
 * ── Por qué ─────────────────────────────────────────────────────────────────
 * La lista «Elegir plantilla» del chat pedía TODAS y filtraba sólo por contexto y origen. Una
 * plantilla sustituida seguía ofreciéndose aunque no pudiera salir por ningún canal: el 17/09/2026
 * aparecían `welcome_booking` —la vieja, con las cuentas tecleadas— junto a su sustituta, y
 * `recordatorio_llegada`, con los tres canales ya apagados, como «Guia de llegada» al lado de la
 * nueva.
 *
 * «Archivada» no es una columna: es **no tener ningún canal encendido**
 * ({@see MessageTemplate::estaEnCirculacion()}). Una plantilla así no puede enviarse, así que no
 * tiene nada que hacer en un selector. Sigue en el panel, editable, y encender un canal la devuelve.
 *
 * Sin paginación: son una veintena de filas, y filtrar después de paginar daría páginas cojas.
 *
 * @implements ProviderInterface<MessageTemplate>
 */
final readonly class PlantillasEnCirculacionProvider implements ProviderInterface
{
    public function __construct(
        /** @var ProviderInterface<MessageTemplate> */
        #[Autowire(service: 'api_platform.doctrine.orm.state.collection_provider')]
        private ProviderInterface $decorado,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return list<MessageTemplate>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        /** @var iterable<MessageTemplate> $plantillas */
        $plantillas = $this->decorado->provide($operation, $uriVariables, $context);

        $enCirculacion = [];

        foreach ($plantillas as $plantilla) {
            if ($plantilla->estaEnCirculacion()) {
                $enCirculacion[] = $plantilla;
            }
        }

        return $enCirculacion;
    }
}
