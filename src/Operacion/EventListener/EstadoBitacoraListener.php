<?php

declare(strict_types=1);

namespace App\Operacion\EventListener;

use App\Entity\User;
use App\Operacion\Entity\OperacionEstadoBitacora;
use App\Operacion\Entity\OperacionServicio;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Escribe una línea de bitácora cada vez que un servicio cambia de estado.
 *
 * ── Por qué en `onFlush` y no en `preUpdate` ────────────────────────────────
 * Aquí se CREA otra entidad (la bitácora) a partir del cambio, y `preUpdate` no permite
 * persistir entidades nuevas de forma fiable. En `onFlush` se tiene el changeset ya calculado
 * y se puede meter la bitácora en el mismo flush con `computeChangeSet`, así queda todo en una
 * transacción: o se guardan el cambio y su rastro juntos, o ninguno.
 *
 * ── Qué vigila ──────────────────────────────────────────────────────────────
 * `estadoReservaProveedor` (el estado con el proveedor: solicitado, confirmado…) y
 * `estadoOperacion` (el servicio en sí). Los dos son enums; el changeset los da como el enum,
 * no como su valor, así que se normaliza a string.
 *
 * Del estado de reserva actualiza además `estadoReservaProveedorDesde` en el propio servicio,
 * para que «desde hace X» sea un campo directo y no una consulta por fila (ver su docblock).
 *
 * ⚠️ Un cambio que NO pase por el ORM —un UPDATE en SQL— no deja rastro. Es la misma razón por
 * la que las correcciones de datos con listeners van por comando, no por consola de MySQL.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final readonly class EstadoBitacoraListener
{
    public function __construct(private TokenStorageInterface $tokenStorage)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em  = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $meta = $em->getClassMetadata(OperacionEstadoBitacora::class);

        [$usuarioId, $usuarioNombre] = $this->usuarioActual();

        foreach ($uow->getScheduledEntityUpdates() as $entidad) {
            if (!$entidad instanceof OperacionServicio) {
                continue;
            }

            $cambios = $uow->getEntityChangeSet($entidad);

            foreach ($this->camposVigilados() as $propiedad => $campo) {
                if (!isset($cambios[$propiedad])) {
                    continue;
                }

                // Las propiedades vigiladas son enums escalares: su changeset es siempre
                // [antes, después]. El `is_array` es para PHPStan, que tipa el retorno de
                // getEntityChangeSet como unión con PersistentCollection (el caso de las
                // relaciones colección, que aquí no ocurre).
                $cambio = $cambios[$propiedad];
                if (!is_array($cambio)) {
                    continue;
                }
                $valorAntes   = $this->comoTexto($cambio[0] ?? null);
                $valorDespues = $this->comoTexto($cambio[1] ?? null);

                // Sin cambio real (mismo valor reasignado) no se registra nada.
                if ($valorDespues === null || $valorAntes === $valorDespues) {
                    continue;
                }

                $log = (new OperacionEstadoBitacora())
                    ->setOperacionServicio($entidad)
                    ->setCampo($campo)
                    ->setValorAnterior($valorAntes)
                    ->setValorNuevo($valorDespues)
                    ->setUsuarioId($usuarioId)
                    ->setUsuarioNombre($usuarioNombre);

                $em->persist($log);
                $uow->computeChangeSet($meta, $log);

                // El «desde cuándo» del estado de reserva se guarda en el servicio para no
                // consultar la bitácora en cada fila del cuadro.
                if ($campo === OperacionEstadoBitacora::CAMPO_RESERVA) {
                    $entidad->setEstadoReservaProveedorDesde($log->getCreatedAt());
                    $uow->recomputeSingleEntityChangeSet(
                        $em->getClassMetadata(OperacionServicio::class),
                        $entidad
                    );
                }
            }
        }
    }

    /** @return array<string, string> propiedad de la entidad → campo de la bitácora */
    private function camposVigilados(): array
    {
        return [
            'estadoReservaProveedor' => OperacionEstadoBitacora::CAMPO_RESERVA,
            'estadoOperacion'        => OperacionEstadoBitacora::CAMPO_OPERACION,
        ];
    }

    /** El changeset da enums; se guarda su `value`. Cualquier otra cosa, a string. */
    private function comoTexto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        if ($valor instanceof \BackedEnum) {
            return (string) $valor->value;
        }

        return (string) $valor;
    }

    /** @return array{0: ?string, 1: ?string} [uuid, nombre] del usuario, o [null, null]. */
    private function usuarioActual(): array
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        if (!$user instanceof User) {
            return [null, null];   // comando, reconciliación: el hecho ocurre igual, sin firma
        }

        return [$user->getId()?->toRfc4122(), $user->getNombre()];
    }
}
