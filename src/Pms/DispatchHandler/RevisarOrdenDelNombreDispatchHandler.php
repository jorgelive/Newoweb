<?php

declare(strict_types=1);

namespace App\Pms\DispatchHandler;

use App\Pms\Dispatch\RevisarOrdenDelNombreDispatch;
use App\Pms\Entity\PmsReserva;
use App\Pms\Nombre\OrdenDelNombre;
use App\Pms\Nombre\RevisorDeOrdenDeNombre;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Endereza el nombre y el apellido de una reserva cuando el canal los mandó cruzados.
 *
 * ### Por qué un handler y no una skill
 *
 * Una skill es una herramienta que **elige el modelo** dentro de una conversación, con su
 * descripción escrita para que la reconozca. Aquí no hay conversación ni nadie a quien
 * preguntar: es un trabajo de sistema que se dispara solo al entrar una reserva. El molde
 * correcto es el de {@see \App\Agent\Dispatch\ProcessInboundIntentDispatch} — un dispatch
 * enrutado a `async` y su handler—, y así además no aparece en el catálogo de ninguna skill ni
 * gasta tokens del prompt de nadie.
 *
 * ### Por qué asíncrono, y qué margen hay de verdad
 *
 * Va aparte porque una llamada al modelo dentro del webhook lo alargaría varios segundos, y
 * Beds24 y Meta reintentan los webhooks lentos — el mismo motivo por el que
 * `ProcessInboundIntentDispatch` está en `async` (ver `config/packages/messenger.yaml`).
 *
 * El margen existe porque **el texto del mensaje se renderiza al ENVIAR, no al encolar**: la
 * fila de `msg_beds24_send_queue` guarda `message_id`, no el cuerpo, y quien lo compone es
 * `exchange:run beds24_message_send`, un comando de cron. Hasta que ese cron pase, corregir el
 * nombre todavía cambia lo que va a leer el huésped.
 *
 * ⚠️ **Ese margen NO está garantizado por nada de este repositorio**: es la cadencia del
 * crontab del servidor. Si algún día se aprieta, la bienvenida puede adelantarse. La forma de
 * volverlo determinista sin tocar código es dar a las reglas «Bienvenida a…» un
 * `offset_minutes` de unos pocos minutos, que hoy es 0.
 *
 * ### El cierre es código
 *
 * El modelo contesta un booleano y una confianza; **nunca el nombre**. Quien intercambia las dos
 * cadenas es {@see OrdenDelNombre::resultado()}, con las que ya estaban guardadas. Así el peor
 * fallo posible es un intercambio equivocado, no un nombre inventado.
 */
#[AsMessageHandler]
final readonly class RevisarOrdenDelNombreDispatchHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private RevisorDeOrdenDeNombre $revisor,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(RevisarOrdenDelNombreDispatch $dispatch): void
    {
        if (!Uuid::isValid($dispatch->reservaId)) {
            return;
        }

        $reserva = $this->em->find(PmsReserva::class, Uuid::fromString($dispatch->reservaId));

        if (!$reserva instanceof PmsReserva) {
            return;
        }

        $veredicto = $this->revisor->veredicto($dispatch->nombre, $dispatch->apellido);

        if ($veredicto === null) {
            // Los tres motivos —sin motor, el motor falló, respuesta ilegible— ya los registró
            // el revisor como `warning`. Aquí no se repite: dos líneas por el mismo hecho hacen
            // que contar ocurrencias en el log mienta.
            return;
        }

        $par = OrdenDelNombre::resultado(
            invertido: $veredicto['invertido'],
            confianza: $veredicto['confianza'],
            nombreJuzgado: $dispatch->nombre,
            apellidoJuzgado: $dispatch->apellido,
            nombreActual: $reserva->getNombreCliente(),
            apellidoActual: $reserva->getApellidoCliente(),
        );

        // ⚠️ **El caso normal también se cuenta.** Antes esto era un `return` mudo, y ahí estaba
        // el agujero: la ÚNICA línea que este handler podía escribir era la del intercambio
        // hecho, o sea el caso raro. El común —«no estaba cruzado»— salía por aquí sin decir
        // nada. Resultado: `info.log` con 8 MB y **cero** líneas de `[OrdenNombre]`, y ninguna
        // forma de distinguir «lleva doce días sin encontrar nada que arreglar» de «no se está
        // ejecutando». Son cosas muy distintas y costaban lo mismo de averiguar: nada.
        //
        // Va en `notice` y no en `info` para que se lea entre los 378 `[WebPush]` del día.
        if ($par === null) {
            $this->logger->notice(sprintf(
                '[OrdenNombre] Reserva %s: «%s / %s» se queda como vino (invertido=%s, confianza=%s). %s',
                $dispatch->reservaId,
                $dispatch->nombre,
                $dispatch->apellido,
                $veredicto['invertido'] ? 'sí' : 'no',
                $veredicto['confianza'],
                $veredicto['motivo']
            ));

            return;
        }

        [$nombre, $apellido] = $par;

        $reserva->setNombreCliente($nombre);
        $reserva->setApellidoCliente($apellido);
        $this->em->flush();

        $this->logger->notice(sprintf(
            '[OrdenNombre] Reserva %s: «%s / %s» venía cruzado y queda «%s / %s» (%s).',
            $dispatch->reservaId,
            $dispatch->nombre,
            $dispatch->apellido,
            $nombre,
            $apellido,
            $veredicto['motivo']
        ));
    }
}
