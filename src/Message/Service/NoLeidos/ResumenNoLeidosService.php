<?php

declare(strict_types=1);

namespace App\Message\Service\NoLeidos;

use App\Message\Entity\MessageConversation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cuenta los mensajes sin leer del sistema.
 *
 * Fuente única del número, para que no vuelva a haber dos sitios contando lo
 * mismo y diciendo cosas distintas. Lo consumen:
 *
 * - `UnreadSummaryController` → el resumen que pinta el portal y el chat.
 * - `MessageConversationMercureListener` → el total que viaja EN EL PUSH, para
 *   que el service worker pueda poner el badge del icono con la app cerrada.
 *
 * Ese segundo caso es el motivo de que esto sea un servicio y no un método del
 * controlador: con la app cerrada no corre nada del front, y sin el total en el
 * payload el sistema operativo solo sabe contar notificaciones visibles —que con
 * el agrupado por conversación es casi siempre 1—.
 *
 * Ver docs/PwaNotificaciones.md.
 */
final readonly class ResumenNoLeidosService
{
    /** Cuántas conversaciones sin leer devuelve el detalle del panel de Home. */
    public const int LIMITE_DETALLE = 20;

    public function __construct(
        private EntityManagerInterface $em
    ) {}

    /**
     * Total de mensajes sin leer en todo el sistema.
     *
     * Es un único `SUM` sobre una columna indexable: se llama por cada mensaje
     * entrante que dispara push, así que tiene que ser barato.
     */
    public function total(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(c.unreadCount), 0)')
            ->from(MessageConversation::class, 'c')
            ->where('c.unreadCount > 0')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Resumen completo: total, desglose por estado y las conversaciones pendientes.
     *
     * @return array{
     *     totalMensajes: int,
     *     totalConversaciones: int,
     *     porEstado: array<string, array{mensajes: int, conversaciones: int}>,
     *     conversaciones: list<array{id: string, guestName: string, unreadCount: int, status: string, lastMessageAt: ?string}>
     * }
     */
    public function resumen(): array
    {
        $porEstado = $this->em->createQueryBuilder()
            ->select('c.status AS status', 'SUM(c.unreadCount) AS mensajes', 'COUNT(c.id) AS conversaciones')
            ->from(MessageConversation::class, 'c')
            ->where('c.unreadCount > 0')
            ->groupBy('c.status')
            ->getQuery()
            ->getArrayResult();

        // Los tres estados se inicializan a cero a propósito: el front pinta una
        // pestaña por estado y necesita la clave aunque no haya nada pendiente, o
        // tendría que defenderse de `undefined` en cada plantilla.
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

            // Un estado fuera de los tres conocidos (dato viejo o migración a
            // medias) suma al total pero no rompe el desglose.
            if (isset($estados[$fila['status']])) {
                $estados[$fila['status']] = ['mensajes' => $mensajes, 'conversaciones' => $conversaciones];
            }
        }

        // Campos sueltos, no entidades: esto se consulta en cada carga del portal
        // y no necesita el grafo entero de la conversación.
        $detalle = $this->em->createQueryBuilder()
            ->select('c.id AS id', 'c.guestName AS guestName', 'c.unreadCount AS unreadCount', 'c.status AS status', 'c.lastMessageAt AS lastMessageAt')
            ->from(MessageConversation::class, 'c')
            ->where('c.unreadCount > 0')
            ->orderBy('c.lastMessageAt', 'DESC')
            ->setMaxResults(self::LIMITE_DETALLE)
            ->getQuery()
            ->getArrayResult();

        return [
            'totalMensajes'       => $totalMensajes,
            'totalConversaciones' => $totalConversaciones,
            'porEstado'           => $estados,
            'conversaciones'      => array_map(static fn (array $c): array => [
                'id'            => (string) $c['id'],
                'guestName'     => $c['guestName'] ?? 'Huésped',
                'unreadCount'   => (int) $c['unreadCount'],
                'status'        => $c['status'],
                'lastMessageAt' => $c['lastMessageAt']?->format(\DATE_ATOM),
            ], $detalle),
        ];
    }
}
