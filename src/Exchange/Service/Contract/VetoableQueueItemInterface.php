<?php

declare(strict_types=1);

namespace App\Exchange\Service\Contract;

use DateTimeImmutable;

/**
 * Un ítem de cola que puede decir «ya no me ejecutes», aunque siga en `pending`.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * El worker elige qué ejecutar mirando SÓLO la cola (`AbstractExchangeRepository::claimRunnable()`).
 * Para casi todas las colas es lo correcto, pero no para las que **entregan un contenido decidido
 * antes**: si esa decisión se revoca después de encolar y la cola no se enteró, sale igual.
 *
 * Pasó con los mensajes. Cancelar un mensaje cancela sus colas en cascada, y la cascada puede
 * fallar en silencio; el 17/09/2026 dejó 25 colas vivas de 13 recordatorios cancelados, la primera
 * para el día siguiente a las 8:00. Nada entre la cola y la red miraba el mensaje.
 *
 * ── Por qué es OPCIONAL y no una regla del motor ────────────────────────────
 * Las colas no significan lo mismo, y una regla general sería dañina en parte de ellas:
 *
 * | Tipo | Colas | ¿Veto? |
 * |---|---|---|
 * | traer datos | `bookings_pull`, `beds24_message_receive`, `invoice_receive` | no: no cuelgan de nada cancelable |
 * | sincronizar estado | `bookings_push`, `rates_push` | **no**: mandan el estado ACTUAL, y una reserva cancelada TIENE que salir — es como la cancelación llega a la OTA |
 * | entregar contenido | `beds24_message_send`, `whatsapp_meta_send`, `email_send` | sí |
 *
 * Cada cola decide si se apunta y con qué criterio. El motor sólo pregunta
 * ({@see \App\Exchange\Service\Engine\FiltroDeVetos}), y las que no implementan esto no cambian.
 */
interface VetoableQueueItemInterface extends ExchangeQueueItemInterface
{
    /** `null` para ejecutar; si no, por qué no debe ejecutarse. */
    public function motivoParaNoEjecutar(): ?string;

    /**
     * Lo saca de la cola para siempre: estado final, SIN bloqueo y con el motivo.
     *
     * ⚠️ Soltar el bloqueo no es opcional: el vigilante de `claimRunnable()` pasa a `failed` todo
     * lo que tenga `locked_at` viejo, y un ítem vetado con el candado puesto volvería a la cola.
     */
    public function marcarVetado(string $motivo, DateTimeImmutable $now): void;
}
