<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Message\Entity\MessageRule;
use App\Message\Entity\MessageTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Archiva plantillas: apaga todos sus canales.
 *
 * Archivar es eso y nada más ({@see MessageTemplate::estaEnCirculacion()}): deja de ofrecerse en el
 * chat y en el catálogo del agente, y sigue en el panel. **No se borra** porque los mensajes ya
 * enviados la referencian —`welcome_booking` tiene 46—, y borrarla rompería el historial.
 *
 * Sólo toca los interruptores `is_active`, nunca los cuerpos: `AutoTranslate` compara el hash del
 * texto y no rehace ninguna traducción.
 *
 * ⚠️ Se niega si una regla ACTIVA la usa: archivarla dejaría esa regla programando mensajes que no
 * pueden salir por ningún canal. Primero se repunta o se apaga la regla.
 */
#[AsCommand(
    name: 'msg:plantilla:archivar',
    description: 'Archiva plantillas apagando todos sus canales. No borra nada.',
)]
final class MessageArchivarPlantillaCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('codigos', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Códigos de las plantillas')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice qué haría');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simular = (bool) $input->getOption('dry-run');
        /** @var list<string> $codigos */
        $codigos = $input->getArgument('codigos');
        $repo = $this->em->getRepository(MessageTemplate::class);
        $filas = [];
        $cambios = 0;

        foreach ($codigos as $codigo) {
            $plantilla = $repo->findOneBy(['code' => $codigo]);

            if (!$plantilla instanceof MessageTemplate) {
                $io->error(sprintf('No existe «%s». No se toca nada.', $codigo));

                return Command::FAILURE;
            }

            $reglas = $this->reglasActivasDe($plantilla);

            if ($reglas !== []) {
                $io->error(sprintf(
                    '«%s» la usan reglas activas (%s): archivarla las dejaría sin por dónde enviar. '
                    . 'Repúntalas o apágalas primero. No se toca nada.',
                    $codigo,
                    implode(', ', $reglas)
                ));

                return Command::FAILURE;
            }

            if (!$plantilla->estaEnCirculacion()) {
                $filas[] = [$codigo, (string) $plantilla->getName(), '<comment>ya estaba archivada</comment>'];
                continue;
            }

            $filas[] = [$codigo, (string) $plantilla->getName(), 'se apagan sus canales'];
            ++$cambios;

            if (!$simular) {
                $plantilla
                    ->setBeds24Tmpl(['is_active' => false] + ($plantilla->getBeds24Tmpl() ?? []))
                    ->setWhatsappMetaTmpl(['is_active' => false] + ($plantilla->getWhatsappMetaTmpl() ?? []))
                    ->setEmailTmpl(['is_active' => false] + ($plantilla->getEmailTmpl() ?? []));
            }
        }

        $io->table(['Código', 'Nombre', 'Qué pasa'], $filas);

        if ($simular || $cambios === 0) {
            $simular ? $io->note('Simulación: no se ha escrito nada.') : $io->success('Nada que archivar.');

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d archivada(s). Ya no salen en el chat ni en el catálogo del agente.', $cambios));

        return Command::SUCCESS;
    }

    /** @return list<string> */
    private function reglasActivasDe(MessageTemplate $plantilla): array
    {
        $nombres = [];

        foreach ($this->em->getRepository(MessageRule::class)->findBy(['template' => $plantilla, 'isActive' => true]) as $regla) {
            $nombres[] = (string) $regla->getName();
        }

        return $nombres;
    }
}
