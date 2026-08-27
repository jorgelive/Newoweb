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
 * También pone el **prestador** por el mismo prefijo: `PR ` → PeruRail, `IR ` → IncaRail.
 *
 * ⚠️ **Sólo los trenes, y no por timidez.** Buscar la empresa dentro del nombre de la tarifa
 * produce falsos positivos que ensucian datos de dinero: «Hostal Mallqui» casa con tarifas
 * llamadas «Hostal por pax» —la palabra común es «hostal»— y «Hotel Dazzler - Miraflores» con
 * «Auto a Miraflores Noche», donde Miraflares es el DESTINO. Un prestador equivocado es peor que
 * ninguno: el vacío se ve, el error no. El prefijo `PR `/`IR ` es estructural y por eso se fía.
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
    description: 'Asigna prestador (PeruRail/IncaRail) y comprador (OpenPeru Tickets) a las tarifas de tren.'
)]
final class TravelAsignarCompradorTrenesCommand extends Command
{
    private const string COMPRADOR = 'OpenPeru Tickets';

    /** Prefijo → quién PRESTA. El prefijo es estructural, no una corazonada sobre el nombre. */
    private const array PRESTADOR_POR_PREFIJO = [
        'PR ' => 'PeruRail',
        'IR ' => 'IncaRail',
    ];

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
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin escribir.')
            ->addOption('crear-faltantes', null, InputOption::VALUE_NONE, 'Da de alta la organización que falte del catálogo.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simulacro = (bool) $input->getOption('dry-run');
        $crearFaltantes = (bool) $input->getOption('crear-faltantes');

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

        // Los prestadores, uno por prefijo. El que falte se avisa o se crea, según se pida.
        $prestadores = [];
        $faltan = [];
        foreach (self::PRESTADOR_POR_PREFIJO as $prefijo => $nombreEmpresa) {
            $empresa = $this->em->getRepository(TravelOrganizacion::class)
                ->findOneBy(['nombreComercial' => $nombreEmpresa]);

            if ($empresa instanceof TravelOrganizacion) {
                $prestadores[$prefijo] = $empresa;
                continue;
            }

            $faltan[$prefijo] = $nombreEmpresa;

            if ($crearFaltantes && !$simulacro) {
                $empresa = new TravelOrganizacion();
                $empresa->setNombreComercial($nombreEmpresa);
                $empresa->setRazonSocial($nombreEmpresa);
                $this->em->persist($empresa);
                $prestadores[$prefijo] = $empresa;
                $io->note(sprintf('Alta de «%s» en el catálogo.', $nombreEmpresa));
            }
        }

        if ($faltan !== [] && !$crearFaltantes) {
            $io->warning(sprintf(
                'Sin prestador por no estar en el catálogo: %s. Sus tarifas se quedan sin él '
                . '(el comprador sí se pone). Añade --crear-faltantes para darlas de alta.',
                implode(', ', $faltan)
            ));
        }

        $io->title(sprintf('%d tarifa(s) de tren%s', count($tarifas), $simulacro ? ' — SIMULACRO' : ''));

        $porComponente = [];
        $compradores = 0;
        $puestos = 0;

        foreach ($tarifas as $tarifa) {
            $componente = $tarifa->getComponente()?->getNombreInterno() ?? '¿?';
            $prefijo = str_starts_with($tarifa->getNombreInterno() ?? '', 'PR ') ? 'PR ' : 'IR ';
            $prestador = $prestadores[$prefijo] ?? null;

            $cambia = false;

            if ($tarifa->getComprador() !== $comprador) {
                ++$compradores;
                $cambia = true;
                if (!$simulacro) { $tarifa->setComprador($comprador); }
            }

            if ($prestador !== null && $tarifa->getPrestador() !== $prestador) {
                ++$puestos;
                $cambia = true;
                $porComponente[$componente][$prefijo] = ($porComponente[$componente][$prefijo] ?? 0) + 1;
                if (!$simulacro) { $tarifa->setPrestador($prestador); }
            }

            // Ver el docblock: sin esto se traducen los títulos por poner un id de empresa.
            if ($cambia && !$simulacro) { $tarifa->setEjecutarTraduccion(false); }
        }

        $filas = [];
        foreach ($porComponente as $nombre => $cuenta) {
            $filas[] = [$nombre, $cuenta['PR '] ?? 0, $cuenta['IR '] ?? 0];
        }
        $io->table(['Componente', 'PeruRail', 'IncaRail'], $filas);

        if ($simulacro) {
            $io->warning(sprintf('%d prestador(es) y %d comprador(es) se pondrían. Nada escrito.', $puestos, $compradores));

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d prestador(es) y %d comprador(es) asignados.', $puestos, $compradores));

        return Command::SUCCESS;
    }
}
