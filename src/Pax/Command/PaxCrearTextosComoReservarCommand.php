<?php

declare(strict_types=1);

namespace App\Pax\Command;

use App\Pax\Entity\UiI18n;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Las cadenas del bloque «Cómo reservar» de la cotización (16/10/2026).
 *
 * El adelanto para reservar, cuándo se paga el resto, por dónde pagar y la política de
 * devoluciones: lo que en Oweb salía bajo cada total. Lo arma `ComoReservarResolver` y lo pinta
 * `PaxCotizacionGuiaView`. Se reutilizan las del cobro de alojamiento que ya dicen lo mismo
 * (`res_con_tarjeta`, `res_aviso_pago`, `res_a_nombre_de`…): dos claves con el mismo texto acaban
 * diciendo cosas distintas en el idioma número cinco.
 *
 * Comando y no migración: `UiI18n::$contenido` lleva `#[AutoTranslate]` y un INSERT en SQL dejaría
 * la cadena sólo en español. `{{ pct }}` es un marcador que protege `ProtectorDeMarcadores` y
 * rellena `maestroStore.t()`, ya con su «%».
 *
 * Idempotente por la clave natural.
 *
 *   php bin/console pax:textos:como-reservar --dry-run
 *   php bin/console pax:textos:como-reservar
 */
#[AsCommand(
    name: 'pax:textos:como-reservar',
    description: 'Crea las cadenas UiI18n del bloque «Cómo reservar» de la cotización. Idempotente.',
)]
final class PaxCrearTextosComoReservarCommand extends Command
{
    private const string SCOPE = 'cotizacion';

    /** @var array<string, string> */
    private const array TEXTOS = [
        'cot_como_reservar'          => 'Cómo reservar',
        'cot_reserva_con'            => 'Reserva con el {{ pct }}',
        'cot_reserva_total'          => 'Para reservar se paga el total',
        'cot_reserva_sin_adelanto'   => 'Sin adelanto: todo se paga el día del primer servicio',
        'cot_adelanto_para_reservar' => 'Adelanto para reservar',
        'cot_resto_primer_servicio'  => 'El resto, el día del primer servicio',
        'cot_por_donde_pagar'        => 'Por dónde pagar',
        'cot_desde_peru'             => 'Desde Perú',
        'cot_desde_extranjero'       => 'Desde el extranjero',
        // La tarjeta no tiene cuenta que copiar: se cobra con un enlace (Culqi) que se emite
        // cuando el cliente decide reservar.
        'cot_tarjeta_enlace'         => 'Te enviamos un enlace de pago seguro: pídelo cuando quieras reservar.',
        'cot_devoluciones'           => 'Cambios y devoluciones',
        // Sin cifras a propósito: los tramos viven en la política publicada, y copiarlos aquí
        // sería tener dos versiones que un día dirán cosas distintas.
        'cot_devoluciones_resumen'   => 'Si cancelas, lo que se devuelve depende de con cuánta anticipación lo hagas.',
        'cot_ver_politica'           => 'Ver la política completa',
    ];

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

        $repo = $this->em->getRepository(UiI18n::class);
        $creadas = [];
        $existentes = [];

        foreach (self::TEXTOS as $clave => $es) {
            if ($repo->find($clave) !== null) {
                $existentes[] = $clave;
                continue;
            }

            $creadas[] = [$clave, $es];

            if ($simular) {
                continue;
            }

            $texto = (new UiI18n())
                ->setId($clave)
                ->setScope(self::SCOPE)
                // El listener rellena los otros idiomas al persistir; aquí sólo va el origen.
                ->setContenido([['language' => 'es', 'content' => $es]]);

            $this->em->persist($texto);
        }

        if ($existentes !== []) {
            $io->writeln(sprintf('Ya existían (no se tocan): %s', implode(', ', $existentes)));
        }

        if ($creadas === []) {
            $io->success('No falta ninguna cadena.');

            return Command::SUCCESS;
        }

        $io->table(['clave', 'es'], $creadas);

        if ($simular) {
            $io->note('--dry-run: no se escribió nada.');

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d cadena(s) creada(s) y traducida(s).', count($creadas)));

        return Command::SUCCESS;
    }
}
