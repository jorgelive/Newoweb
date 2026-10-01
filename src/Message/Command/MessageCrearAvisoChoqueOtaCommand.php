<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Message\Entity\MessageTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La plantilla con la que se avisa a la guardia de reservas de que un canal movió una reserva
 * encima de la noche extra de otra estancia.
 *
 * ── Por qué hace falta una más ──────────────────────────────────────────────
 * La noche extra de una entrada temprana o una salida tardía la bloqueamos nosotros en Beds24,
 * pero una OTA puede cambiar fechas o habitación sin preguntar, y ese cambio no se puede rechazar:
 * el canal ya lo hizo. Lo único que queda es avisar a quien puede reubicar a uno de los dos. Fase 5
 * de docs/PlanHorarioExtraSinEventos.md; el texto lo aprobó Jorge el 01/10/2026.
 *
 * Ninguna de las tres internas que hay sirve: `aviso_escalado_interno` habla de un huésped que
 * espera, `aviso_cobro_interno` de un pago y `aviso_tecnico_interno` de un sistema que no responde.
 * Usar una para esto sería mentir en el texto que Meta aprobó.
 *
 * Va a `ROLE_CUSTOMER_SUPPORT` y no a la guardia técnica: no es el sistema contradiciéndose, son
 * dos huéspedes para la misma noche, y eso lo arregla quien atiende las reservas.
 *
 * ⚠️ **Los parámetros van en UNA línea**, que es lo que exige Meta. Dentro de la ventana de 24 h
 * sale el texto libre que redacte quien avisa; esto es sólo el respaldo.
 *
 *   php bin/console msg:crear:aviso-choque-ota --dry-run
 *   php bin/console msg:crear:aviso-choque-ota
 *   php bin/console msg:meta:push aviso_choque_ota_interno --todos
 */
#[AsCommand(
    name: 'msg:crear:aviso-choque-ota',
    description: 'Crea la plantilla «aviso_choque_ota_interno»: un canal movió una reserva sobre una noche extra. Idempotente.',
    hidden: true,
)]
final class MessageCrearAvisoChoqueOtaCommand extends Command
{
    public const string CODIGO = 'aviso_choque_ota_interno';

    private const string CUERPO = '⚠️ *{{casita}}*: la noche del {{fecha}} estaba reservada para el horario extra de {{huesped}}, '
        . 'pero {{canal}} acaba de mover ahí otra reserva ({{otra}}). Hay que reubicar a una de las dos.';

    /** La misma cabecera que sus hermanas de escalado y cobro, a mano en los siete idiomas. */
    private const array CABECERA = [
        ['format' => 'TEXT', 'language' => 'es', 'content' => 'Aviso interno'],
        ['format' => 'TEXT', 'language' => 'en', 'content' => 'Internal alert'],
        ['format' => 'TEXT', 'language' => 'pt', 'content' => 'Aviso interno'],
        ['format' => 'TEXT', 'language' => 'fr', 'content' => 'Avis interne'],
        ['format' => 'TEXT', 'language' => 'it', 'content' => 'Avviso interno'],
        ['format' => 'TEXT', 'language' => 'de', 'content' => 'Interne Mitteilung'],
        ['format' => 'TEXT', 'language' => 'nl', 'content' => 'Interne mededeling'],
    ];

    /**
     * El pie de todas las internas: «Sistema OpenPeru», nombre propio y sin traducir. Ver
     * `MessageCrearAvisoTecnicoCommand::PIE` (lo que pasó con «PMS»).
     */
    private const array PIE = [
        ['language' => 'es', 'content' => 'Aviso automático · Sistema OpenPeru'],
        ['language' => 'en', 'content' => 'Automatic alert · Sistema OpenPeru'],
        ['language' => 'pt', 'content' => 'Alerta automático · Sistema OpenPeru'],
        ['language' => 'fr', 'content' => 'Alerte automatique · Sistema OpenPeru'],
        ['language' => 'it', 'content' => 'Avviso automatico · Sistema OpenPeru'],
        ['language' => 'de', 'content' => 'Automatische Meldung · Sistema OpenPeru'],
        ['language' => 'nl', 'content' => 'Automatische melding · Sistema OpenPeru'],
    ];

    /** Lo que Meta revisa en cada hueco: un caso real, no «Dato_Ejemplo». */
    private const array EJEMPLO = [
        'casita' => 'Casita 1',
        'fecha' => '01/02/2027',
        'huesped' => 'Anna Müller',
        'canal' => 'Booking.com',
        'otra' => '5893021744',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice qué crearía');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => self::CODIGO]) !== null) {
            // Idempotente y sin pisar: puede haberla afinado alguien desde el panel.
            $io->success(sprintf('«%s» ya existe: no se toca.', self::CODIGO));

            return Command::SUCCESS;
        }

        $io->section('Se creará');
        $io->writeln(self::CUERPO);

        if ((bool) $input->getOption('dry-run')) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        $cuerpo = [['language' => 'es', 'content' => self::CUERPO]];
        $ejemplos = [];
        foreach (self::PIE as $fila) {
            $ejemplos[$fila['language']] = self::EJEMPLO;
        }

        $plantilla = (new MessageTemplate())
            ->setCode(self::CODIGO)
            ->setName('Aviso interno: un canal movió una reserva sobre una noche extra')
            // `staff`: no cuelga de ninguna reserva, como sus hermanas.
            ->setContextType('staff')
            ->setAutoenvioHabilitada(false)
            ->setWhatsappMetaTmpl([
                'is_active' => true,
                'category' => 'UTILITY',
                // Con sufijo desde el primer día: ver §18 de docs/Mensajeria.md.
                'meta_template_name' => self::CODIGO . '_v1',
                'is_official_meta' => false,
                'header' => self::CABECERA,
                'footer' => self::PIE,
                'body' => $cuerpo,
                // Sin botones: la acción es reubicar en el calendario, y cada botón es una cosa
                // más que Meta puede rechazar.
                'buttons_map' => [],
                'ejemplos' => $ejemplos,
            ])
            ->setWhatsappLinkTmpl(['is_active' => false, 'body' => []])
            ->setBeds24Tmpl(['is_active' => false, 'body' => []])
            ->setEmailTmpl(['is_active' => false, 'subject' => [], 'body' => []]);

        $this->em->persist($plantilla);
        // `AutoTranslate` corre en `prePersist` y rellena los seis idiomas del cuerpo.
        $this->em->flush();

        $io->success(sprintf('«%s» creada. Súbela con: msg:meta:push %s --todos', self::CODIGO, self::CODIGO));

        return Command::SUCCESS;
    }
}
