<?php

declare(strict_types=1);

namespace App\Agent\DispatchHandler;

use App\Agent\Dispatch\ProcessInboundIntentDispatch;
use App\Agent\Router\IntentRouter;
use App\Agent\Triage\PreRouterRafaga;
use App\Message\Entity\Message;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Throwable;

#[AsMessageHandler]
final readonly class ProcessInboundIntentDispatchHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private IntentRouter $intentRouter,
        private PreRouterRafaga $preRouter,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
        #[Autowire('%env(int:AGENT_IA_ESPERA_RAFAGA)%')]
        private int $esperaRafaga,
    ) {}

    public function __invoke(ProcessInboundIntentDispatch $dispatch): void
    {
        $msg = $this->em->getRepository(Message::class)->find($dispatch->messageId);

        // 1. Doble validación de seguridad
        if (!$msg instanceof Message || !$msg->getInboundIntent() || $msg->getInboundIntent()['resolved']) {
            return;
        }

        // 🔥 FIX PARA EL WORKER ASÍNCRONO:
        // Limpiamos la caché del UnitOfWork para esta entidad y traemos los datos reales
        // que el Webhook acaba de guardar (incluyendo la apertura de la ventana de 24h).
        $conversation = $msg->getConversation();
        if ($conversation !== null) {
            $this->em->refresh($conversation);
        }

        // PRE-ROUTER. ¿Ha terminado de escribir, o sigue?
        //
        // Se consulta AQUÍ y no en el listener porque hace una petición de red y el
        // listener corre dentro de un flush de Doctrine. Si dice que espere, el trabajo se
        // reencola con la ventana completa; `yaEsperado` corta el bucle para que no pueda
        // reencolarse dos veces.
        //
        // Si el huésped escribió mientras tanto, este trabajo morirá igual en el guardia de
        // ráfaga de AiConversationProcessor: son dos redes distintas y la segunda sigue
        // puesta. Ver PreRouterRafaga.
        if (!$dispatch->yaEsperado && $this->preRouter->debeEsperar($msg)) {
            $this->bus->dispatch(
                new ProcessInboundIntentDispatch($dispatch->messageId, yaEsperado: true),
                [new DelayStamp($this->esperaRafaga * 1000)]
            );

            return;
        }

        // 🔒 UN SOLO WORKER POR MENSAJE.
        //
        // Los guardias de `AiConversationProcessor` preguntan «¿ya hay respuesta?», y contra dos
        // trabajos que corren a la vez pierden siempre: ninguno ha escrito nada todavía cuando
        // el otro mira. Pasó de verdad —10/08/2026 14:21:28— con DOS respuestas casi idénticas
        // en el mismo segundo y una tercera tres segundos después.
        //
        // El lock es de MySQL (`GET_LOCK`) y no del componente Lock de Symfony: no está
        // instalado, y meterlo obligaría a un composer en producción para algo que la base ya
        // hace. Es CONSULTIVO —no bloquea filas ni abre transacción—, así que no retiene nada
        // durante los 20-60 s que puede tardar el modelo.
        //
        // Con espera 0: el segundo trabajo no hace cola, se retira. Si de verdad hubiera algo
        // nuevo que contestar, ya vendrá su propio mensaje con su propio trabajo.
        if (!$this->tomarElTurno($dispatch->messageId)) {
            return;
        }

        try {
            // ⚠️ RE-LEER YA CON EL TURNO TOMADO. El `resolved` de arriba se comprobó sin el
            // lock, y entre aquella lectura y esta línea ha pasado el pre-router: una petición
            // de red que puede tardar segundos. En ese hueco otro worker ha podido atender el
            // mensaje entero, contestar y soltar el turno — y este trabajo entraría con un
            // `resolved` de antes, a hacerlo todo otra vez.
            //
            // En la ruta de texto libre se salvaba de rebote, porque el guardia
            // `yaSeRespondio()` recarga la colección. En la determinista NO hay guardia: sería
            // una plantilla duplicada al huésped. Comprobar → bloquear → VOLVER A COMPROBAR es
            // la regla de cualquier lock, y aquí faltaba el tercer paso.
            $this->em->refresh($msg);

            if (($msg->getInboundIntent()['resolved'] ?? true) !== false) {
                return;
            }

            // 2. Le pasamos el control a tu nuevo Router Determinista/IA
            $this->intentRouter->routeIntent($msg);
        } finally {
            $this->soltarElTurno($dispatch->messageId);
        }
    }

    /**
     * ¿Soy el único que está atendiendo este mensaje?
     *
     * ⚠️ El lock vive en la SESIÓN de MySQL, no en la transacción: sobrevive a los commits que
     * haga el turno por dentro —que son varios— y se suelta solo si la conexión se cae. Eso
     * último es justo lo que se quiere: un worker que muera a media respuesta no deja el
     * mensaje bloqueado para siempre.
     *
     * Si la consulta falla por lo que sea, se deja pasar. Un duplicado ocasional es peor que un
     * silencio, pero un silencio SISTEMÁTICO por un lock que no se puede pedir es peor que los
     * dos.
     */
    private function tomarElTurno(string $messageId): bool
    {
        try {
            $resultado = $this->em->getConnection()
                ->fetchOne('SELECT GET_LOCK(?, 0)', [$this->nombreDelLock($messageId)]);
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                '[Turno] No se pudo pedir el lock de %s (%s); se deja pasar y se acepta el '
                . 'riesgo de duplicado.',
                $messageId,
                $e->getMessage()
            ));

            return true;
        }

        // ⚠️ `GET_LOCK` devuelve NULL en error interno, SIN lanzar. Un `(int) null` es 0, o sea
        // «lo tiene otro», y el trabajo se retiraba dejando el mensaje sin contestar — lo
        // contrario de la política declarada. Se trata igual que la excepción: se deja pasar.
        if ($resultado === null) {
            $this->logger->error(sprintf(
                '[Turno] GET_LOCK devolvió NULL para %s; se deja pasar.',
                $messageId
            ));

            return true;
        }

        if ((int) $resultado !== 1) {
            // Ruidoso a propósito: es lo único que distingue «mi gemelo está trabajando» —lo
            // normal— de «un worker lleva media hora colgado reteniendo el turno», que deja
            // todos los trabajos siguientes de ese mensaje sin contestar y en silencio.
            $this->logger->info(sprintf('[Turno] %s lo tiene otro worker; me retiro.', $messageId));

            return false;
        }

        return true;
    }

    private function soltarElTurno(string $messageId): void
    {
        try {
            $this->em->getConnection()
                ->executeStatement('SELECT RELEASE_LOCK(?)', [$this->nombreDelLock($messageId)]);
        } catch (Throwable $e) {
            // Que no se pueda soltar no puede tumbar el turno: MySQL lo libera al cerrar la
            // sesión, y el trabajo ya hizo lo suyo. Pero se anota: si esto sale a menudo, hay
            // algo raro con la conexión.
            $this->logger->warning(sprintf(
                '[Turno] No se pudo soltar el lock de %s (%s).',
                $messageId,
                $e->getMessage()
            ));
        }
    }

    /**
     * El nombre lleva la BASE DE DATOS delante.
     *
     * `GET_LOCK` no sabe de bases: su espacio de nombres es el SERVIDOR entero. Con un clon de
     * la base en la misma máquina —un staging, una copia para pruebas— los UUID son los mismos,
     * y un worker de pruebas atendiendo un mensaje bloquearía al de producción para ese mismo
     * id. El síntoma sería un retiro silencioso, de los más difíciles de atribuir.
     *
     * MySQL corta a 64 caracteres: «intent-» + base + «-» + UUID cabe de sobra.
     */
    private function nombreDelLock(string $messageId): string
    {
        return substr('intent-' . $this->em->getConnection()->getDatabase() . '-' . $messageId, 0, 64);
    }
}