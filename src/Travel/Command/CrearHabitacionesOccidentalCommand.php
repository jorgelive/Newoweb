<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelOrganizacion;
use App\Travel\Entity\TravelOrganizacionServicio;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Los tipos de habitación del Occidental Caribe como servicios del prestador.
 *
 * ── Por qué la habitación y no «El resort» ──────────────────────────────────
 * `app:travel:crear-actividades-resort` dejó siete servicios de prestador, y seis nombran
 * un sitio concreto —lobby, buffet, piscinas…— que ilustra bien la actividad que cuelga de
 * él. El séptimo era «El resort», un cajón de sastre: no es nada que se contrate, así que
 * como *servicio contratado* de una línea no dice nada y como galería sólo puede repetir lo
 * que ya enseñan los otros seis.
 *
 * Lo que de verdad se contrata en un resort es **la habitación**, que es además el ejemplo
 * canónico de `prestadorServicio` en todo el modelo (`docs/Cotizaciones.md` §6.c: «el
 * servicio contratado, ej. el tipo de habitación»). Por eso «El resort» **se reconvierte**
 * en la habitación estándar en vez de borrarse: la tarifa que colgaba de él conserva su
 * enlace y pasa a decir algo cierto y útil.
 *
 * ⚠️ **Y eso mueve una afirmación, no sólo un nombre.** La tarifa «Día libre en resort
 * (resumen)» apuntaba a «El resort» y pasa a apuntar a «Habitación Doble». Es correcto
 * mientras se venda la estándar; si una propuesta reserva otro tipo, hay que cambiarle el
 * servicio en la línea. Lo hace el operador desde el inspector del componente, y desde el
 * 31/08/2026 el desplegable ya ofrece sólo los de este prestador.
 *
 * ── De dónde salen los datos ────────────────────────────────────────────────
 * De la ficha pública del hotel (barcelo.com, consultada el 31/08/2026): los siete tipos,
 * su capacidad y su equipamiento. **Sin precios**: los de la web son de un canal y una
 * fecha, envejecen solos y aquí el precio lo pone nuestra tarifa.
 *
 * ── Por comando y no por SQL ────────────────────────────────────────────────
 * `titulo` y `descripcion` llevan `#[AutoTranslate]`, enganchado a `prePersist`/`preUpdate`.
 * Un `INSERT` directo los dejaría sólo en español. Aquí la traducción se deja **encendida**
 * a propósito —al revés que en las cargas que sólo mueven ids—: esto es contenido publicado
 * y el cliente lo lee en su idioma, así que las siete lenguas son el trabajo, no un efecto
 * colateral. Por eso tarda.
 *
 * Idempotente por (organización, nombre): repetirlo no duplica ni pisa lo que ya exista, y
 * en la segunda pasada ya no encuentra «El resort» y sólo comprueba que estén los siete.
 */
#[AsCommand(
    name: 'app:travel:crear-habitaciones-occidental',
    description: 'Crea los tipos de habitación del Occidental Caribe y reconvierte «El resort» en la estándar.',
)]
final class CrearHabitacionesOccidentalCommand extends Command
{
    private const ORG_NOMBRE = 'Occidental Caribe - Punta Cana';

    /** El cajón de sastre que se reconvierte en la primera de la lista. */
    private const NOMBRE_A_RECONVERTIR = 'El resort';

    /**
     * Los siete tipos. El **primero** es el que hereda «El resort», así que va la estándar.
     *
     * @var list<array{nombre: string, titulo: string, descripcion: string}>
     */
    private const HABITACIONES = [
        [
            'nombre' => 'Habitación Doble',
            'titulo' => 'Habitación Doble',
            'descripcion' => 'Habitación estándar del resort, para hasta 4 personas (máximo 4 adultos '
                . 'y 3 niños). Incluye caja fuerte, set de café y té y tabla de planchar.',
        ],
        [
            'nombre' => 'Habitación Doble Vista Mar',
            'titulo' => 'Habitación Doble Vista Mar',
            'descripcion' => 'La habitación doble con vistas al mar, para hasta 4 personas (máximo 4 '
                . 'adultos y 2 niños). Incluye caja fuerte, minibar y tabla de planchar.',
        ],
        [
            'nombre' => 'Habitación Superior',
            'titulo' => 'Habitación Superior',
            'descripcion' => 'Categoría superior, para hasta 4 personas (máximo 4 adultos y 2 niños). '
                . 'Incluye minibar, set de café y té y tabla de planchar.',
        ],
        [
            'nombre' => 'Habitación Superior Vista Mar Frontal',
            'titulo' => 'Habitación Superior Vista Mar Frontal',
            'descripcion' => 'Categoría superior con vista frontal al mar, para hasta 4 personas '
                . '(máximo 4 adultos y 2 niños). Incluye set de café y té, plancha y tabla de planchar.',
        ],
        [
            'nombre' => 'Habitación Familiar',
            'titulo' => 'Habitación Familiar',
            'descripcion' => 'La más amplia en capacidad: hasta 6 personas (máximo 4 adultos y 4 niños). '
                . 'Incluye caja fuerte, minibar y wifi sin cargo.',
        ],
        [
            'nombre' => 'Suite Premium Level',
            'titulo' => 'Suite Premium Level',
            'descripcion' => 'Suite del servicio Premium Level, para hasta 4 personas (máximo 4 adultos '
                . 'y 2 niños). Con cama king size, aire acondicionado y televisión.',
        ],
        [
            'nombre' => 'Suite Presidencial Premium Level',
            'titulo' => 'Suite Presidencial Premium Level',
            'descripcion' => 'La categoría más alta del hotel, para 2 personas (máximo 2 adultos y 1 niño). '
                . 'Con cama king size, sala de estar y bañera de hidromasaje.',
        ],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin tocar nada.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');

        if ($simula) {
            $io->note('Simulación: no se escribe nada.');
        }

        $org = $this->em->getRepository(TravelOrganizacion::class)
            ->findOneBy(['nombreComercial' => self::ORG_NOMBRE]);

        if ($org === null) {
            $io->error(sprintf(
                'No existe la organización «%s». Córrelo después de `app:travel:crear-actividades-resort`.',
                self::ORG_NOMBRE
            ));

            return Command::FAILURE;
        }

        $repo = $this->em->getRepository(TravelOrganizacionServicio::class);

        // ── 1. «El resort» se reconvierte, no se borra ──────────────────────
        // Borrarlo dejaría la tarifa que colgaba de él sin servicio, y con ella la galería
        // de ese bloque de la guía. Renombrarlo conserva el enlace.
        $io->section('El cajón de sastre');
        $cajon = $repo->findOneBy(['organizacion' => $org, 'nombre' => self::NOMBRE_A_RECONVERTIR]);
        $primera = self::HABITACIONES[0];
        $yaHecho = false;

        if ($cajon !== null) {
            $io->text(sprintf(
                '  %s · «%s» → «%s»',
                $simula ? 'reconvertiría' : 'reconvertido ',
                self::NOMBRE_A_RECONVERTIR,
                $primera['nombre']
            ));

            if (!$simula) {
                $cajon->setNombre($primera['nombre'])
                    ->setTitulo([['language' => 'es', 'content' => $primera['titulo']]])
                    ->setDescripcion([['language' => 'es', 'content' => $primera['descripcion']]]);
            }

            $yaHecho = true;
        } else {
            $io->text(sprintf('  no está: «%s» ya se reconvirtió en una pasada anterior', self::NOMBRE_A_RECONVERTIR));
        }

        // ── 2. Las demás ────────────────────────────────────────────────────
        $io->section('Tipos de habitación');
        $creados = 0;

        foreach (self::HABITACIONES as $i => $hab) {
            if ($i === 0 && $yaHecho) {
                continue;   // la acaba de heredar el cajón
            }

            if ($repo->findOneBy(['organizacion' => $org, 'nombre' => $hab['nombre']]) !== null) {
                $io->text(sprintf('  ya existe · %s', $hab['nombre']));
                continue;
            }

            $io->text(sprintf('  %s · %s', $simula ? 'crearía' : 'creada ', $hab['nombre']));
            ++$creados;

            if (!$simula) {
                $servicio = (new TravelOrganizacionServicio())
                    ->setOrganizacion($org)
                    ->setNombre($hab['nombre'])
                    ->setTitulo([['language' => 'es', 'content' => $hab['titulo']]])
                    ->setDescripcion([['language' => 'es', 'content' => $hab['descripcion']]]);
                $this->em->persist($servicio);
            }
        }

        if (!$simula) {
            $io->text('');
            $io->text('Traduciendo a los siete idiomas (esto tarda)…');
            $this->em->flush();
        }

        $io->success(sprintf(
            '%s%d habitación(es) nueva(s)%s. Faltan las FOTOS: sin ellas el segmento genérico sale desnudo.',
            $simula ? 'Simulación: ' : '',
            $creados,
            $yaHecho ? sprintf(' y «%s» reconvertido', self::NOMBRE_A_RECONVERTIR) : ''
        ));

        return Command::SUCCESS;
    }
}
