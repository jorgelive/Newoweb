<?php

declare(strict_types=1);

namespace App\Message\Service\Push;

use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Message\Service\NoLeidos\ResumenNoLeidosService;
use App\Message\Service\Resumen\ResumenConversacionService;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Security\Roles;
use App\Service\WebPushNotificationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Throwable;

/**
 * Arma y despacha la notificación push de una conversación con mensajes nuevos.
 *
 * Vivía dentro de `MessageConversationMercureListener`, que es un listener de Doctrine.
 * Se sacó por dos motivos, y el segundo es el que se notaba:
 *
 * 1. Hacía peticiones HTTP a FCM/APNs **dentro de un flush**.
 * 2. Salía en el mismo instante en que entraba el mensaje, así que el resumen IA —que se
 *    calcula unos segundos después, tras la espera de ráfaga— **todavía no existía**: la
 *    notificación viajaba con el resumen del turno ANTERIOR. Ahora la dispara el mismo
 *    worker que acaba de escribir el resumen, así que siempre lleva el vigente.
 *
 * Ver docs/PwaNotificaciones.md.
 */
final readonly class NotificadorPushConversacion
{
    public function __construct(
        private EntityManagerInterface $em,
        private WebPushNotificationService $push,
        private UserRepository $usuarios,
        private RoleHierarchyInterface $jerarquia,
        private ResumenNoLeidosService $resumenNoLeidos,
        private ResumenConversacionService $resumenConversacion,
        private LoggerInterface $logger,
    ) {}

    public function notificar(MessageConversation $conversacion): void
    {
        try {
            $destinatarios = $this->destinatarios();

            if ($destinatarios === []) {
                return;
            }

            $payload = $this->payload($conversacion);

            foreach ($destinatarios as $usuario) {
                $this->push->sendToUser($usuario, $payload);
            }
        } catch (Throwable $e) {
            // Un aviso que no sale no puede tumbar el proceso que lo pidió.
            $this->logger->error('[PushConversacion] Fallo al notificar: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Avisa de que un mensaje escrito por una persona se quedó sin salir.
     *
     * ── Por qué hace falta ──────────────────────────────────────────────────
     * Un envío que falla no se lo dice a nadie. El mensaje se queda en `FAILED` en la base de
     * datos y el operador, que lo escribió y le dio a enviar, se va convencido de que llegó.
     * Pasó de verdad: el 2026-05-05 alguien le ofreció a Blandine un guía en francés, la reserva
     * era directa —así que WhatsApp era el único canal— y la ventana de 24 h estaba cerrada. El
     * mensaje no salió, nadie se enteró, y la huésped no recibió respuesta a lo que preguntó.
     *
     * Para lo que escribe una PERSONA y, desde el 10/09/2026, para lo que escribe el AGENTE:
     * los dos son mensajes que alguien está esperando ahora. Los automáticos programados siguen
     * fuera —avisar de cada uno llenaría el móvil sin que nadie pueda hacer nada distinto—, y el
     * reparto lo decide `AvisoEnvioFallidoListener`, que es donde está la cuenta que lo sostiene.
     *
     * ⚠️ Por eso el título distingue quién escribió: «tu mensaje» sobre una respuesta que el
     * operador no redactó le hace buscar en su historial algo que nunca envió.
     *
     * El cuerpo lleva el motivo tal cual lo guardó el despachador —«la ventana de 24 h ha
     * caducado», «no se permite enviar a reservas directas por Beds24»— porque es lo único que
     * dice qué hacer a continuación.
     *
     * `$esPorLaVentana` añade la salida concreta a ese caso: mandar una plantilla, que es lo
     * único que WhatsApp acepta con la ventana cerrada. **Cuál, lo elige la persona**: se probó
     * a mandar una automática de «le hemos escrito» y se descartó, porque si hay que gastar una
     * plantilla —Meta las cobra— más vale que sea la guía o el menú de tours, que abren
     * conversación igual y además le sirven de algo al huésped.
     */
    public function avisarEnvioFallido(Message $mensaje, bool $esPorLaVentana = false): void
    {
        try {
            $conversacion = $mensaje->getConversation();

            if (!$conversacion instanceof MessageConversation) {
                return;
            }

            foreach ($this->destinatariosDelFallo($esPorLaVentana) as $usuario) {
                $this->push->sendToUser($usuario, [
                    'title' => $this->quienEscribio($mensaje) . ($conversacion->getGuestName() ?? 'el huésped'),
                    'body' => $this->motivoDe($mensaje) . ($esPorLaVentana
                        ? ' → Mándale una plantilla (guía, tours, llegada): en cuanto conteste, podrás escribirle normal.'
                        : ''),
                    'type' => 'error',
                    'actionUrl' => "/chat?id={$conversacion->getId()}",
                    'unreadTotal' => $this->resumenNoLeidos->total(),
                ]);
            }
        } catch (Throwable $e) {
            $this->logger->error('[PushConversacion] Fallo al avisar de un envío fallido: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /** Encabezado según quién redactó lo que no salió. Ver el aviso de `avisarEnvioFallido()`. */
    private function quienEscribio(Message $mensaje): string
    {
        return ($mensaje->getMetadata()['generado_por'] ?? null) === 'ia'
            ? 'No salió la respuesta del asistente a '
            : 'No salió tu mensaje a ';
    }

    /**
     * El motivo que guardó `MessageDispatcher`, o una frase honesta si no hay ninguno.
     *
     * Se juntan todos los canales: si falló por dos sitios, saber sólo uno lleva a arreglar la
     * mitad y volver a intentarlo para nada.
     */
    private function motivoDe(Message $mensaje): string
    {
        $metadata = $mensaje->getMetadata();
        $motivos = $metadata['dispatch_errors'] ?? $metadata['dispatch_partial_errors'] ?? [];

        if (!is_array($motivos) || $motivos === []) {
            return 'Se quedó sin enviar y no hay motivo registrado. Ábrelo y vuelve a intentarlo.';
        }

        return implode(' · ', array_map(strval(...), $motivos));
    }

    /**
     * Quien puede ver el chat, expandiendo la jerarquía de roles de `security.yaml`.
     *
     * @return list<\App\Entity\User>
     */
    private function destinatarios(): array
    {
        $elegibles = [];

        foreach ($this->usuarios->findAll() as $usuario) {
            $roles = $this->jerarquia->getReachableRoleNames($usuario->getRoles());

            if (in_array(Roles::MENSAJES_SHOW, $roles, true)) {
                $elegibles[] = $usuario;
            }
        }

        return $elegibles;
    }

    /**
     * A quién le sirve ESTE fallo, que no es siempre la misma persona.
     *
     * ── La frontera, que ya existía sin nombre ──────────────────────────────
     * `avisarEnvioFallido()` recibía desde el principio un `$esPorLaVentana`, y esa bandera
     * separa exactamente los dos mundos:
     *
     * | motivo | quién lo arregla | qué hace |
     * |---|---|---|
     * | Ventana de 24 h cerrada | una PERSONA del equipo | manda una plantilla y reabre |
     * | Todo lo demás | quien toca el sistema | canal vetado, sin `bookId`, sin config… |
     *
     * El segundo grupo —«no se permite enviar a reservas directas por Beds24», «ningún canal
     * disponible para este mensaje»— no se arregla desde el chat. Mandárselo a todo el que
     * pueda LEER mensajes tiene las dos formas de salir mal: gente que no puede hacer nada
     * recibiendo `bookId`s de madrugada, y el aviso que sí importa diluido entre los que no.
     *
     * ⚠️ **La guardia técnica se filtra LITERAL, la de mensajería por JERARQUÍA**, y la
     * diferencia es deliberada. `MENSAJES_SHOW` es un permiso: heredarlo de `ROLE_ADMIN` cuenta,
     * porque describe lo que puedes ver. `TECH_SUPPORT` es una guardia: dice a quién se le
     * escribe al móvil, y eso no se hereda de ser administrador. Mismo criterio que
     * {@see \App\Security\Roles::COBRADOR} y `CUSTOMER_SUPPORT`.
     *
     * ⚠️ **Si la guardia técnica está vacía, el aviso NO se pierde**: cae a la de mensajería y se
     * registra como error. Un rol sin nadie detrás es un fallo de configuración, y callarlo aquí
     * convertiría cada fallo técnico en un aviso que nadie recibe y nadie echa de menos — que es
     * justo el silencio que este servicio existe para romper.
     *
     * @return list<\App\Entity\User>
     */
    private function destinatariosDelFallo(bool $esPorLaVentana): array
    {
        if ($esPorLaVentana) {
            return $this->destinatarios();
        }

        $tecnicos = $this->usuarios->findByRole(Roles::TECH_SUPPORT);

        if ($tecnicos === []) {
            $this->logger->error(
                '[PushConversacion] Nadie tiene ROLE_TECH_SUPPORT: el aviso técnico sale a la guardia de mensajería.',
                ['rol' => Roles::TECH_SUPPORT]
            );

            return $this->destinatarios();
        }

        // `array_values` porque `findByRole()` devuelve el resultado de Doctrine y la firma
        // promete una lista: sin reindexar, PHPStan lo ve como `array`, no como `list`.
        return array_values($tecnicos);
    }

    /**
     * ¿El último mensaje de la conversación lo escribió el agente?
     *
     * Se mira el ÚLTIMO mensaje y no «si existe alguna respuesta del sistema»: lo que
     * interesa es el estado actual. Si el huésped volvió a escribir después de que la IA
     * contestara, vuelve a haber algo pendiente y el aviso no debe decir que está
     * atendido.
     *
     * `SENDER_SYSTEM` es lo que pone `AiConversationProcessor::encolarRespuesta()`; un
     * operador escribiendo desde el panel deja `SENDER_HOST`, así que no se confunden.
     */
    private function laIaYaRespondio(MessageConversation $conversacion): bool
    {
        $ultimo = $this->em->getRepository(Message::class)->createQueryBuilder('m')
            ->where('m.conversation = :c')
            ->setParameter('c', $conversacion->getId(), 'uuid')
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $ultimo instanceof Message
            && $ultimo->getDirection() === Message::DIRECTION_OUTGOING
            && $ultimo->getSenderType() === Message::SENDER_SYSTEM;
    }

    /** @return array<string, mixed> */
    private function payload(MessageConversation $conversacion): array
    {
        $huesped = $conversacion->getGuestName() ?? 'Huésped';
        $noLeidos = $conversacion->getUnreadCount();

        // El cuerpo dice QUÉ quieren, no que «hay un mensaje nuevo». Si no hay resumen
        // —IA apagada, motor caído o conversación recién escalada sin mensaje entrante—
        // cae al texto del último mensaje, que sigue siendo mejor que la frase genérica.
        $cuerpo = $conversacion->getResumenIa() ?: $this->resumenConversacion->textoDeRespaldo($conversacion);

        if ($cuerpo === null || $cuerpo === '') {
            $cuerpo = 'Nuevo mensaje en la conversación de ' . ($conversacion->getContextOrigin() ?? 'Chat');
        } elseif ($noLeidos > 1) {
            $cuerpo = "{$noLeidos} sin leer — {$cuerpo}";
        }

        // Si el agente ya contestó, decirlo cambia la decisión del operador: no es lo
        // mismo «alguien espera respuesta» que «esto ya está atendido, échale un ojo».
        // El aviso se espera a propósito a que el agente termine para poder afirmarlo.
        if ($this->laIaYaRespondio($conversacion)) {
            $cuerpo = "🤖 Respondido por IA — {$cuerpo}";
        }

        return [
            'title'     => "Mensaje de {$huesped}",
            'body'      => $cuerpo,
            'type'      => 'info',
            'actionUrl' => "/chat?id={$conversacion->getId()}",

            // Total de no leídos del sistema, para que el service worker pinte el badge
            // con la app cerrada. En Android no surte efecto —la Badging API no existe
            // en Chrome móvil—, pero en escritorio sí. Ver docs/PwaNotificaciones.md §5.
            'unreadTotal' => $this->resumenNoLeidos->total(),
        ];
    }
}
