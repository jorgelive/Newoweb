<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Message\Entity\Message;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deja UNA sola cola viva por envío programado, y cancela las copias.
 *
 * ── Qué pasó ────────────────────────────────────────────────────────────────
 * `MessageDispatcher` marcaba `failed` el mensaje cuando el segundo despacho no creaba ninguna
 * cola NUEVA — y no la creaba porque la barrera de idempotencia veía la del primero. Sus colas
 * seguían `pending`. Y un mensaje `failed` deja de ser el intento vigente de su regla: el motor
 * fabricaba otro en la pasada siguiente, con su propia cola. Tres por pasada. El porqué y la
 * medición completa están en el comentario del dispatcher, que es donde se arregló.
 *
 * Esto es sólo la basura que quedó puesta antes del arreglo. Medido el 14/09/2026:
 *
 * | Huésped | Plantilla | Sale el | Copias |
 * |---|---|---|---|
 * | Vanessa (2KRERH) | `recordatorio_llegada` | 04/10 08:00 | 71 por WhatsApp + 71 por Booking |
 * | Vanessa (2KRERH) | `check_out` | 10/10 12:00 | 71 + 71 |
 * | Vanessa (2KRERH) | `despedida_booking` | 11/10 11:00 | 71 + 71 |
 * | Karina (P9Y2XK) | `recordatorio_llegada` | **29/09 08:00** | 37 por WhatsApp |
 *
 * Sin esto, el 29 de septiembre a las 8:00 Karina recibe la guía de llegada 37 veces seguidas.
 *
 * ── Cómo decide ─────────────────────────────────────────────────────────────
 * Agrupa por lo que define UN envío —asunto, regla y minuto de salida— y dentro de cada grupo
 * conserva el mensaje **más reciente** que todavía tenga colas vivas; los demás pasan a
 * `cancelled`, y sus colas las cancela en cascada el `preUpdate` de
 * {@see \App\Message\EventListener\Queue\MessageEnqueuerEntityListener} — el mismo camino que usa
 * el motor, no un `UPDATE` paralelo que dejaría la cola viva y el mensaje muerto.
 *
 * El que se conserva se deja en `queued` si venía en `failed`: es lo que de verdad es, y así el
 * motor vuelve a reconocerlo como suyo en lugar de fabricar el siguiente.
 *
 * ⚠️ Sólo mira **envíos futuros**: lo ya salido no se toca ni se reescribe.
 *
 * Nace `hidden` porque es de una vez — regla de archivado en `CLAUDE.md`.
 */
#[AsCommand(
    name: 'app:msg:colas-duplicadas',
    description: 'Deja una sola cola viva por envío programado y cancela las copias. Idempotente.',
    hidden: true,
)]
final class MessageColasDuplicadasCommand extends Command
{
    /** Los estados en los que una cola todavía va a salir. */
    private const array VIVAS = ['pending', 'queued', 'processing'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice qué haría');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simular = (bool) $input->getOption('dry-run');

        /** @var list<Message> $mensajes */
        $mensajes = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Message::class, 'm')
            ->where('m.scheduledAt > :ahora')
            ->andWhere('m.status IN (:estados)')
            ->setParameter('ahora', new DateTimeImmutable())
            ->setParameter('estados', [Message::STATUS_FAILED, Message::STATUS_QUEUED, Message::STATUS_PENDING])
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        /** @var array<string, list<Message>> $grupos */
        $grupos = [];

        foreach ($mensajes as $mensaje) {
            if ($this->colasVivas($mensaje) === 0) {
                continue;
            }

            $grupos[$this->llave($mensaje)][] = $mensaje;
        }

        $filas = [];
        $cancelados = 0;

        foreach ($grupos as $grupo) {
            if (count($grupo) < 2) {
                continue;
            }

            // El más reciente se queda: es el que el motor sincronizaría.
            $conserva = array_pop($grupo);

            foreach ($grupo as $copia) {
                if (!$simular) {
                    $copia->setStatus(Message::STATUS_CANCELLED);
                }

                ++$cancelados;
            }

            if ($conserva->getStatus() === Message::STATUS_FAILED && !$simular) {
                $conserva->setStatus(Message::STATUS_QUEUED);
            }

            $filas[] = [
                $conserva->getConversation()?->getGuestName() ?? '¿?',
                $conserva->getTemplate()?->getCode() ?? '(texto libre)',
                $conserva->getScheduledAt()?->format('d/m/Y H:i') ?? '¿?',
                count($grupo) + 1,
                count($grupo),
            ];
        }

        if ($filas === []) {
            $io->success('No hay envíos duplicados en cola.');

            return Command::SUCCESS;
        }

        $io->table(['Huésped', 'Plantilla', 'Sale el', 'Copias', 'Se cancelan'], $filas);

        if ($simular) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d mensajes cancelados; sus colas caen en cascada.', $cancelados));

        return Command::SUCCESS;
    }

    /**
     * Lo que define UN envío: el asunto, la regla que lo pidió y el minuto exacto de salida.
     *
     * Se cae a la plantilla cuando no hay regla —los envíos a mano no tienen— y al hilo cuando el
     * mensaje es de la era anterior y no lleva asunto estampado.
     */
    private function llave(Message $mensaje): string
    {
        return implode('|', [
            $mensaje->getAsuntoType() ?? 'hilo',
            $mensaje->getAsuntoId() ?? (string) $mensaje->getConversation()?->getId(),
            (string) ($mensaje->getRule()?->getId() ?? $mensaje->getTemplate()?->getId()),
            $mensaje->getScheduledAt()?->format('Y-m-d H:i') ?? '',
        ]);
    }

    private function colasVivas(Message $mensaje): int
    {
        $vivas = 0;

        foreach ($mensaje->getAllQueues() as $cola) {
            if (in_array($cola->getStatus(), self::VIVAS, true)) {
                ++$vivas;
            }
        }

        return $vivas;
    }
}
