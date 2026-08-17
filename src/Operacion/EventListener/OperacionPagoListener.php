<?php

declare(strict_types=1);

namespace App\Operacion\EventListener;

use App\Entity\User;
use App\Operacion\Entity\OperacionPago;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Sella cada pago con quién lo registró.
 *
 * El nombre se resuelve al crear —no se guarda sólo el uuid— porque el pago se lee mucho y su
 * autor no cambia: repetir la consulta al usuario en cada lectura para pintar un nombre fijo
 * sería trabajo de más. Si no hay sesión (un comando), queda sin firma; el pago vale igual.
 */
#[AsEntityListener(event: Events::prePersist, entity: OperacionPago::class)]
final readonly class OperacionPagoListener
{
    public function __construct(private TokenStorageInterface $tokenStorage)
    {
    }

    public function prePersist(OperacionPago $pago): void
    {
        if ($pago->getUsuarioNombre() !== null) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        if ($user instanceof User) {
            $pago->setUsuarioNombre($user->getNombre());
        }
    }
}
