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
 * Las cadenas que faltaban para el resumen de pago de la ficha del huésped.
 *
 * ── Por qué un comando y no una migración ───────────────────────────────────
 * `UiI18n::$contenido` lleva `#[AutoTranslate(sourceLanguage: 'es')]`, y ese listener se
 * engancha a `prePersist`/`preUpdate`. Un `INSERT` en SQL **se lo salta**, así que las
 * cadenas nacerían sólo en español y el huésped que lee en inglés vería castellano sin que
 * fallara nada. Es la regla de CLAUDE.md: lo que dispara listeners entra por el ORM.
 *
 * **Idempotente por la clave natural** (el `id` de `UiI18n`): las que ya existan no se tocan,
 * ni siquiera para reescribir su español — puede haberlas afinado alguien desde el panel, y
 * pisarlas sería descartar ese trabajo sin avisar.
 *
 *   php bin/console pax:textos:cobro --dry-run   # dice qué crearía
 *   php bin/console pax:textos:cobro             # las crea y las traduce
 */
#[AsCommand(
    name: 'pax:textos:cobro',
    description: 'Crea las cadenas UiI18n del resumen de pago que faltan. Idempotente.',
)]
final class PaxCrearTextosCobroCommand extends Command
{
    private const string SCOPE = 'reserva';

    /**
     * Sólo lo que NO existe ya. Se reutiliza a propósito `res_recargo_nota` («incluye X% de
     * comisión») en vez de inventar un gemelo: dos claves que dicen lo mismo acaban diciendo
     * cosas distintas en el idioma número cinco.
     *
     * @var array<string, string>
     */
    private const array TEXTOS = [
        // Los dos medios que `FinMedioCobroTipo` separa y `PmsMedioPago` juntaba en
        // `plin_yape`. El catálogo de cobro los distingue, así que necesitan su cadena.
        'res_medio_yape' => 'Yape',
        'res_medio_plin' => 'Plin',

        // Qué se le está pidiendo. Son dos y no uno porque la diferencia importa: un adelanto
        // asegura la reserva y el total la cierra, y el huésped no reacciona igual.
        'res_pide_adelanto' => 'Adelanto para asegurar tu reserva',
        'res_pide_total' => 'Total a pagar',

        // El segundo peldaño de la tarjeta. `res_ver_mas`/`res_ver_menos` ya existen y ahora
        // abren el RESUMEN; éstas son las del detalle, que vive dentro.
        'res_ver_detalle' => 'Ver detalle',
        'res_ocultar_detalle' => 'Ocultar detalle',

        // La nota del asterisco, fuera del cuadro del prepago.
        'res_con_tarjeta' => 'Con tarjeta de crédito',
        'res_con_estos_medios' => 'Con estos medios de pago',

        // ⚠️ Faltaba y nadie lo había notado: la vista lleva meses cayendo al respaldo en
        // castellano, así que un huésped con la ficha en inglés leía «Pagar ahora» en medio
        // de todo lo demás traducido. El respaldo del `||` no falla — y por eso no se ve.
        'res_pagar_online' => 'Pagar ahora',

        // La X de la ficha de cuentas. En táctil no hay «quitar el ratón de encima»: el
        // cierre tiene que ser un botón, y un botón necesita nombre para el lector de
        // pantalla aunque sólo se vea un aspa.
        'res_cerrar' => 'Cerrar',

        // Cómo avisarnos de que ya pagó. Son DOS y no una porque el chat de Booking no
        // transporta imágenes y el resto sí: la variante la elige `PmsReservaPaxProvider`
        // según el canal de la reserva, no el texto. Estuvo metido dentro de las notas de Yape
        // y Plin del catálogo de cobro, y así se le hablaba del chat de Booking a huéspedes de
        // reservas directas. Ver `PmsChannel::CHAT_SIN_IMAGENES`.
        'res_aviso_pago' => 'Avísanos por este chat cuando lo hayas hecho y el equipo lo confirma. Si quieres, mándanos la captura.',
        'res_aviso_pago_sin_imagenes' => 'Avísanos por este chat cuando lo hayas hecho y el equipo lo confirma. Si quieres mandarnos la captura, hazlo por WhatsApp: este chat no admite imágenes.',
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
                // El listener de traducción rellena los otros idiomas al persistir. Aquí sólo
                // se pone el origen, que es lo que declara `sourceLanguage: 'es'`.
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

        $io->table(['Clave', 'Español'], $creadas);

        if ($simular) {
            $io->note(sprintf('Simulación: se crearían %d. Sin --dry-run se escriben y se traducen.', count($creadas)));

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d cadenas creadas y encoladas para traducir.', count($creadas)));

        return Command::SUCCESS;
    }
}
