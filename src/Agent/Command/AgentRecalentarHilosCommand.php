<?php

declare(strict_types=1);

namespace App\Agent\Command;

use App\Agent\Service\AiConversationProcessor;
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
 * Recoge lo que el agente dejó pasar porque había un humano atendiendo, cuando ya no lo hay.
 *
 * ── El agujero ──────────────────────────────────────────────────────────────
 * `AiConversationProcessor::hayHumanoAtendiendo()` calla al bot si una persona del alojamiento
 * escribió hace poco, y hace bien: nada deja peor al hotel que un bot pisando a quien ya está
 * atendiendo. Pero el agente **sólo reacciona a mensajes entrantes**. Cuando descarta uno por
 * esa razón y la ventana expira, nadie vuelve a mirar ese mensaje: se queda esperando a que el
 * huésped insista, o a nadie.
 *
 * Medido el 30/08/2026 sobre los 34 descartes por `humano_atendiendo` desde julio: 23 se
 * resolvieron solos —el humano seguía ahí— y el resto no.
 *
 * | Caso | Espera |
 * |---|---|
 * | «¿Y cuánto sería por esas dos noches por 3 personas?» | 112 min, y sólo porque insistió |
 * | «could it even be at 10:45?» | **jamás contestada** |
 * | «¿El check in podría ser entre 8 y 8:30pm?» | 54 min |
 *
 * Tres pérdidas en dos meses, una total. Poco volumen y mucho silencio, que es el peor
 * combinado: nadie se entera de lo que no pasó.
 *
 * ── Qué hace ────────────────────────────────────────────────────────────────
 * Busca mensajes entrantes que cumplan las cuatro cosas y los devuelve al procesador normal,
 * que aplicará sus seis guardias como si acabaran de llegar:
 *
 * 1. La resolución fue `humano_atendiendo`.
 * 2. **Son el último mensaje del hilo** — nada después, ni entrante ni saliente. Si el huésped
 *    volvió a escribir, ese mensaje posterior ya pasó por el procesador y es el que manda; si
 *    contestó alguien, no hay nada que recuperar.
 * 3. La ventana de `HUMANO_AL_MANDO` ya expiró. No se recomprueba aquí: se deja que la
 *    compruebe el propio procesador, que es quien tiene esa constante. Aquí sólo se descartan
 *    los que evidentemente siguen dentro, para no gastar una llamada al modelo.
 * 4. No son tan viejos como para que contestar sea peor que callar (`--maximo-horas`).
 *
 * ── ⚠️ Lo que este comando NO puede saber ───────────────────────────────────
 * «Nada después en el chat» no es «nadie lo atendió». Si el operador lo resolvió por teléfono o
 * en recepción, aquí no consta y el bot contestará igual. Es el riesgo asumido, y es el motivo
 * de que `--dry-run` exista y de que convenga mirarlo unos días antes de programarlo.
 *
 * Y recalentará también los «Gracias»: el agente contestará «¡De nada!» media hora tarde, que
 * queda algo robótico. Filtrarlos por longitud o por palabras es una heurística que fallará —el
 * rastro no distingue un «gracias» de una pregunta, todos son `free_text`—, así que decide el
 * agente. Un «de nada» tardío cuesta mucho menos que una consulta de venta perdida.
 *
 *   php bin/console agent:recalentar-hilos --dry-run
 *   php bin/console agent:recalentar-hilos
 */
#[AsCommand(
    name: 'agent:recalentar-hilos',
    description: 'Reprocesa lo que el agente calló por haber un humano atendiendo, si ya no lo hay.',
)]
final class AgentRecalentarHilosCommand extends Command
{
    /**
     * Suelo de espera antes de recalentar, en minutos.
     *
     * Tiene que ser **mayor** que `AiConversationProcessor::HUMANO_AL_MANDO` (20 min): si fuera
     * igual, el comando gastaría una llamada al modelo para que el procesador lo descartara por
     * el mismo motivo. Cinco minutos de margen cubren el desfase entre el reloj del cron y el de
     * la consulta.
     *
     * No se lee de la constante del procesador a propósito: es privada, y exponerla para esto
     * ataría dos decisiones que se toman con datos distintos.
     */
    private const int ESPERA_MINIMA = 25;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AiConversationProcessor $procesador,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice a quién contestaría')
            ->addOption(
                'maximo-horas',
                null,
                InputOption::VALUE_REQUIRED,
                'No recalentar lo más viejo que esto: contestar tardísimo es peor que callar',
                '12',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simular = (bool) $input->getOption('dry-run');
        $maximoHoras = max(1, (int) $input->getOption('maximo-horas'));

        $hasta = new DateTimeImmutable(sprintf('-%d minutes', self::ESPERA_MINIMA));
        $desde = new DateTimeImmutable(sprintf('-%d hours', $maximoHoras));

        // El filtro por resolución se hace en PHP y no en la consulta: DQL no conoce
        // `JSON_EXTRACT` si no se registra como función, y registrarla para esto ataría el
        // esquema de `metadata` —que es libre a propósito— a una consulta. La ventana son horas
        // y los entrantes de un día caben de sobra en memoria; se lee con el accesor de la
        // entidad, que es quien sabe dónde vive `inbound_intent`.
        /** @var list<Message> $recientes */
        $recientes = $this->em->getRepository(Message::class)->createQueryBuilder('m')
            ->andWhere('m.direction = :entrante')
            ->andWhere('m.createdAt BETWEEN :desde AND :hasta')
            ->setParameter('entrante', Message::DIRECTION_INCOMING)
            ->setParameter('desde', $desde)
            ->setParameter('hasta', $hasta)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        $candidatos = array_values(array_filter(
            $recientes,
            static fn (Message $m): bool => ($m->getInboundIntent()['resolution'] ?? null) === 'humano_atendiendo',
        ));

        if ($candidatos === []) {
            $io->success('Nada que recalentar.');

            return Command::SUCCESS;
        }

        $recalentados = 0;
        $saltados = 0;

        foreach ($candidatos as $mensaje) {
            if (!$this->esElUltimo($mensaje)) {
                ++$saltados;
                continue;
            }

            $conversacion = $mensaje->getConversation();
            $quien = $conversacion?->getGuestName() ?? '(sin nombre)';
            $texto = trim((string) ($mensaje->getContentExternal() ?? $mensaje->getContentLocal() ?? ''));
            $espera = (int) round((time() - $mensaje->getCreatedAt()->getTimestamp()) / 60);

            $io->writeln(sprintf(
                '  <info>%-26s</info> %3d min  %s',
                mb_substr($quien, 0, 26),
                $espera,
                mb_substr(str_replace("\n", ' ', $texto), 0, 70),
            ));

            if ($simular) {
                ++$recalentados;
                continue;
            }

            // El procesador vuelve a aplicar sus seis guardias, incluida la del humano: si entre
            // la consulta y este momento alguien escribió, se descarta ahí y no aquí.
            $resolucion = $this->procesador->process($mensaje);
            $io->writeln(sprintf('       → %s', $resolucion));
            ++$recalentados;
        }

        if ($saltados > 0) {
            $io->writeln(sprintf('  <comment>%d ya tenían algo después en el hilo.</comment>', $saltados));
        }

        if ($recalentados === 0) {
            $io->success('Nada que recalentar: todos tenían respuesta o mensaje posterior.');

            return Command::SUCCESS;
        }

        if ($simular) {
            $io->note(sprintf('Simulación: se recalentarían %d. Sin --dry-run se contesta de verdad.', $recalentados));

            return Command::SUCCESS;
        }

        $io->success(sprintf('%d hilo(s) recalentado(s).', $recalentados));

        return Command::SUCCESS;
    }

    /**
     * ¿No hay NADA después de este mensaje en su conversación?
     *
     * Ni entrante ni saliente. Un entrante posterior ya pasó por el procesador y es el que
     * manda —contestar el viejo sería contestar a destiempo—; un saliente posterior significa
     * que alguien ya respondió, sea la persona o el bot.
     *
     * ⚠️ Se consulta a la TABLA y no a `$conversacion->getMessages()`, por lo mismo que lo hace
     * `hayHumanoAtendiendo()`: la colección puede venir de una carga anterior del turno, y aquí
     * un mensaje que no se vea significa contestar encima de alguien.
     */
    private function esElUltimo(Message $mensaje): bool
    {
        $conversacion = $mensaje->getConversation();

        if ($conversacion === null) {
            return false;
        }

        $posteriores = (int) $this->em->getRepository(Message::class)->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.conversation = :conversacion')
            ->andWhere('m.status != :cancelado')
            ->andWhere('COALESCE(m.scheduledAt, m.createdAt) > :cuando')
            ->setParameter('conversacion', $conversacion->getId(), 'uuid')
            ->setParameter('cancelado', Message::STATUS_CANCELLED)
            ->setParameter('cuando', $mensaje->getCreatedAt())
            ->getQuery()
            ->getSingleScalarResult();

        return $posteriores === 0;
    }
}
