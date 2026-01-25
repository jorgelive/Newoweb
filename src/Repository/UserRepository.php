<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * Repositorio para la entidad User.
 * Gestiona las operaciones de base de datos y la actualización de contraseñas.
 *
 * @extends ServiceEntityRepository<User>
 *
 * @method User|null find($id, $lockMode = null, $lockVersion = null)
 * @method User|null findOneBy(array $criteria, array $orderBy = null)
 * @method User[]    findAll()
 * @method User[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    /**
     * Constructor del repositorio.
     *
     * @param ManagerRegistry $registry El registro de Doctrine.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Actualiza el hash de la contraseña de un usuario automáticamente.
     * Este método es llamado por Symfony cuando detecta que el algoritmo de la contraseña
     * ha cambiado o necesita ser "rehashing" (por ejemplo, migrando de SHA512 a Argon2).
     *
     * @param PasswordAuthenticatedUserInterface $user El usuario autenticado.
     * @param string $newHashedPassword La nueva contraseña ya hasheada.
     *
     * @return void
     * @throws UnsupportedUserException Si el usuario no es una instancia de nuestra entidad User.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', get_class($user)));
        }

        $user->setPassword($newHashedPassword);

        // Persistimos el cambio inmediatamente.
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    // --- MÉTODOS DE BÚSQUEDA PERSONALIZADOS (EJEMPLOS ÚTILES) ---

    /**
     * Busca un usuario por email o nombre de usuario.
     * Útil si decides permitir login con ambos campos en el futuro.
     *
     * @param string $identifier Email o Username.
     * @return User|null
     */
    public function findOneByEmailOrUsername(string $identifier): ?User
    {
        return $this->createQueryBuilder('u')
            ->where('u.email = :identifier')
            ->orWhere('u.username = :identifier')
            ->setParameter('identifier', $identifier)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}