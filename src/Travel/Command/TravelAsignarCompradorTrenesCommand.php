<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelOrganizacion;
use App\Travel\Entity\TravelTarifa;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pone «OpenPeru Tickets» como comprador de las tarifas de tren.
 *
 * Los billetes de PeruRail e IncaRail no se le compran al operador: los saca OpenPeru Tickets,
 * que es una división nuestra modelada como organización. Y el comprador es quien decide a
 * nombre de quién sale la Orden de Servicio, así que dejarlo vacío haría que el encargo saliera
 * a nombre del tren.
 *
 * **Identifica por el prefijo del nombre**, que es lo único que hay hoy: `PR ` son 57 tarifas en
 * 6 componentes y `IR ` son 12 en 4. No por el prestador, porque todavía está sin poblar — de
 * hecho este comando existe para adelantar la mitad útil mientras eso se llena a mano.
 *
 * ⚠️ **No toca el prestador.** Podría deducirse del mismo prefijo, pero eso es una afirmación
 * sobre de quién es el precio y merece decidirse aparte; aquí sólo se dice a quién se le encarga.
 *
 * ⚠️ **Apaga la traducción antes de guardar.** `TravelTarifa` lleva `#[AutoTranslate]` en el
 * título y `ejecutarTraduccion` arranca en `true`, así que un `flush` normal dispararía el
 * traductor de las 69 tarifas para rellenar idiomas que nadie pidió. Poner un id de empresa no
 * cambia ningún texto.
 *
 * Idempotente: sólo toca las que no tienen ya ese comprador.
 */
#[AsCommand(
    name: 'app:travel:comprador-trenes',
    description: 'Asigna OpenPeru Tickets como comprador de las tarifas PR (PeruRail) e IR (IncaRail).'
)]
final class TravelAsignarCompradorTrenesCommand extends Command
{
    private const string COMPRADOR = 'OpenPeru Tickets';

    /**
     * El prefijo es la marca: `PR Expedition Adulto`, `IR Ejecutivo`.
     *
     * Dos `LIKE` y no una expresión regular: DQL no trae `REGEXP` sin registrar una función a
     * medida, y para un prefijo de tres caracteres eso es montar andamios para colgar un cuadro.
     */
    private const array PREFIJOS = ['PR %', 'IR %'];

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
        $simulacro = (bool) $input->getOption('dry-run');

        $comprador = $this->em->getRepository(TravelOrganizacion::class)
            ->findOneBy(['nombreComercial' => self::COMPRADOR]);

        if (!$comprador instanceof TravelOrganizacion) {
            $io->error(sprintf('No existe la organización «%s». Créala antes.', self::COMPRADOR));

            return Command::FAILURE;
        }

        /** @var list<TravelTarifa> $tarifas */
        $tarifas = $this->em->createQuery(
            'SELECT t, c FROM ' . TravelTarifa::class . ' t JOIN t.componente c '
            . 'WHERE t.nombreInterno LIKE :pr OR t.nombreInterno LIKE :ir '
            . 'ORDER BY c.nombre, t.nombreInterno'
        )->setParameter('pr', self::PREFIJOS[0])->setParameter('ir', self::PREFIJOS[1])->getResult();

        if ($tarifas === []) {
            $io->warning('Ninguna tarifa empieza por «PR » ni «IR ».');

            return Command::SUCCESS;
        }

        $io->title(sprintf('%d tarifa(s) de tren%s', count($tarifas), $simulacro ? ' — SIMULACRO' : ''));

        $porComponente = [];
        $tocadas = 0;
        $yaEstaban = 0;

        foreach ($tarifas as $tarifa) {
            $componente = $tarifa->getComponente()?->getNombre() ?? '¿?';

            if ($tarifa->getComprador() === $comprador) {
                ++$yaEstaban;
                $porComponente[$componente]['ya'] = ($porComponente[$componente]['ya'] ?? 0) + 1;
                continue;
            }

            ++$tocadas;
            $porComponente[$componente]['nuevo'] = ($porComponente[$componente]['nuevo'] ?? 0) + 1;

            if ($simulacro) {
                continue;
            }

            // Ver el docblock: sin esto se traducen 69 títulos por poner un id.
            $tarifa->setEjecutarTraduccion(false);
            $tarifa->setComprador($comprador);
        }

        $filas = [];
        foreach ($porComponente as $nombre => $cuenta) {
            $filas[] = [$nombre, $cuenta['nuevo'] ?? 0, $cuenta['ya'] ?? 0];
        }
        $io->table(['Componente', 'Se asignan', 'Ya lo tenían'], $filas);

        if ($simulacro) {
            $io->warning(sprintf('%d se asignarían, %d ya lo tenían. Nada escrito.', $tocadas, $yaEstaban));

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d tarifa(s) con comprador «%s». %d ya lo tenían.', $tocadas, self::COMPRADOR, $yaEstaban));

        return Command::SUCCESS;
    }
}
