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
 * Lo que lee el ASISTENTE en las siete duchas: «chorro fuerte de agua» y las tildes de los pasos
 * (17/10/2026).
 *
 * El asistente no usa las traducciones: recibe siempre el español de `agente_contenido` y
 * `agente_pasos` y traduce él mismo. Así que las correcciones de `CorreccionesDeTraduccion` no le
 * llegan, y lo que confundió a Google le puede confundir a él:
 *
 * - **«un chorro fuerte»** salió «chorro de GAS» en los seis idiomas de la app —a un huésped le
 *   suena a fuga—. Este texto es sólo para el modelo, así que se aclara aquí sin tocar lo publicado:
 *   «chorro fuerte de agua».
 * - **Los pasos iban sin tildes** («Pidele», «balon», «averia», «en que baño esta»…): el repaso de
 *   tildes llegó al texto principal pero no a la escalera.
 *
 * Por palabra y frase concretas, con límites de palabra: «esta» sólo lleva tilde en «está vacío» y
 * en «en qué baño está», no en «esta casita». Idempotente.
 */
#[AsCommand(
    name: 'app:pms:guia:duchas-agente',
    description: 'En lo que lee el asistente de las duchas: «chorro fuerte de agua» y tildes en los pasos.',
    hidden: true,
)]
final class PmsGuiaDuchasAgenteCommand extends Command
{
    /** Patrón → reemplazo. Todos idempotentes: el reemplazo no vuelve a casar. */
    private const array CAMBIOS = [
        // Sólo si no lo dice ya: «chorro fuerte de agua» no debe volver a casar.
        '/\bchorro(\s+)fuerte\b(?!\s+de\s+agua)/u' => 'chorro$1fuerte de agua',
        '/\bcomun\b/u' => 'común',
        '/\bPidele\b/u' => 'Pídele',
        '/\bbalon\b/u' => 'balón',
        '/\besta vacio\b/u' => 'está vacío',
        '/\baveria\b/u' => 'avería',
        '/\bDiselo\b/u' => 'Díselo',
        '/\bSI prende\b/u' => 'SÍ prende',
        '/\btecnico\b/u' => 'técnico',
        '/\bpideselo\b/u' => 'pídeselo',
        '/\ben que baño esta\b/u' => 'en qué baño está',
        '/\bpreguntaselo\b/u' => 'pregúntaselo',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');
        $tocados = 0;

        for ($casa = 1; $casa <= 7; ++$casa) {
            $nombre = sprintf('Ducha (casa %d)', $casa);
            $item = $this->em->getRepository(PmsGuiaItem::class)->findOneBy(['nombreInterno' => $nombre]);

            if (!$item instanceof PmsGuiaItem) {
                $io->warning(sprintf('No encuentro «%s».', $nombre));
                continue;
            }

            $contenido = (string) $item->getAgenteContenido();
            $nuevoContenido = self::corregir($contenido);

            $pasos = $item->getAgentePasos();
            $nuevosPasos = array_map(self::corregir(...), $pasos);

            if ($nuevoContenido === $contenido && $nuevosPasos === $pasos) {
                $io->text(sprintf('· %s — ya está bien', $nombre));
                continue;
            }

            if ($nuevoContenido !== $contenido) {
                $item->setAgenteContenido($nuevoContenido);
            }
            if ($nuevosPasos !== $pasos) {
                $item->setAgentePasos($nuevosPasos);
            }

            ++$tocados;
            $io->text(sprintf(
                '+ %s —%s%s',
                $nombre,
                $nuevoContenido !== $contenido ? ' texto del asistente' : '',
                $nuevosPasos !== $pasos ? ' · pasos' : '',
            ));
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->success(sprintf('%d ducha(s) %s.', $tocados, $seco ? 'se corregirían' : 'corregidas'));

        return Command::SUCCESS;
    }

    public static function corregir(string $texto): string
    {
        foreach (self::CAMBIOS as $patron => $reemplazo) {
            $texto = (string) preg_replace($patron, $reemplazo, $texto);
        }

        return $texto;
    }
}
