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

    /**
     * El texto bien escrito, **sólo si la caja original no decía nada**.
     *
     * ⚠️ Y sólo si el modelo devolvió las MISMAS letras. Se le pidió que no tradujera ni
     * añadiera nombres, pero una petición no es un cierre: se comprueba con código, que es la
     * regla de este proyecto para todo lo que decide un modelo. Si difiere en algo más que caja y
     * tildes, se descarta y el nombre se queda como vino.
     */
    private function capitalizado(string $original, string $propuesto): string
    {
        if (trim($propuesto) === '' || !OrdenDelNombre::mereceCapitalizacion($original)) {
            return $original;
        }

        return $this->mismasLetras($original, $propuesto) ? trim($propuesto) : $original;
    }

    /** ¿Es el mismo texto salvo caja y tildes? `Transliterator` cubre cualquier alfabeto. */
    private function mismasLetras(string $a, string $b): bool
    {
        static $translit = null;
        $translit ??= \Transliterator::create('Any-Latin; Latin-ASCII; Upper');

        $plano = static function (string $t) use ($translit): string {
            $limpio = $translit?->transliterate($t) ?: mb_strtoupper($t);

            return (string) preg_replace('/[^A-Z0-9]/', '', $limpio);
        };

        return $plano($a) === $plano($b);
    }

    /**
     * Arregla la caja dejando el orden intacto.
     *
     * Se llama en el camino de «no estaba cruzado», que es el 90 % de las veces y hasta ahora no
     * escribía nada.
     *
     * @param array{invertido: bool, confianza: string, motivo: string, nombreCapitalizado: string, apellidoCapitalizado: string} $veredicto
     */
    private function aplicarSoloCaja(PmsReserva $reserva, RevisarOrdenDelNombreDispatch $dispatch, array $veredicto): void
    {
        $nombre = $this->capitalizado($dispatch->nombre, $veredicto['nombreCapitalizado']);
        $apellido = $this->capitalizado($dispatch->apellido, $veredicto['apellidoCapitalizado']);

        // ⚠️ Contra lo que hay AHORA en la reserva, no contra lo que se juzgó: entre medias pudo
        // entrar otro pull y pisarlo. Mismo cuidado que `OrdenDelNombre::esNuestroIntercambio()`.
        if ($nombre === $reserva->getNombreCliente() && $apellido === $reserva->getApellidoCliente()) {
            return;
        }

        if ($dispatch->nombre !== $reserva->getNombreCliente() || $dispatch->apellido !== $reserva->getApellidoCliente()) {
            return;   // ya no es el nombre que se mandó a juzgar
        }

        $reserva->setNombreCliente($nombre);
        $reserva->setApellidoCliente($apellido);
        $this->em->flush();

        $this->logger->notice(sprintf(
            '[OrdenNombre] Reserva %s: caja corregida «%s / %s» → «%s / %s».',
            $dispatch->reservaId,
            $dispatch->nombre,
            $dispatch->apellido,
            $nombre,
            $apellido
        ));
    }

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
            // 🔥 **La caja se arregla aunque el ORDEN se quede como vino, y son cosas distintas.**
            // Cruzar dos campos le cambia el nombre a una persona, así que exige confianza alta;
            // pasar «JOSE ANTONIO ALVAREZ» a «José Antonio Álvarez» no cambia quién es nadie. Con
            // la misma vara para las dos, el 90 % de los nombres se quedarían gritando por culpa
            // de una duda sobre el orden que no tiene nada que ver.
            $this->aplicarSoloCaja($reserva, $dispatch, $veredicto);

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

        // La capitalización se aplica sobre el par YA ordenado: si venían cruzados, el nombre bien
        // escrito que devolvió el modelo corresponde al campo del que salió, no al de destino.
        [$nombre, $apellido] = $veredicto['invertido']
            ? [$this->capitalizado($nombre, $veredicto['apellidoCapitalizado']), $this->capitalizado($apellido, $veredicto['nombreCapitalizado'])]
            : [$this->capitalizado($nombre, $veredicto['nombreCapitalizado']), $this->capitalizado($apellido, $veredicto['apellidoCapitalizado'])];

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
