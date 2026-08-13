<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Message\Entity\MessageConversation;
use App\Pms\Entity\PmsConversacionEnlace;
use App\Pms\Entity\PmsReserva;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Crea el ENLACE que le falta a cada conversación de alojamiento.
 *
 * Segundo paso de la cirugía (ver docs/Mensajeria.md §20): las conversaciones ya apuntan a una
 * reserva por `context_type`/`context_id`, pero apuntan a **una sola**, y por eso hace falta una
 * conversación por reserva. Este comando copia ese apuntador a `pms_conversacion_enlace`, que
 * admite varios por hilo.
 *
 * ── Es una COPIA, y las columnas viejas siguen mandando ─────────────────────
 * Aditivo por completo: no toca `context_type`, ni `context_id`, ni `context_data`, ni borra ni
 * fusiona nada. Al terminar, cada conversación de alojamiento tiene exactamente **un** enlace
 * con los mismos datos que ya tenía dentro del JSON. Nada lo lee todavía —el motor de reglas
 * sigue programando por conversación—, así que se puede ejecutar en producción y volver atrás
 * borrando la tabla.
 *
 * Los hilos duplicados de una misma persona se fusionarán después, y entonces sus enlaces se
 * juntarán bajo una sola conversación: por eso el enlace nace ahora, para que la fusión tenga
 * dónde poner cada asunto en vez de tener que elegir uno.
 *
 * ── Qué se copia ────────────────────────────────────────────────────────────
 * Lo del ACTIVO y sólo eso: `vinculo`, `milestones`, `origin`, `agency`, `status_tag`. Los
 * `financials` e `items` del mismo JSON se quedan donde están —son una foto de la cuenta para
 * el motor de plantillas y su sitio natural es `PmsInformacionFinanciera`, no una copia más—.
 *
 * Uso:
 *   php bin/console app:conversaciones:enlazar --dry-run
 *   php bin/console app:conversaciones:enlazar
 */
#[AsCommand(
    name: 'app:conversaciones:enlazar',
    description: 'Crea el PmsConversacionEnlace de cada conversación de alojamiento que no lo tenga.'
)]
final class PoblarEnlacesCommand extends Command
{
    /** Cada cuántos enlaces se vuelca a la base: evita un flush de miles de objetos al final. */
    private const int LOTE = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin escribir nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $io = new SymfonyStyle($input, $output);

        $conversaciones = $this->em->getRepository(MessageConversation::class)
            ->findBy(['contextType' => PmsConversacionEnlace::CONTEXT_TYPE]);

        $io->text(sprintf('Conversaciones de alojamiento: %d', count($conversaciones)));

        $creados = 0;
        $yaTenian = 0;
        $canceladas = 0;
        $sobrantes = 0;
        $huerfanas = [];

        foreach ($conversaciones as $conversacion) {
            if (!$conversacion->getEnlacesPms()->isEmpty()) {
                $yaTenian++;
                continue;
            }

            $reserva = $this->em->getRepository(PmsReserva::class)->find($conversacion->getContextId());

            // Una conversación que apunta a una reserva borrada. No se inventa el enlace: se
            // deja constancia y se sigue. Es un dato roto que ya existía —la FK vieja es un
            // string sin integridad referencial—, y taparlo aquí sería esconder el problema
            // justo cuando por fin hay una FK de verdad que lo impediría en adelante.
            if ($reserva === null) {
                $huerfanas[] = (string) $conversacion->getId();
                continue;
            }

            // Una reserva CANCELADA no es un asunto: no hay nada que atender, nada que
            // programar y nada de lo que hablar.
            //
            // ⚠️ Y no es un caso raro: la base arrastra reservas duplicadas nacidas de un bug de
            // guardado que quedaron canceladas —siete filas donde debía haber una con cuatro
            // eventos—. Enlazarlas las convertiría en siete asuntos vivos colgando de un hilo, y
            // el día que el motor programe por asunto se pondría a regenerar mensajes por cada
            // una. El enlace es justo la puerta por la que eso entraría, así que se cierra aquí.
            if ($reserva->isCancelada()) {
                $canceladas++;
                continue;
            }

            $creados++;

            if ($dryRun) {
                continue;
            }

            $enlace = new PmsConversacionEnlace($conversacion, $reserva);
            $enlace->setVinculo($conversacion->getContextVinculo() ?? $enlace->getVinculo());
            $enlace->setMilestones($conversacion->getContextMilestones());
            $enlace->setOrigen($conversacion->getContextOrigin());
            $enlace->setAgencia($conversacion->getContextAgency());
            $enlace->setStatusTag($conversacion->getContextStatusTag());

            $this->em->persist($enlace);

            if ($creados % self::LOTE === 0) {
                $this->em->flush();
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        // Los enlaces de reservas canceladas que se crearon ANTES de que existiera el filtro de
        // arriba. Se retiran aquí y no a mano: son filas que nadie lee todavía —el motor sigue
        // programando por conversación—, así que quitarlas no tiene efecto ninguno hoy y evita
        // que el motor las encuentre vivas mañana.
        if (!$dryRun) {
            $sobrantes = $this->retirarCancelados();
        }

        $io->table(
            ['Enlaces nuevos', 'Ya tenían', 'Saltados por cancelada', 'Retirados (cancelados ya enlazados)', 'Apuntan a una reserva que no existe'],
            [[$creados, $yaTenian, $canceladas, $sobrantes, count($huerfanas)]]
        );

        if ($huerfanas !== []) {
            $io->warning(sprintf(
                'Estas %d conversaciones apuntan a una reserva borrada y se quedan sin enlace: %s',
                count($huerfanas),
                implode(', ', array_slice($huerfanas, 0, 10)) . (count($huerfanas) > 10 ? '…' : '')
            ));
        }

        $dryRun
            ? $io->warning(sprintf('DRY RUN: se crearían %d enlaces.', $creados))
            : $io->success(sprintf('%d enlaces creados.', $creados));

        return Command::SUCCESS;
    }

    /**
     * Borra los enlaces cuya reserva está cancelada.
     *
     * Existe porque la primera versión de este comando no filtraba, y dejó enlazadas reservas
     * canceladas —entre ellas las duplicadas de un bug de guardado—. Se corrige aquí en vez de
     * con SQL a mano para que la reparación quede escrita, se pueda repetir y no dependa de que
     * alguien recuerde qué consulta lanzó.
     *
     * Es seguro: hoy nadie lee esta tabla.
     */
    private function retirarCancelados(): int
    {
        $retirados = 0;

        foreach ($this->em->getRepository(PmsConversacionEnlace::class)->findAll() as $enlace) {
            $reserva = $enlace->getReserva();

            if ($reserva === null || $reserva->isCancelada()) {
                $this->em->remove($enlace);
                $retirados++;
            }
        }

        if ($retirados > 0) {
            $this->em->flush();
        }

        return $retirados;
    }
}
