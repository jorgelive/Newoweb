<?php

namespace App\Repository;

use App\Entity\User;
use App\Service\Phone\PhoneSanitizer;
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

    /**
     * Busca todos los usuarios que poseen un rol específico.
     * * @param string $role El rol a buscar (ej: ROLE_MENSAJES_SHOW).
     * @return User[]
     */
    public function findByRole(string $role): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.roles LIKE :role')
            ->setParameter('role', '%"' . $role . '"%')
            ->getQuery()
            ->getResult();
    }

    /**
     * Quién del equipo escribe desde este número, o null si es un desconocido (un huésped).
     *
     * Es la contraparte de `PmsReserva`→teléfono: cuando entra un WhatsApp sólo se tiene el
     * número del remitente, y de aquí sale si hay que tratarlo como equipo
     * (`AgentActor::delEquipoPorChat()`) o como huésped.
     *
     * El número entrante se normaliza con el MISMO `PhoneSanitizer` que usó el listener al
     * guardarlo. Sin eso la comparación es una lotería: WhatsApp entrega «+51987654321» y en
     * la columna está «51987654321», así que un `findOneBy` a pelo no encuentra nada y falla
     * en silencio —el operador quedaría como huésped sin que nada lo delate—.
     *
     * ⚠️ Devuelve al usuario aunque esté deshabilitado, y eso es deliberado: en este sistema
     * `enabled = false` significa DOS cosas —«se le retiró el acceso» y «nunca tuvo login»,
     * como la limpiadora que sí puede cobrar (§11.5.1)—. Filtrar aquí dejaría a la segunda
     * como desconocida.
     *
     * 🔒 Pero identificar SÍ sería autorizar si nadie mirase `enabled` después, y durante un
     * tiempo nadie lo miraba. Quien lo cierra es
     * {@see \App\Agent\Access\AgentActorFactory::delEquipoPorChat()}: a un deshabilitado le
     * retira los roles de escritura. **Si añades otra ruta que construya un actor desde este
     * método, tiene que hacer lo mismo** — aquí sólo se identifica.
     */
    public function findByTelefono(?string $telefono, PhoneSanitizer $sanitizer): ?User
    {
        $telefono = trim((string) $telefono);
        if ($telefono === '') {
            return null;
        }

        $limpio = $sanitizer->cleanPhoneNumber($telefono, 'PE');
        if ($limpio === '') {
            return null;
        }

        return $this->createQueryBuilder('u')
            ->andWhere('u.telefono = :tel')
            ->setParameter('tel', $limpio)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}