<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Command\EntradaDeConsola;
use App\Exchange\Enum\ConnectivityProvider;
use App\Exchange\Repository\ExchangeEndpointRepository;
use App\Exchange\Service\Context\SyncContext;
use App\Pms\Entity\PmsBookingsPullQueue;
use App\Pms\Entity\PmsBookingsPushQueue;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Factory\PmsBookingsPushQueueFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Deshace un marcador PMS duplicado: dos reservas de Beds24 con el mismo `custom1`.
 *
 * ── Qué pasó para necesitar esto ────────────────────────────────────────────
 * El `custom1` que empujamos (`PMS:<uuid del link>`) es nuestra ancla de identidad, pero en
 * Beds24 NO es única. Si por lo que sea nacen dos reservas con el mismo marcador, las dos
 * apuntan al mismo link, y hasta el 27/09/2026 el pull adoptaba la última que pasara por
 * delante: le pisaba el `beds24BookId` al link y el link se ponía a seguir a la equivocada.
 *
 * Pasó con Melanie (29ZY2P, 13–15/11/2026). `93628251` —la completa, con apellido— seguía
 * confirmada en Beds24; el operador canceló la duplicada `93628253`, y como el link ya seguía a
 * ésa, **la cancelación entró al PMS como si fuera la estancia buena**: evento `cancelada`,
 * casita libre en el calendario y la reserva vendida en Beds24. Lo que lo hacía urgente no era
 * el duplicado, era eso.
 *
 * Desde entonces {@see \App\Pms\Service\Exchange\Tasks\BookingsPull\BookingPullPersister::upsert()}
 * resuelve primero por `bookId` y sólo adopta por marcador un link LIBRE; el choque ya no pisa
 * nada y sale por el log. Este comando es la otra mitad: deshacer el que sí llegó a pisar.
 *
 * ── Por qué no se arregla desde el panel ────────────────────────────────────
 * `beds24_book_id` no es un campo que se edite: es el hilo que une nuestro evento con Beds24, y
 * tocarlo a mano dejaría el resto del arreglo sin hacer. Hacen falta tres cosas a la vez, y en
 * este orden.
 *
 * ── Las tres cosas ──────────────────────────────────────────────────────────
 * 1. **Devolver el link a la reserva buena.** Sólo eso: el `beds24BookId` y el `lastSeenAt`.
 * 2. **Pedir un pull del día de llegada.** El estado del evento NO se escribe a mano aquí: lo
 *    repone el pull leyendo Beds24, que es de donde tenía que haber salido siempre. Con el link
 *    ya apuntando bien, la buena entra por `bookId` y la duplicada se queda fuera por la guarda
 *    nueva.
 * 3. **Encolar el DELETE de la duplicada**, por la misma cola que cualquier otro borrado
 *    ({@see \App\Pms\Service\Queue\Beds24BookingsPushQueueCreator}: `link` a NULL y el id en el
 *    snapshot). Beds24 sólo borra reservas canceladas, así que la duplicada tiene que estar ya
 *    cancelada allí — que es como llega a nuestras manos.
 *
 * ── Por qué corre en contexto PULL ──────────────────────────────────────────
 * Mover el `beds24BookId` es una escritura sobre el link, y un link principal que cambia
 * dispara `Beds24BookingsPushQueueListener`. Ese push viajaría con el estado que el evento tiene
 * AHORA —`cancelada`, el que metió la duplicada— y en una reserva directa el `status` siempre
 * viaja: cancelaría en Beds24 la reserva buena. Justo lo contrario de lo que venimos a hacer.
 *
 * No se apaga con un flag: se entra en `MODE_PULL`, que es lo que esto ES —estamos escribiendo
 * lo que Beds24 ya dice—, y el creador de colas se aparta solo para los links principales.
 */
#[AsCommand(
    name: 'app:pms:beds24:reparar-marcador-duplicado',
    description: 'Devuelve un link a su reserva de Beds24 y manda borrar la duplicada que le robó el marcador.'
)]
final class PmsBeds24RepararMarcadorDuplicadoCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ExchangeEndpointRepository $endpoints,
        private readonly PmsBookingsPushQueueFactory $factory,
        private readonly SyncContext $syncContext,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('link', InputArgument::REQUIRED, 'UUID del link (lo que va detrás de «PMS:» en el custom1).')
            ->addOption('quedarse', null, InputOption::VALUE_REQUIRED, 'bookId de Beds24 que SÍ es la reserva.')
            ->addOption('borrar', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'bookId duplicado a borrar en Beds24. Repetible.')
            ->addOption('ejecutar', null, InputOption::VALUE_NONE, 'Sin esto sólo dice lo que haría.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $linkId = EntradaDeConsola::texto($input->getArgument('link'), 'link');
        $quedarse = EntradaDeConsola::texto($input->getOption('quedarse'), '--quedarse');
        $aBorrar = EntradaDeConsola::textos($input->getOption('borrar'), '--borrar');
        $ejecutar = (bool) $input->getOption('ejecutar');

        if (!Uuid::isValid($linkId)) {
            $io->error(sprintf('«%s» no es un UUID.', $linkId));

            return Command::INVALID;
        }

        $link = $this->em->getRepository(PmsEventoBeds24Link::class)->find(Uuid::fromString($linkId));

        if (!$link instanceof PmsEventoBeds24Link) {
            $io->error(sprintf('No existe el link %s.', $linkId));

            return Command::INVALID;
        }

        // ── Comprobaciones. Si alguna falla no se toca nada: media reparación es peor ──────
        $problemas = [];

        if ($quedarse === '' || !ctype_digit($quedarse)) {
            $problemas[] = sprintf('--quedarse tiene que ser un bookId numérico; llegó «%s».', $quedarse);
        }

        if ([] === $aBorrar) {
            $problemas[] = 'No has dicho qué duplicada borrar (--borrar).';
        }

        foreach ($aBorrar as $id) {
            if ($id === $quedarse) {
                $problemas[] = sprintf('%s está en --quedarse y en --borrar a la vez.', $id);
            }
        }

        // La buena no puede estar reclamada por OTRO link: eso no sería un duplicado de Beds24,
        // sería que dos links nuestros se pelean por la misma reserva, y se arregla en otro sitio.
        $duenoDeLaBuena = $this->linkQueSigueA($quedarse);

        if ($duenoDeLaBuena !== null && (string) $duenoDeLaBuena->getId() !== $linkId) {
            $problemas[] = sprintf('La reserva %s ya la sigue el link %s.', $quedarse, $duenoDeLaBuena->getId());
        }

        foreach ($aBorrar as $id) {
            $dueno = $this->linkQueSigueA($id);

            if ($dueno !== null && (string) $dueno->getId() !== $linkId) {
                $problemas[] = sprintf('La %s no está suelta: la sigue el link %s. No se borra lo que alguien usa.', $id, $dueno->getId());
            }
        }

        $evento = $link->getEvento();

        if ($evento === null) {
            $problemas[] = 'El link no cuelga de ningún evento: sin él no hay día de llegada que volver a leer.';
        }

        if ([] !== $problemas) {
            $io->error('No se toca nada:');
            $io->listing($problemas);

            return Command::FAILURE;
        }

        $llegada = $evento?->getInicio();

        $io->definitionList(
            ['Link' => $linkId],
            ['Sigue ahora a' => $link->getBeds24BookId() ?? '(ninguna)'],
            ['Pasará a seguir a' => $quedarse],
            ['Se mandará borrar' => implode(', ', $aBorrar)],
            ['Evento' => $evento !== null ? (string) $evento->getId() : '—'],
            ['Estado del evento' => $evento?->getEstado()?->getId() ?? '—'],
            ['Se pedirá pull de' => $llegada?->format('Y-m-d') ?? '—'],
        );

        if (!$ejecutar) {
            $io->note('Ensayo. Añade --ejecutar para hacerlo.');

            return Command::SUCCESS;
        }

        // ── Y ahora sí ────────────────────────────────────────────────────────────────────
        $scope = $this->syncContext->enter(SyncContext::MODE_PULL, ConnectivityProvider::BEDS24->value);

        try {
            $ahora = new DateTimeImmutable();

            $link->setBeds24BookId($quedarse);
            $link->setLastSeenAt($ahora);

            foreach ($aBorrar as $id) {
                // Primero la cancelación, después el borrado: Beds24 se niega a borrar una
                // reserva activa («cannot delete active bookings»). El desfase le da margen a
                // la primera; si aun así llega pronto, el `DELETE` falla y reintenta solo.
                $this->em->persist($this->colaDeRetirada($link, $id, $ahora));
                $this->em->persist($this->colaDeBorrado($link, $id, $ahora->modify('+2 minutes')));
            }

            if ($llegada !== null) {
                $this->em->persist($this->colaDeRelectura($link, $llegada, $ahora));
            }

            $this->em->flush();
        } finally {
            $scope->restore();
        }

        $io->success(sprintf(
            'El link %s vuelve a seguir a %s. Encolados el borrado de %s y la relectura del %s.',
            $linkId,
            $quedarse,
            implode(', ', $aBorrar),
            $llegada?->format('Y-m-d') ?? '—',
        ));

        $io->note('El estado del evento lo repone el pull. Compruébalo cuando la cola haya corrido.');

        return Command::SUCCESS;
    }

    private function linkQueSigueA(string $bookId): ?PmsEventoBeds24Link
    {
        $link = $this->em->getRepository(PmsEventoBeds24Link::class)->findOneBy(['beds24BookId' => $bookId]);

        return $link instanceof PmsEventoBeds24Link ? $link : null;
    }

    /**
     * La cancelación previa. Misma forma que el borrado —sin `link`, con el id en el snapshot—,
     * y la estrategia de mapeo la reconoce por eso mismo: sin link no hay estancia que describir,
     * así que sólo le manda el estado. Ver `BookingsPushMappingStrategy::buildUpsertPayload()`.
     */
    private function colaDeRetirada(PmsEventoBeds24Link $link, string $bookId, DateTimeImmutable $ahora): PmsBookingsPushQueue
    {
        return $this->colaSinLink(
            $link,
            $bookId,
            $ahora,
            PmsBookingsPushQueue::ACCION_POST_BOOKINGS,
            'retirada',
        );
    }

    /**
     * El DELETE, con la misma forma que le da `Beds24BookingsPushQueueCreator`: sin `link` —para
     * que el borrado no arrastre al link que acabamos de arreglar— y con el id en el snapshot,
     * que es de donde lo lee la estrategia de mapeo.
     */
    private function colaDeBorrado(PmsEventoBeds24Link $link, string $bookId, DateTimeImmutable $runAt): PmsBookingsPushQueue
    {
        return $this->colaSinLink(
            $link,
            $bookId,
            $runAt,
            PmsBookingsPushQueue::ACCION_DELETE_BOOKINGS,
            'duplicado',
        );
    }

    /**
     * Una fila de cola que apunta a una reserva de Beds24 **sin link**: ni la adopta ni la
     * resucita, sólo la nombra. El `link` sólo se usa para llegar a la config y para dejar
     * escrito de qué arreglo salió.
     */
    private function colaSinLink(
        PmsEventoBeds24Link $link,
        string $bookId,
        DateTimeImmutable $runAt,
        string $accion,
        string $prefijoDedupe,
    ): PmsBookingsPushQueue {
        $endpoint = $this->endpoints->findOneBy([
            'provider' => ConnectivityProvider::BEDS24,
            'accion' => $accion,
            'activo' => true,
        ]);

        if ($endpoint === null) {
            throw new \RuntimeException(sprintf('No hay endpoint activo «%s» en Beds24.', $accion));
        }

        $config = $link->getUnidadBeds24Map()?->getPmsUnidadOrFail()->getEstablecimientoOrFail()->getBeds24ConfigOrFail();

        if ($config === null) {
            throw new \RuntimeException('El link no llega a ninguna config de Beds24.');
        }

        $cola = $this->factory->create($config, $endpoint);
        $cola->setLink(null);
        $cola->setLinkIdOriginal((string) $link->getId());
        $cola->setBeds24BookIdOriginal($bookId);
        $cola->setDedupeKey(sprintf('%s:%s:provider:%s:endpoint:%s', $prefijoDedupe, $bookId, ConnectivityProvider::BEDS24->value, $accion));
        $cola->setRunAt($runAt);

        return $cola;
    }

    /**
     * Un pull del día de llegada, igual que el que encola el cron por tramos. Es quien devuelve
     * al evento el estado que Beds24 tiene, en vez de escribirlo nosotros de memoria.
     */
    private function colaDeRelectura(PmsEventoBeds24Link $link, \DateTimeInterface $llegada, DateTimeImmutable $ahora): PmsBookingsPullQueue
    {
        $endpoint = $this->endpoints->findOneBy([
            'provider' => ConnectivityProvider::BEDS24,
            'accion' => 'GET_BOOKINGS',
            'activo' => true,
        ]);

        if ($endpoint === null) {
            throw new \RuntimeException('No hay endpoint activo de lectura de reservas en Beds24.');
        }

        $config = $link->getUnidadBeds24Map()?->getPmsUnidadOrFail()->getEstablecimientoOrFail()->getBeds24ConfigOrFail();

        if ($config === null) {
            throw new \RuntimeException('El link no llega a ninguna config de Beds24.');
        }

        $dia = DateTimeImmutable::createFromInterface($llegada);

        $cola = new PmsBookingsPullQueue();
        $cola->setConfig($config);
        $cola->setEndpoint($endpoint);
        $cola->setArrivalFrom($dia);
        $cola->setArrivalTo($dia);
        $cola->setStatus(PmsBookingsPullQueue::STATUS_PENDING);
        $cola->setRunAt($ahora);

        return $cola;
    }
}
