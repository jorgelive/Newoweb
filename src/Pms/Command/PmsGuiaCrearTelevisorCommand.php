<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsGuiaItem;
use App\Pms\Entity\PmsGuiaSeccion;
use App\Pms\Entity\PmsGuiaSeccionHasItem;
use App\Pms\Enum\PmsGuiaVisibilidad;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Crea el ítem «Televisor» de cada casita, que no existía en la guía.
 *
 * ### Por qué un comando y no una migración
 *
 * Un ítem de guía **se publica en la app del huésped en siete idiomas**, y las traducciones las
 * genera {@see \App\EventListener\AutoTranslationEventListener} en `prePersist`. Una migración
 * escribe SQL directo y se salta el listener: crearía fichas que en la app aparecen sólo en
 * español. Por el ORM se traducen solas.
 *
 * Es **idempotente**: si el ítem ya existe por `nombreInterno`, no lo toca. Se puede relanzar sin
 * miedo, y con `--dry-run` sólo cuenta lo que haría.
 *
 * ### Los tres aparatos, que no son el mismo
 *
 * ```
 *   casas 1-5   Roku, con su propio mando aparte del de la tele
 *   casa 6      Mi TV Stick de Xiaomi, también con mando propio
 *   casa 7      Smart TV Android: va todo integrado, un solo mando
 * ```
 *
 * La diferencia importa: en las dos primeras, la queja habitual —«no aparece nada»— se resuelve
 * cambiando la entrada de la tele, y eso no aplica en la 7.
 *
 * ### La escalera
 *
 * Peldaño 0, cómo se usa. Paso 1, **que no hay canales locales**: es una negativa y no se dice a
 * priori. Ver `docs/Mensajeria.md` §22.
 */
#[AsCommand(
    name: 'app:pms:guia:crear-televisor',
    description: 'Crea el ítem Televisor de cada casita (Roku, Xiaomi o Android TV según la casa).'
)]
final class PmsGuiaCrearTelevisorCommand extends Command
{
    /** Qué aparato hay en cada casita. */
    private const array APARATO_POR_CASA = [
        1 => 'roku',
        2 => 'roku',
        3 => 'roku',
        4 => 'roku',
        5 => 'roku',
        6 => 'xiaomi',
        7 => 'android',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice qué haría.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');
        $creados = 0;
        $saltados = 0;

        foreach (self::APARATO_POR_CASA as $casa => $aparato) {
            $nombre = sprintf('Televisor (casa %d)', $casa);

            if ($this->em->getRepository(PmsGuiaItem::class)->findOneBy(['nombreInterno' => $nombre]) !== null) {
                $io->text(sprintf('· %s ya existe, no se toca.', $nombre));
                ++$saltados;

                continue;
            }

            $seccion = $this->em->getRepository(PmsGuiaSeccion::class)
                ->findOneBy(['nombreInterno' => sprintf('Manual (casa %d)', $casa)]);

            if ($seccion === null) {
                $io->warning(sprintf('No encuentro «Manual (casa %d)»: me salto esa casa.', $casa));
                ++$saltados;

                continue;
            }

            $io->text(sprintf('+ %s (%s) → %s', $nombre, $aparato, $seccion->getNombreInterno()));

            if ($seco) {
                ++$creados;

                continue;
            }

            $item = (new PmsGuiaItem())
                ->setNombreInterno($nombre)
                ->setTipo(PmsGuiaItem::TIPO_TARJETA)
                ->setVisibilidad(PmsGuiaVisibilidad::Cliente)
                ->setCategoria('general')
                ->setIcono('fa-tv')
                ->setTitulo([['language' => 'es', 'content' => 'Televisión']])
                ->setDescripcion([['language' => 'es', 'content' => $this->cuerpoPublicado($aparato)]])
                ->setAgenteTerminos($this->terminos($aparato))
                ->setAgenteContenido($this->paraElAgente($aparato))
                ->setAgentePasos([$this->sinCanalesLocales()]);

            $enlace = (new PmsGuiaSeccionHasItem())
                ->setSeccion($seccion)
                ->setItem($item)
                // Detrás de lo que ya hay: la tele no es lo primero que se necesita al llegar.
                ->setOrden(50);

            $this->em->persist($item);
            $this->em->persist($enlace);
            ++$creados;
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%d ítem(s) %s, %d saltado(s).',
            $creados,
            $seco ? 'se crearían' : 'creados',
            $saltados
        ));

        return Command::SUCCESS;
    }

    /** Lo que lee el huésped en la app. Lleva HTML porque el resto de la guía lo lleva. */
    private function cuerpoPublicado(string $aparato): string
    {
        return match ($aparato) {
            'roku' => '<p>La televisión funciona con un <strong>Roku</strong>, que tiene su '
                . '<strong>propio mando</strong>, aparte del mando de la tele.</p>'
                . '<p>👉 Enciende la tele con su mando y, si no aparece la pantalla del Roku, '
                . 'cambia la entrada con el botón de <strong>fuente</strong> (Source / Input). '
                . 'A partir de ahí se maneja todo con el mando del Roku.</p>'
                . '<p>📺 Están instalados <strong>Netflix</strong> y <strong>HBO Max</strong>. '
                . 'Puedes entrar con tu propia cuenta.</p>',
            'xiaomi' => '<p>La televisión funciona con un <strong>Mi TV Stick de Xiaomi</strong>, '
                . 'que tiene su <strong>propio mando</strong>, aparte del mando de la tele.</p>'
                . '<p>👉 Enciende la tele con su mando y, si no aparece la pantalla del stick, '
                . 'cambia la entrada con el botón de <strong>fuente</strong> (Source / Input). '
                . 'A partir de ahí se maneja todo con el mando del stick.</p>'
                . '<p>📺 Puedes entrar con tu propia cuenta en las aplicaciones de streaming.</p>',
            default => '<p>La televisión es una <strong>Smart TV Android</strong>: va todo '
                . 'integrado y se maneja con <strong>un solo mando</strong>.</p>'
                . '<p>👉 Enciéndela y las aplicaciones están en la pantalla de inicio. Puedes '
                . 'entrar con tu propia cuenta.</p>',
        };
    }

    /** Con qué palabras pregunta la gente por esto. */
    private function terminos(string $aparato): string
    {
        $comunes = 'television, televisor, tele, tv, control, mando, netflix, hbo, streaming, '
            . 'ver peliculas, no se ve nada, pantalla en negro, no funciona la tv, remote, '
            . 'how to use the tv, canales, canal';

        return match ($aparato) {
            'roku' => 'roku, ' . $comunes,
            'xiaomi' => 'xiaomi, mi tv stick, stick, ' . $comunes,
            default => 'smart tv, android tv, ' . $comunes,
        };
    }

    /** Peldaño 0: cómo se usa, y nada más. */
    private function paraElAgente(string $aparato): string
    {
        return match ($aparato) {
            'roku' => <<<'TXT'
                La tele funciona con un Roku, que tiene su PROPIO mando, aparte del mando de la tele.
                Es la confusion mas comun: si dice que "no funciona" o que "no aparece nada",
                casi siempre esta usando el mando equivocado o la tele esta en otra entrada.

                Que encienda la tele con su mando y, si no ve la pantalla del Roku, cambie la entrada
                con el boton de fuente (Source / Input). A partir de ahi todo se maneja con el mando
                del Roku.

                Tiene Netflix y HBO Max instalados. Puede entrar con su propia cuenta.
                TXT,
            'xiaomi' => <<<'TXT'
                La tele funciona con un Mi TV Stick de Xiaomi, que tiene su PROPIO mando, aparte del
                mando de la tele. Es la confusion mas comun: si dice que "no funciona" o que "no
                aparece nada", casi siempre esta usando el mando equivocado o la tele esta en otra
                entrada.

                Que encienda la tele con su mando y, si no ve la pantalla del stick, cambie la entrada
                con el boton de fuente (Source / Input). A partir de ahi todo se maneja con el mando
                del stick.

                Puede entrar con su propia cuenta en las aplicaciones de streaming.
                TXT,
            default => <<<'TXT'
                Esta casita tiene una Smart TV Android: va todo integrado y se maneja con UN SOLO
                mando. Aqui no hay que cambiar de entrada ni buscar un segundo control, asi que no se
                lo sugieras.

                Las aplicaciones estan en la pantalla de inicio. Puede entrar con su propia cuenta.
                TXT,
        };
    }

    /**
     * Paso 1: la negativa.
     *
     * No se dice a priori —quien pregunta cómo se enciende la tele no está pidiendo la parrilla—,
     * pero cuando pregunta por canales hay que decirlo claro y sin rodeos, que es peor dejarle
     * buscando algo que no existe.
     */
    private function sinCanalesLocales(): string
    {
        return <<<'TXT'
            Si pregunta por canales locales, television por cable o senal abierta: no tenemos. La tele
            funciona solo por internet, con aplicaciones de streaming, y no hay antena ni cable.

            Dilo con naturalidad y sin disculparte de mas: es lo que hay y la mayoria lo entiende a la
            primera. Si lo que quiere es ver algo en concreto, ofrecele las aplicaciones que si estan.
            TXT;
    }
}
