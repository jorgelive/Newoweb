<?php

declare(strict_types=1);

namespace App\Pms\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Pms\Entity\PmsPagoFinanciero;
use App\Repository\UserRepository;
use App\Security\Roles;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Convierte el `cobradorId` del payload en la relación `User` antes de persistir el pago.
 *
 * Hace falta un processor porque `User` NO es un `ApiResource`: API Platform no puede
 * denormalizar la relación desde una IRI, así que el campo viaja como UUID plano —el mismo
 * que sirve `PmsEnumAjaxController::getCobradores()` para el desplegable de la SPA— y la
 * traducción a entidad se hace aquí, que es el único punto con acceso al repositorio.
 *
 * Decora el persist processor de Doctrine en vez de hacer el `flush()` a mano: así los
 * listeners de coherencia y el recálculo del saldo siguen corriendo exactamente igual que
 * en cualquier otra escritura del módulo.
 */
final readonly class PmsPagoFinancieroProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private UserRepository $usuarios,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof PmsPagoFinanciero && $data->isCobradorIdRecibido()) {
            $data->setCobrador($this->resolverCobrador($data->getCobradorIdEntrante()));
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    /**
     * El UUID convertido en `User`, o null para desasignar.
     *
     * Se comprueba el ROL y no sólo que el usuario exista: el desplegable de la SPA ya viene
     * filtrado, pero el endpoint es escribible por cualquiera con permiso de reservas y un
     * pago atribuido a quien no maneja caja rompe el cuadre sin dejar rastro de por qué.
     *
     * 🪞 Mismo criterio que `PmsEnumAjaxController::getCobradores()` y
     * `RegistrarPagoSkill::cobradoresPosibles()`: rol LITERAL en la columna `user.roles`, sin
     * la jerarquía de `security.yaml`. **Si cambia uno, cambian los tres.**
     */
    private function resolverCobrador(?string $cobradorId): ?User
    {
        if ($cobradorId === null) {
            return null;
        }

        $cobrador = $this->usuarios->find($cobradorId);

        if ($cobrador === null) {
            throw new UnprocessableEntityHttpException('El cobrador indicado no existe.');
        }

        if (!in_array(Roles::COBRADOR, $cobrador->getRoles(), true)) {
            throw new UnprocessableEntityHttpException(sprintf(
                '%s no tiene el rol de cobrador, así que no puede figurar como quien recibió el dinero.',
                $cobrador->getFullname() ?: $cobrador->getUserIdentifier(),
            ));
        }

        return $cobrador;
    }
}
