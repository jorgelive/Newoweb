<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsGuiaItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * «Horario solicitudes»: un solo número, y por marcador.
 *
 * ── Qué tenía ───────────────────────────────────────────────────────────────
 * Una lista con `+51 961 281 953` y `+51 958 191 965` escritos a mano. Dos problemas en la misma
 * línea:
 *
 * 1. **Son copias.** El primero es `PmsEstablecimiento::$telefonoEmergencia` y el segundo el móvil
 *    personal de Susan, que además es el número del Yape del catálogo de cobro. El día que
 *    cualquiera cambie, la guía sigue dando el viejo sin que nada falle.
 * 2. **Son los números equivocados para esta ficha.** Aquí se pide papel higiénico y jabón: eso va
 *    al canal que atiende el sistema —el WhatsApp público—, no al de urgencias ni al móvil de una
 *    persona. El de urgencias sigue donde tiene que estar: en el botón «Necesito ayuda» de las
 *    llaves y en lo que devuelve `ConsultarCodigosSkill` cuando no hay código que entregar.
 *
 * Pasa a `{{ whatsapp_numero }}`, que sale del establecimiento — la misma clave que usan las
 * plantillas desde el 14/09/2026.
 *
 * ── Por qué comando y no SQL ────────────────────────────────────────────────
 * `descripcion` lleva `#[AutoTranslate]`: un `UPDATE` se salta el listener y deja las otras seis
 * traducciones con los números viejos dentro. Se toca **sólo el español** y el listener rehace el
 * resto, porque aquí la frase cambia entera y no sólo el marcador.
 *
 * Nace `hidden` porque es de una vez: ver la regla de archivado en `CLAUDE.md`.
 */
#[AsCommand(
    name: 'app:pms:guia:contacto-solicitudes',
    description: 'Deja «Horario solicitudes» con el WhatsApp público por marcador. Idempotente.',
    hidden: true,
)]
final class PmsGuiaContactoSolicitudesCommand extends Command
{
    private const string FICHA = 'Horario solicitudes (general)';

    /** Lo que hay que reemplazar: desde el «📞» hasta el final de la lista de números. */
    private const string VIEJO = '<p>📞 No dudes en llamarnos o escribirnos por WhatsApp:</p>';

    private const string NUEVO = '<p>📞 Escríbenos por WhatsApp al {{ whatsapp_numero }} y te lo resolvemos.</p>';

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

        $item = $this->em->getRepository(PmsGuiaItem::class)->findOneBy(['nombreInterno' => self::FICHA]);

        if (!$item instanceof PmsGuiaItem) {
            $io->error(sprintf('No existe la ficha «%s».', self::FICHA));

            return Command::FAILURE;
        }

        $contenido = $item->getDescripcion() ?? [];
        $indice = null;

        foreach ($contenido as $i => $fila) {
            if (($fila['language'] ?? null) === 'es') {
                $indice = $i;
                break;
            }
        }

        if ($indice === null) {
            $io->error('La ficha no tiene descripción en español.');

            return Command::FAILURE;
        }

        $texto = (string) ($contenido[$indice]['content'] ?? '');

        if (!str_contains($texto, self::VIEJO)) {
            $io->success('Ya estaba: la ficha no lleva los números escritos.');

            return Command::SUCCESS;
        }

        // Se va el párrafo de la invitación Y la lista de números que lo seguía. La lista es
        // `<ul>…</ul>` y es lo único que hay entre ese párrafo y el final del cuerpo.
        $nuevo = preg_replace(
            '#' . preg_quote(self::VIEJO, '#') . '\s*<ul>.*?</ul>#s',
            self::NUEVO,
            $texto,
        ) ?? $texto;

        if ($nuevo === $texto) {
            $io->error('Encontré el párrafo pero no la lista de números: no toco nada a ciegas.');

            return Command::FAILURE;
        }

        $io->section('Español');
        $io->writeln('<fg=red>- ' . strip_tags(self::VIEJO) . ' + la lista de dos números</>');
        $io->writeln('<fg=green>+ ' . strip_tags(self::NUEVO) . '</>');

        if ($simular) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        $contenido[$indice]['content'] = $nuevo;
        $item->setDescripcion($contenido);
        $this->em->flush();

        $io->success('Hecho. Los otros seis idiomas los rehace AutoTranslate al guardar.');

        return Command::SUCCESS;
    }
}
