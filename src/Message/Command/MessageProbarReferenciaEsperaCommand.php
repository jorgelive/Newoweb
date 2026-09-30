<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Command\EntradaDeConsola;
use App\Message\Service\Queue\MensajeEnEsperaDeVentana;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Enseña cómo quedaría el aviso de un mensaje en espera en cada idioma, SIN enviar nada.
 *
 * El «sobre qué es» lo escribe el operador en español y se traduce al idioma del cliente antes de
 * meterlo en la plantilla. La traducción automática de una frase suelta puede salir rara, y la
 * única forma de verla era mandándosela a un cliente de verdad.
 *
 *   php bin/console msg:espera:referencia "los tours que pediste"
 *   php bin/console msg:espera:referencia "tu reserva del 3 al 8 de octubre" en fr
 */
#[AsCommand(
    name: 'msg:espera:referencia',
    description: 'Traduce el «sobre qué es» de un mensaje en espera a cada idioma, sin enviar nada.',
)]
final class MessageProbarReferenciaEsperaCommand extends Command
{
    private const array IDIOMAS = ['en', 'pt', 'fr', 'it', 'de', 'nl'];

    public function __construct(
        private readonly MensajeEnEsperaDeVentana $espera,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('referencia', InputArgument::REQUIRED, 'Lo que escribiría el operador, en español')
            ->addArgument('idiomas', InputArgument::IS_ARRAY, 'Códigos de idioma; por defecto, los seis que no son español');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $referencia = EntradaDeConsola::textoOpcional($input->getArgument('referencia'), 'referencia') ?? '';
        /** @var list<string> $idiomas */
        $idiomas = $input->getArgument('idiomas') ?: self::IDIOMAS;

        $filas = [['es', $referencia]];

        foreach ($idiomas as $idioma) {
            $filas[] = [$idioma, $this->espera->referenciaEnIdioma($referencia, $idioma)];
        }

        $io->table(['Idioma', '«…la respuesta sobre {{referencia}}»'], $filas);

        return Command::SUCCESS;
    }
}
