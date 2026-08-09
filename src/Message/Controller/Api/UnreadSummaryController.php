<?php

declare(strict_types=1);

namespace App\Message\Controller\Api;

use App\Message\Entity\MessageConversation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * Resumen global de mensajes sin leer.
 *
 * ¿Por qué existe?: el badge del icono y los contadores del portal se calculaban
 * recorriendo la lista de conversaciones que el chat tenía cargada en memoria, y
 * esa lista está PAGINADA. Una conversación con no leídos que quedara fuera de la
 * primera página —por ejemplo una de hace tres meses— sencillamente no se contaba:
 * el número dependía de cuánto scroll hubiera hecho el operador, no de los datos.
 *
 * Este endpoint responde con el total real de una sola consulta agregada, sin
 * traer conversaciones ni mensajes a memoria, y es la ÚNICA fuente de la que
 * beben el badge del sistema operativo, los contadores de Home y del selector de
 * módulos, y las pestañas del chat. Ver docs/PwaNotificaciones.md.
 */
#[AsController]
final class UnreadSummaryController extends AbstractController
{
    /**
     * Cuántas conversaciones sin leer se devuelven en el detalle.
     *
     * El total y el desglose por estado son agregados y salen completos; esta cota
     * solo limita la lista que pinta el panel de Home. Si alguna vez hubiera más,
     * el panel enseña las más recientes y el contador sigue diciendo la verdad.
     */
    private const int LIMITE_DETALLE = 20;

    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    public function __invoke(): JsonResponse
    {
        // Agregado por estado: una sola pasada, sin hidratar entidades.
        $porEstado = $this->em->createQueryBuilder()
            ->select('c.status AS status', 'SUM(c.unreadCount) AS mensajes', 'COUNT(c.id) AS conversaciones')
            ->from(MessageConversation::class, 'c')
            ->where('c.unreadCount > 0')
            ->groupBy('c.status')
            ->getQuery()
            ->getArrayResult();

        // Se inicializan los tres estados a cero a propósito: el front pinta una
        // pestaña por estado y necesita la clave aunque no haya nada pendiente,
        // o tendría que defenderse de undefined en cada plantilla.
        $estados = [
            MessageConversation::STATUS_OPEN     => ['mensajes' => 0, 'conversaciones' => 0],
            MessageConversation::STATUS_ARCHIVED => ['mensajes' => 0, 'conversaciones' => 0],
            MessageConversation::STATUS_CLOSED   => ['mensajes' => 0, 'conversaciones' => 0],
        ];

        $totalMensajes = 0;
        $totalConversaciones = 0;

        foreach ($porEstado as $fila) {
            $mensajes = (int) $fila['mensajes'];
            $conversaciones = (int) $fila['conversaciones'];

            $totalMensajes += $mensajes;
            $totalConversaciones += $conversaciones;

            // Un estado fuera de los tres conocidos (dato viejo o migración a medias)
            // suma al total pero no rompe el desglose.
            if (isset($estados[$fila['status']])) {
                $estados[$fila['status']] = ['mensajes' => $mensajes, 'conversaciones' => $conversaciones];
            }
        }

        // Detalle para el panel de Home. Se piden campos sueltos, no entidades:
        // esto lo consulta el portal en cada carga y no necesita el grafo entero.
        $detalle = $this->em->createQueryBuilder()
            ->select('c.id AS id', 'c.guestName AS guestName', 'c.unreadCount AS unreadCount', 'c.status AS status', 'c.lastMessageAt AS lastMessageAt')
            ->from(MessageConversation::class, 'c')
            ->where('c.unreadCount > 0')
            ->orderBy('c.lastMessageAt', 'DESC')
            ->setMaxResults(self::LIMITE_DETALLE)
            ->getQuery()
            ->getArrayResult();

        $conversaciones = array_map(static fn (array $c): array => [
            'id'            => (string) $c['id'],
            'guestName'     => $c['guestName'] ?? 'Huésped',
            'unreadCount'   => (int) $c['unreadCount'],
            'status'        => $c['status'],
            'lastMessageAt' => $c['lastMessageAt']?->format(\DATE_ATOM),
        ], $detalle);

        return new JsonResponse([
            'totalMensajes'       => $totalMensajes,
            'totalConversaciones' => $totalConversaciones,
            'porEstado'           => $estados,
            'conversaciones'      => $conversaciones,
        ]);
    }
}
