<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Contract\CategoriaConocimiento;
use App\Pms\Entity\PmsGuiaItem;
use App\Pms\Entity\PmsGuiaSeccion;
use App\Pms\Entity\PmsGuiaSeccionHasItem;
use App\Pms\Enum\PmsGuiaVisibilidad;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Parte «Horario de ingreso y salida» en dos, y estrena el enlace entre fichas.
 *
 * ── Qué mezclaba ────────────────────────────────────────────────────────────
 * Una sola ficha, en la sección de Ingreso, con tres cosas dentro: las HORAS de entrada y salida,
 * el ingreso temprano / salida tarde —que **cuesta dinero**— y el guardaequipaje. Lo peor no era
 * la mezcla: era que el **guardaequipaje**, que es lo más útil y lo más vendible de las tres, vivía
 * enterrado al final de una ficha titulada «Horario de ingreso y salida». Ahí no lo busca nadie.
 *
 * ── Cómo queda ──────────────────────────────────────────────────────────────
 * | Ficha | Dónde | Qué dice |
 * |---|---|---|
 * | `early-check-in-late-check-out` | Ingreso | sólo las horas, y el enlace a la otra |
 * | `equipaje-horarios-flexibles` (nueva) | Servicios | ingreso temprano, salida tarde y equipaje |
 *
 * El enlace es el estreno de `{{ ficha: … }}`: en la guía se pinta con el título de la ficha
 * destino, y el agente lo lee como «esto está en el tema “…”, que puedes consultar».
 *
 * ⚠️ **El puntero no es decorativo.** La regla de la casa (`CLAUDE.md`) es que lo que se le quita
 * a un texto, el modelo lo NIEGA: sin la remisión, alguien que pregunte por sus maletas al llegar
 * podía acabar oyendo que no hay dónde dejarlas. Por eso se reparte también `agenteContenido`.
 *
 * Nace `hidden` porque es de una vez. Idempotente: si la ficha nueva ya existe, no la duplica.
 */
#[AsCommand(
    name: 'app:pms:guia:equipaje',
    description: 'Separa el equipaje y los horarios flexibles en su propia ficha. Idempotente.',
    hidden: true,
)]
final class PmsGuiaEquipajeCommand extends Command
{
    private const string CODIGO_NUEVA = 'equipaje-horarios-flexibles';
    private const string NOMBRE_NUEVA = 'Equipaje y horarios flexibles (general)';
    private const string SECCION = 'Servicios (general)';
    private const string DETRAS_DE = 'Horario solicitudes (general)';
    private const string CODIGO_HORARIOS = 'early-check-in-late-check-out';

    /** Sólo las horas, y la puerta a la otra ficha. */
    private const string HORARIOS = <<<'HTML'
        <p>🕒 <strong>Horarios</strong></p>
        <p>🚪 Ingreso: a partir de las {{ hora_checkin }}</p>
        <p>🗝️ Salida: hasta las {{ hora_checkout }}</p>
        <p>¿Llegas antes, sales más tarde o necesitas dejar las maletas?</p>
        <p>{{ ficha: equipaje-horarios-flexibles }}</p>
        HTML;

    private const string HORARIOS_AGENTE = <<<'TXT'
        Horarios: entrada desde "hora_check_in", salida hasta "hora_check_out", que vienen en
        esta misma respuesta. Usa esas, no des una hora estandar.

        SU GUÍA. Cuando hable de su llegada, recuerdale que las instrucciones de ingreso están en
        su guía y pasale el enlace "guide_url" de consultar_mi_reserva.

        EQUIPAJE Y HORARIOS FLEXIBLES. Si pregunta por dejar maletas, entrar antes de la hora o
        salir más tarde, eso está en el tema «Equipaje y horarios flexibles»: consúltalo y
        respóndele con lo que diga. NO le digas que no se puede.
        TXT;

    private const string EQUIPAJE = <<<'HTML'
        <p>🛎️ <strong>Ingreso temprano y salida tarde</strong></p>
        <p>Si quieres entrar antes de las {{ hora_checkin }} o salir después de las {{ hora_checkout }}, coordínalo con un día de anticipación. Está sujeto a disponibilidad y tiene un costo adicional.</p>
        <p>🎒 <strong>Guardamos tu equipaje</strong></p>
        <p>Tanto si llegas antes de la hora de entrada como si ya hiciste el check-out y sigues en la ciudad.</p>
        <p>📅 También <strong>varios días</strong> —por ejemplo mientras haces el Salkantay—: avísanos con un día de antelación.</p>
        <p>🧳 Cuando lo dejes, dinos <strong>cuántos bultos</strong> son. Puedes recogerlo antes de lo previsto sin problema.</p>
        HTML;

    private const string EQUIPAJE_AGENTE = <<<'TXT'
        EQUIPAJE. Tenemos almacen: puede dejar las maletas si llega antes de la entrada o si ya
        hizo el check-out y sigue en la ciudad. Ofrecelo con confianza. Si pregunta el costo,
        avisa al equipo.

        También se puede guardar varios días, por ejemplo mientras hace un trek: que avise con un
        día de antelación para coordinarlo, y que diga cuantos bultos son.

        INGRESO TEMPRANO Y SALIDA TARDE. Se puede, sujeto a disponibilidad y con costo adicional;
        que lo coordine con un día de anticipación. Las horas normales están en el tema «Horario
        de ingreso y salida».
        TXT;

    private const string TERMINOS = 'equipaje, maletas, guardar maletas, dejar las maletas, almacen, bultos, '
        . 'luggage, storage, keep my bags, early check in, ingreso temprano, late check out, salida tarde, '
        . 'llegar antes, salir tarde, quedarme mas';

    /**
     * Y la de horarios se queda **sólo con las horas**.
     *
     * Traía «equipaje, maletas, almacen, guardar maletas…» de cuando lo contaba todo, así que
     * buscar «maletas» seguía sacándola —medido con `app:agent:skill` el 18/09/2026, devolvía las
     * dos—. El contenido ya no está ahí: anunciarse para eso es mandar al modelo a la ficha que
     * sólo sabe remitir.
     */
    private const string TERMINOS_HORARIOS = 'horario, check in, check out, a que hora entro, a que hora salgo, '
        . 'entrada, salida, what time, arrival time, departure time, hora de entrada, hora de salida';

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
        $items = $this->em->getRepository(PmsGuiaItem::class);
        $filas = [];

        $horarios = $items->findOneBy(['codigo' => self::CODIGO_HORARIOS]);

        if (!$horarios instanceof PmsGuiaItem) {
            $io->error(sprintf('No existe la ficha con código «%s». No se toca nada.', self::CODIGO_HORARIOS));

            return Command::FAILURE;
        }

        $seccion = $this->em->getRepository(PmsGuiaSeccion::class)->findOneBy(['nombreInterno' => self::SECCION]);

        if (!$seccion instanceof PmsGuiaSeccion) {
            $io->error(sprintf('No existe la sección «%s». No se toca nada.', self::SECCION));

            return Command::FAILURE;
        }

        // ── 1. La ficha nueva ───────────────────────────────────────────────
        $nueva = $items->findOneBy(['codigo' => self::CODIGO_NUEVA]);

        if ($nueva instanceof PmsGuiaItem) {
            $filas[] = [self::NOMBRE_NUEVA, '<comment>ya existía</comment>'];
        } else {
            $filas[] = [self::NOMBRE_NUEVA, 'se crea en ' . self::SECCION];

            if (!$simular) {
                $nueva = (new PmsGuiaItem())
                    ->setNombreInterno(self::NOMBRE_NUEVA)
                    ->setCodigo(self::CODIGO_NUEVA)
                    ->setTipo(PmsGuiaItem::TIPO_TARJETA)
                    ->setVisibilidad(PmsGuiaVisibilidad::Cliente)
                    ->setCategoria(CategoriaConocimiento::General)
                    ->setIcono('fa-suitcase-rolling')
                    ->setTitulo([['language' => 'es', 'content' => 'Equipaje y horarios flexibles']])
                    ->setDescripcion([['language' => 'es', 'content' => self::EQUIPAJE]])
                    ->setAgenteContenido(self::EQUIPAJE_AGENTE)
                    ->setAgenteTerminos(self::TERMINOS);

                // `AutoTranslate` corre en `prePersist` y rellena los seis idiomas que faltan.
                $this->em->persist($nueva);
                $this->em->flush();

                $this->colgarDe($seccion, $nueva);
            }
        }

        // ── 2. La de horarios se queda con las horas y el enlace ────────────
        $cuerpo = $horarios->getDescripcion() ?? [];
        $indice = null;

        foreach ($cuerpo as $i => $fila) {
            if (($fila['language'] ?? null) === 'es') {
                $indice = $i;
                break;
            }
        }

        if ($indice === null) {
            $io->error('«Horario de ingreso y salida» no tiene descripción en español.');

            return Command::FAILURE;
        }

        if (str_contains((string) ($cuerpo[$indice]['content'] ?? ''), '{{ ficha: ' . self::CODIGO_NUEVA)) {
            $filas[] = ['Horario de ingreso y salida', '<comment>ya estaba partida</comment>'];
        } else {
            $filas[] = ['Horario de ingreso y salida', 'se queda con las horas + enlace a la nueva'];

            if (!$simular) {
                $cuerpo[$indice]['content'] = self::HORARIOS;
                $horarios->setDescripcion($cuerpo)->setAgenteContenido(self::HORARIOS_AGENTE);
            }
        }

        if ($horarios->getAgenteTerminos() === self::TERMINOS_HORARIOS) {
            $filas[] = ['Horario de ingreso y salida · términos', '<comment>ya eran sólo de horas</comment>'];
        } else {
            $filas[] = ['Horario de ingreso y salida · términos', 'se le quitan los del equipaje'];

            if (!$simular) {
                $horarios->setAgenteTerminos(self::TERMINOS_HORARIOS);
            }
        }

        $io->table(['Ficha', 'Qué pasa'], $filas);

        if ($simular) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success('Hecho. Los otros seis idiomas los rehace AutoTranslate al guardar.');

        return Command::SUCCESS;
    }

    /** Cuelga la ficha nueva de la sección, justo detrás de «Horario solicitudes». */
    private function colgarDe(PmsGuiaSeccion $seccion, PmsGuiaItem $item): void
    {
        /** @var list<PmsGuiaSeccionHasItem> $relaciones */
        $relaciones = $this->em->getRepository(PmsGuiaSeccionHasItem::class)->findBy(['seccion' => $seccion]);

        usort($relaciones, static fn (PmsGuiaSeccionHasItem $a, PmsGuiaSeccionHasItem $b): int => $a->getOrden() <=> $b->getOrden());

        $nueva = (new PmsGuiaSeccionHasItem())->setSeccion($seccion)->setItem($item);
        $this->em->persist($nueva);

        $posicion = count($relaciones);

        foreach ($relaciones as $i => $relacion) {
            if ((string) $relacion->getItem()?->getNombreInterno() === self::DETRAS_DE) {
                $posicion = $i + 1;
                break;
            }
        }

        $final = $relaciones;
        array_splice($final, $posicion, 0, [$nueva]);

        foreach ($final as $i => $relacion) {
            $relacion->setOrden($i);
        }

        $this->em->flush();
    }
}
