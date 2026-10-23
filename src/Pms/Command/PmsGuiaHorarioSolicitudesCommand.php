<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsGuiaItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pone al día la ficha «Solicitudes y requerimientos» (`horario-solicitudes`): el 9:00–21:00 es el
 * horario en que se RECIBEN las solicitudes, que se programan para el DÍA SIGUIENTE (22/10/2026, Jorge).
 *
 * Decía «Horario de atención al cliente: 9:00 a.m. – 9:00 p.m.», que se lee como «en ese horario te
 * lo traemos». Con eso y con la ficha de la plancha («pídela y te la llevamos»), el agente le prometió
 * la plancha a las 17:54 a quien la quería para salir esa noche (José Paredes, AG2CZY), cuando el
 * personal ya no estaba en la zona. Ver `docs/PmsGuiaHuesped.md` §7 septies.
 *
 * Después de las 21:00 se le informa, se anota igual y se intenta para el día siguiente sin
 * prometerlo; lo que llega antes de las 9:00 cuenta como del día. La misma regla, con las mismas horas, en `AnotarPeticionSkill::cuandoSeAtiende()`.
 *
 * Reescribe el ESPAÑOL de `descripcion` (el listener traduce los demás), `agenteContenido` y los
 * términos. El párrafo del teléfono del agente se conserva tal cual. Con `--dry-run`. Nace `hidden`.
 */
#[AsCommand(
    name: 'app:pms:guia:horario-solicitudes',
    description: 'Pone al día «Solicitudes y requerimientos»: se reciben de 9:00 a 21:00 y se atienden al día siguiente.',
    hidden: true,
)]
final class PmsGuiaHorarioSolicitudesCommand extends Command
{
    private const string CODIGO = 'horario-solicitudes';

    private const string NOMBRE = 'Horario solicitudes (general)';

    private const string DESCRIPCION = '<p><strong>🕘 Recibimos tus solicitudes de 9:00 a.m. a 9:00 p.m.</strong> '
        . 'y las preparamos para el <strong>día siguiente</strong>.</p>'
        . '<p>Si necesitas papel higiénico, jabón líquido, la plancha u otra cosa para tu casita, avísanos '
        . '<strong>con un día de anticipación</strong> para llevártelo a tiempo.</p>'
        . '<p>📞 Escríbenos por WhatsApp al {{ whatsapp_numero }}.</p>';

    private const string AGENTE = 'De 9:00 a 21:00 el equipo RECIBE las solicitudes y las programa para el DÍA '
        . 'SIGUIENTE: no hay entregas el mismo día. Para lo que haya que llevar a la casita (papel higiénico, jabón, '
        . 'la plancha, toallas…): anótalo con anotar_peticion y dile que se lo llevan mañana. '
        . 'Si lo pide DESPUÉS de las 21:00, díselo con amabilidad: se anota igual y se intentará llevárselo al día '
        . 'siguiente, pero sin asegurárselo. Lo que pide antes de las 9:00 lo ve el equipo ese mismo día: va para '
        . 'mañana, como lo de horario. '
        . 'Si lo necesita HOY con urgencia, no le prometas que llega hoy: escala con escalar_al_equipo diciendo qué '
        . 'necesita y para cuándo, y dile que el equipo le confirma si hay forma. '
        . 'Lo que no puede esperar —no puede entrar, una avería, algo que pasa ahora— no es una solicitud: va por su '
        . 'camino (guía y escalado), a cualquier hora.'
        . "\n\n"
        . 'SI PIDE UN TELÉFONO: los números existen, pero NO están escritos aquí. Te los da consultar_codigos en el '
        . 'campo «contacto» — pídelos ahí y pasalos tal cual. Uno de los dos es además el número del Yape. No hay '
        . 'copia en esta ficha a proposito: envejecería el día que cambien.';

    private const string TERMINOS = 'horario de atencion, a que hora atienden, hasta que hora, contacto, pedir algo, '
        . 'solicitud, me pueden traer, necesito que me traigan, papel higienico, jabon, suministros, cuando me lo traen, '
        . 'support hours, opening hours, contact, can you bring';

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Sólo dice qué haría.')] bool $dryRun = false,
    ): int {
        // Por código y, si no lo tiene (bases donde no pasó `PmsGuiaCodigosCommand`), por su nombre.
        $repo = $this->em->getRepository(PmsGuiaItem::class);
        $item = $repo->findOneBy(['codigo' => self::CODIGO]) ?? $repo->findOneBy(['nombreInterno' => self::NOMBRE]);

        if (!$item instanceof PmsGuiaItem) {
            $io->error(sprintf('No existe la ficha «%s».', self::CODIGO));

            return Command::FAILURE;
        }

        $io->section('Al huésped (es)');
        $io->writeln(self::DESCRIPCION);
        $io->section('Al agente');
        $io->writeln(self::AGENTE);

        if ($dryRun) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        // Sólo el español: con el resto fuera, el listener vuelve a traducir los seis idiomas.
        $item->setDescripcion([['language' => 'es', 'content' => self::DESCRIPCION]]);
        $item->setAgenteContenido(self::AGENTE);
        $item->setAgenteTerminos(self::TERMINOS);
        $this->em->flush();

        $io->success(sprintf('«%s» al día, en %d idiomas.', $item->getNombreInterno(), count($item->getDescripcion() ?? [])));

        return Command::SUCCESS;
    }
}
