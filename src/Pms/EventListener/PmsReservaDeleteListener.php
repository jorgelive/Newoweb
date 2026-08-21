<?php

declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Message\Entity\MessageConversation;
use App\Pms\Entity\PmsConversacionEnlace;
use App\Pms\Entity\PmsReserva;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Qué impide borrar una reserva, dicho ANTES de intentarlo.
 *
 * ── Por qué existe la segunda comprobación ──────────────────────────────────
 * Una reserva cascadea casi todo lo suyo —eventos, huéspedes, cabecera financiera— salvo el
 * **enlace de conversación**: `PmsConversacionEnlace.reserva` no lleva `onDelete`, así que el
 * borrado moría en MySQL con
 *
 *     Cannot delete or update a parent row: a foreign key constraint fails
 *     (`pms_conversacion_enlace`, CONSTRAINT `FK_D58944CED67139E8` …)
 *
 * ...y eso, saliendo del `commit()` de Doctrine, llega al panel como un 500 sin nada que leer.
 * Pasó de verdad el 20/08/2026 a las 23:49: el operador lo vio como «no borra y no dice nada»,
 * y acabó desmontando la reserva a mano —primero el evento, luego la conversación— y dejando
 * atrás una reserva vacía que nadie volvió a mirar.
 *
 * ── Avisa, no cascadea ──────────────────────────────────────────────────────
 * La tentación es poner `onDelete: CASCADE` y que el enlace se vaya solo. Pero un enlace es la
 * pertenencia de un ASUNTO a la conversación de una persona, y llevárselo por delante sin
 * decirlo borra parte de lo que le pasó a ese cliente — justo lo que este proyecto tiene
 * documentado que no se hace («no se borra: se marca»). Así que se niega con el motivo, y quien
 * de verdad quiera borrar la reserva retira antes su conversación, sabiendo lo que retira.
 *
 * ⚠️ **Se dicen todos los motivos que ESTA guarda ve, no el primero.** MySQL corta en la
 * primera clave ajena que topa, y eso convierte el borrado en un juego de adivinar de uno en uno.
 *
 * ⚠️ Pero ojo con el orden: **Doctrine cascadea a los HIJOS antes de llamar al padre**
 * —`UnitOfWork::doRemove()` invoca `cascadeRemove()` primero, para no tener que inicializar
 * proxies después—, así que si alguna estancia está bloqueada quien habla es
 * {@see PmsEventoCalendarioSecurityListener} y esto ni llega a ejecutarse. Comprobado contra
 * producción: borrar una reserva de OTA devuelve el mensaje de aquél, no éste.
 *
 * La comprobación de estancias se conserva aquí igualmente —cubre el día que el listener hijo
 * deje de existir o de cubrir un caso— pero **el motivo que esta guarda aporta de verdad es el
 * de la conversación**, que es el único que no vigila nadie más y el que dejaba el 500 mudo.
 */
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: PmsReserva::class)]
final class PmsReservaDeleteListener
{
    public function preRemove(PmsReserva $reserva, PreRemoveEventArgs $args): void
    {
        $motivos = [];

        // ── 1 · Estancias que no se pueden tocar ────────────────────────────
        // `isSafeToDelete()` ya mira si es de OTA, si existe en Beds24 sin cancelar, y si hay
        // una sincronización en curso.
        foreach ($reserva->getEventosCalendario() as $evento) {
            if (!$evento->isSafeToDelete()) {
                $motivos[] = sprintf(
                    'la estancia %s no es borrable (es de una OTA, ya existe en Beds24 sin cancelar, '
                    . 'o se está sincronizando ahora mismo)',
                    $evento->getLocalizador() ?? substr((string) $evento->getId(), 0, 8)
                );
            }
        }

        // ── 2 · El hilo de chat, que no cascadea ────────────────────────────
        foreach ($this->conversacionesDe($reserva, $args) as $nombre) {
            $motivos[] = sprintf(
                'su conversación de chat%s todavía la tiene como asunto — retírala primero desde el chat',
                $nombre !== '' ? sprintf(' con %s', $nombre) : ''
            );
        }

        if ($motivos === []) {
            return;
        }

        throw new AccessDeniedHttpException(sprintf(
            'No se puede borrar la reserva %s: %s.',
            $reserva->getLocalizador() ?? substr((string) $reserva->getId(), 0, 8),
            implode('; y ', $motivos)
        ));
    }

    /**
     * Los nombres de las conversaciones que todavía apuntan a esta reserva.
     *
     * Se consulta en vez de navegar la relación porque **no hay relación**: el enlace es
     * unidireccional (`PmsConversacionEnlace → PmsReserva`) y la reserva no sabe de sus enlaces.
     *
     * @return list<string>
     */
    private function conversacionesDe(PmsReserva $reserva, PreRemoveEventArgs $args): array
    {
        $enlaces = $args->getObjectManager()
            ->getRepository(PmsConversacionEnlace::class)
            ->findBy(['reserva' => $reserva]);

        $nombres = [];

        foreach ($enlaces as $enlace) {
            $conversacion = $enlace->getConversacion();
            $nombres[] = $conversacion instanceof MessageConversation
                ? trim((string) $conversacion->getGuestName())
                : '';
        }

        return array_values(array_unique($nombres));
    }
}
