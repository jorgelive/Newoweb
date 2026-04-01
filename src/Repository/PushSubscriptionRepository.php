<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repositorio dedicado a la gestión en base de datos de la entidad PushSubscription.
 * * ¿Por qué existe?: Centraliza todas las consultas (queries) y operaciones de persistencia
 * relacionadas con las suscripciones WebPush, aislando la lógica de base de datos de los
 * controladores y servicios de aplicación.
 *
 * @extends ServiceEntityRepository<PushSubscription>
 *
 * @method PushSubscription|null find($id, $lockMode = null, $lockVersion = null)
 * @method PushSubscription|null findOneBy(array $criteria, array $orderBy = null)
 * @method PushSubscription[]    findAll()
 * @method PushSubscription[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PushSubscriptionRepository extends ServiceEntityRepository
{
    /**
     * Constructor explícito del repositorio.
     * * ¿Por qué existe?: Inyecta el registro del manejador de entidades (ManagerRegistry)
     * necesario para que Doctrine sepa qué entidad está gestionando esta clase.
     *
     * @param ManagerRegistry $registry Registro central de Doctrine.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushSubscription::class);
    }

    /**
     * Guarda o actualiza explícitamente una entidad PushSubscription en la base de datos.
     * * ¿Por qué existe?: Provee una forma encapsulada y segura de persistir una nueva suscripción
     * o guardar los cambios de una existente sin llamar directamente al EntityManager desde fuera.
     * * @example
     * $repo->save($subscription, true); // Guarda y hace el flush inmediatamente.
     *
     * @param PushSubscription $entity La entidad de suscripción a guardar.
     * @param bool $flush Si es true, ejecuta el flush() en Doctrine inmediatamente.
     * @return void
     */
    public function save(PushSubscription $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Elimina explícitamente una entidad PushSubscription de la base de datos.
     * * ¿Por qué existe?: Es crítico para mantener la base de datos limpia. Se utiliza
     * principalmente cuando el proveedor Push (FCM, Apple) responde con un error 410 (Gone),
     * lo que indica que el usuario revocó los permisos o desinstaló el Service Worker.
     *
     * @example
     * $repo->remove($expiredSubscription, true); // Elimina la suscripción inválida de la BD.
     *
     * @param PushSubscription $entity La entidad de suscripción a eliminar.
     * @param bool $flush Si es true, ejecuta el flush() en Doctrine inmediatamente.
     * @return void
     */
    public function remove(PushSubscription $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Encuentra explícitamente todas las suscripciones activas vinculadas a un usuario específico.
     * * ¿Por qué existe?: Cuando ocurre un evento en el sistema (ej. nuevo mensaje de chat),
     * el servicio de envío necesita recuperar todos los dispositivos/navegadores activos
     * de un Host o Recepcionista para despachar la notificación a todos ellos en paralelo.
     *
     * @example
     * $userSubscriptions = $repo->findByUser($hostUser);
     * foreach ($userSubscriptions as $sub) { $webPush->send(...) }
     *
     * @param User $user El usuario del cual se quieren obtener las suscripciones.
     * @return PushSubscription[] Un arreglo de entidades PushSubscription.
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.user = :val')
            ->setParameter('val', $user)
            ->getQuery()
            ->getResult();
    }
}