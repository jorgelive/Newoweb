<?php

declare(strict_types=1);

namespace App\Pms\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Pms\Entity\PmsPeticion;
use DateTimeImmutable;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Al marcar una petición desde el panel, firma QUIÉN y CUÁNDO.
 *
 * ── Por qué no lo manda el cliente ──────────────────────────────────────────
 * Porque un dato que dice quién comprobó algo no puede venir de quien lo comprueba. El grupo de
 * escritura sólo admite `efectuadaAt`, así que el navegador puede decir «hecha» y nada más: el
 * nombre y la hora los pone el servidor con la sesión que tiene delante.
 *
 * Es el mismo criterio que en `marcar_peticion`, la vía del chat: allí el nombre sale del actor,
 * aquí del token. Ninguna de las dos se lo pregunta a nadie.
 *
 * ── Y desmarcar limpia la firma ─────────────────────────────────────────────
 * Si alguien pone `efectuadaAt` a `null` —se marcó por error, la plancha no estaba— el nombre
 * del anterior tiene que irse con la fecha. Dejarlo diría que esa persona comprobó algo que
 * ahora consta como pendiente, que es peor que no decir nada.
 *
 * @implements ProcessorInterface<PmsPeticion, PmsPeticion>
 */
final readonly class PmsPeticionProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<PmsPeticion, PmsPeticion> $persistidor El de Doctrine, decorado.
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        /** @var ProcessorInterface<PmsPeticion, PmsPeticion> */
        private ProcessorInterface $persistidor,
        private Security $security,
    ) {}

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = []
    ): mixed {
        if ($data instanceof PmsPeticion) {
            $usuario = $this->security->getUser();

            $data->setEfectuadaPor(
                $data->getEfectuadaAt() !== null && $usuario instanceof User ? $usuario : null
            );

            // La hora la pone el servidor aunque el cliente mande otra: lo que interesa es
            // cuándo se comprobó de verdad, y el reloj del navegador no es un dato nuestro.
            if ($data->getEfectuadaAt() !== null) {
                $data->setEfectuadaAt(new DateTimeImmutable());
            }
        }

        return $this->persistidor->process($data, $operation, $uriVariables, $context);
    }
}
