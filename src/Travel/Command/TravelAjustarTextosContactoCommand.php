<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelTarifa;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Afina los textos de los segmentos de contacto, ya publicados.
 *
 * ── Qué cambia y por qué ────────────────────────────────────────────────────
 *
 * **La estación.** El encuentro es en el área designada del EXTERIOR, y hay que decirlo: al andén
 * no se entra, y mucha gente espera dentro convencida de que ahí la recogen. Es una frase que
 * ahorra una llamada por grupo y una espera con maletas.
 *
 * **El hotel.** «Recepción en tu hotel» se lee raro —parece que le recibimos la recepción— y
 * además dice el sitio dos veces. «Encuentro con nuestro personal en tu hotel» dice lo que pasa.
 *
 * ── ⚠️ Se REGENERAN los siete idiomas ───────────────────────────────────────
 * `AutoTranslationService` sólo pisa una traducción existente si `sobreescribirTraduccion` está
 * activo. Sin activarlo, el español diría «en el exterior de la estación» y los otros seis
 * seguirían mandando al cliente al andén — y no lo vería nadie, porque quien revisa lee español.
 * Por eso se activa antes de tocar el texto y se **comprueba después** que cambiaron.
 */
#[AsCommand(
    name: 'app:travel:ajustar-textos-contacto',
    description: 'Afina el título y el texto de los segmentos de contacto, regenerando sus traducciones.',
)]
final class TravelAjustarTextosContactoCommand extends Command
{
    /**
     * Segmento → título y texto nuevos.
     *
     * @var array<string, array{titulo: string, contenido: string}>
     */
    private const array TEXTOS = [
        'Contacto en la estación de Machu Picchu' => [
            'titulo' => 'Recepción a la salida de la estación de Machu Picchu',
            'contenido' => 'Nuestro personal te estará esperando con un cartel a tu nombre en el área de recepción, '
                . 'a la salida de la estación. El andén es de acceso restringido y no se permite el ingreso de '
                . 'acompañantes, así que el encuentro es siempre en el exterior: al bajar del tren, sigue hacia la '
                . 'salida y búscanos allí.',
        ],
        'Contacto en el hotel' => [
            'titulo' => 'Encuentro con nuestro personal en tu hotel',
            'contenido' => 'Nuestro personal pasará por la recepción de tu hotel a la hora indicada para '
                . 'acompañarte al inicio de la visita. Te recomendamos estar listo unos minutos antes.',
        ],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'No escribe nada: sólo enseña lo que haría.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');
        $repo = $this->em->getRepository(TravelSegmento::class);
        $tocados = [];

        foreach (self::TEXTOS as $nombre => $texto) {
            $segmento = $repo->findOneBy(['nombreInterno' => $nombre]);

            if ($segmento === null) {
                $io->writeln(sprintf('  <error>✗</error> no encuentro «%s»', $nombre));
                continue;
            }

            $io->section($nombre);

            // ⚠️ Si el español ya es el bueno, no se toca. Sin esto, cada pasada vuelve a pedir
            // las siete traducciones —la automática no es determinista, así que salen distintas—
            // y el comando dejaría de ser repetible: correrlo por si acaso cambiaría el texto que
            // ve el cliente sin que nadie lo haya pedido.
            if ($this->es($segmento->getTitulo()) === $texto['titulo']
                && $this->es($segmento->getContenido()) === $texto['contenido']) {
                $io->writeln('  <comment>·</comment> ya está puesto — sin tocar');
                continue;
            }

            $io->writeln(sprintf('  antes: %s', $this->es($segmento->getTitulo())));
            $io->writeln(sprintf('  ahora: <info>%s</info>', $texto['titulo']));

            // El interruptor ANTES de tocar el campo: el listener lo lee en el mismo preUpdate.
            $segmento->setSobreescribirTraduccion(true);
            $segmento->setTitulo([['language' => 'es', 'content' => $texto['titulo']]]);
            $segmento->setContenido([['language' => 'es', 'content' => $texto['contenido']]]);

            // ⚠️ Se guarda lo que HABÍA para compararlo después.
            //
            // El primer intento comprobaba que apareciera una palabra testigo en cada idioma, y
            // avisaba en falso: «estaci» no está en «station» ni en «Bahnhof». Un chequeo que
            // grita cuando todo está bien se deja de mirar, y entonces no sirve el día que sí
            // pase algo. Comparar contra lo anterior es agnóstico del idioma y no falla.
            $tocados[$nombre] = [$segmento, $this->idiomasDe($segmento->getTitulo())];
        }

        if ($seco) {
            $io->newLine();
            $io->success('Nada escrito (--dry-run). Se regenerarían los 7 idiomas de cada uno.');

            return Command::SUCCESS;
        }

        $this->renombrarTarifa($io);
        $this->em->flush();

        // ── La comprobación que justifica todo lo anterior ──────────────────
        foreach ($tocados as $nombre => [$segmento, $antes]) {
            $this->em->refresh($segmento);
            $idiomas = $this->idiomasDe($segmento->getTitulo());

            if (count($idiomas) < 2) {
                $io->warning(sprintf('«%s»: el título quedó SÓLO en español. La traducción no corrió.', $nombre));
                continue;
            }

            // Un idioma que sigue diciendo exactamente lo mismo que antes es un idioma que no se
            // regeneró: el español cambió y él se quedó mandando al cliente al sitio viejo.
            $mudos = array_keys(array_filter(
                $idiomas,
                static fn (string $t, string $lang): bool => $lang !== 'es' && ($antes[$lang] ?? null) === $t,
                ARRAY_FILTER_USE_BOTH
            ));

            $io->writeln(sprintf(
                '  <info>%s</info> %d idiomas%s',
                $nombre,
                count($idiomas),
                $mudos === [] ? '' : sprintf(' — <comment>revisa %s</comment>', implode(', ', $mudos))
            ));
        }

        $io->newLine();
        $io->success('Textos ajustados.');

        return Command::SUCCESS;
    }

    /**
     * La tarifa del contacto se llamaba «Base», y en el cuadro de tráfico eso no dice nada.
     *
     * `descripcionServicio` de La Biblia sale del **nombre interno de la tarifa** —debajo del
     * nombre del componente—, así que la fila del contacto se leía «Contacto con el cliente /
     * Base» y no se distinguía de nada. En los demás componentes ese campo dice qué es
     * exactamente lo que se compra («Van (Incluido en paquete externo)»).
     *
     * ⚠️ Sólo se renombra si sigue diciendo «Base»: si alguien ya la afinó a mano, manda lo suyo.
     */
    private function renombrarTarifa(SymfonyStyle $io): void
    {
        $componente = $this->em->getRepository(TravelComponente::class)
            ->findOneBy(['nombreInterno' => 'Contacto con el cliente']);

        if ($componente === null) {
            return;
        }

        foreach ($this->em->getRepository(TravelTarifa::class)->findBy(['componente' => $componente]) as $tarifa) {
            if ($tarifa->getNombreInterno() !== 'Base') {
                continue;
            }

            $tarifa->setNombreInterno('Recepción y contacto con el pasajero');
            $io->writeln('  <info>·</info> tarifa «Base» → «Recepción y contacto con el pasajero»');
        }
    }

    /** @param list<array{language?: string, content?: string|null}> $campo */
    private function es(array $campo): string
    {
        foreach ($campo as $fila) {
            if (($fila['language'] ?? '') === 'es') {
                return (string) ($fila['content'] ?? '');
            }
        }

        return '(vacío)';
    }

    /**
     * @param list<array{language?: string, content?: string|null}> $campo
     *
     * @return array<string, string>
     */
    private function idiomasDe(array $campo): array
    {
        $mapa = [];

        foreach ($campo as $fila) {
            $mapa[(string) ($fila['language'] ?? '?')] = (string) ($fila['content'] ?? '');
        }

        return $mapa;
    }
}
