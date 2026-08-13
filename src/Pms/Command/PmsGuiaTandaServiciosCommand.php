<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsGuiaItem;
use App\Pms\Entity\PmsGuiaSeccion;
use App\Pms\Entity\PmsGuiaSeccionHasItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Escribe los textos de la tanda «Servicios» y enciende las fichas.
 *
 * ### La otra mitad de Version20260813220000
 *
 * La migración dejó el esqueleto: la sección, las filas de las fichas y los enlaces **apagados**.
 * Aquí se escribe lo que hay que traducir —títulos y cuerpos— por el ORM, para que
 * `AutoTranslationEventListener` genere los siete idiomas, y al final se encienden los enlaces.
 *
 * Mientras esto no corra, nadie ve nada: `PmsGuiaSeccion::getItemsApi()` filtra por enlace activo.
 *
 * ### Y recorta lo que sobra de las descripciones
 *
 * El bloque «Servicios y detalles» —calefactores y toallas— está **byte a byte idéntico** en las
 * siete fichas de descripción: siete copias de 434 caracteres que pueden separarse en cuanto
 * alguien corrija una. Se saca de ahí y pasa a la ficha de toallas, que es su sitio.
 *
 * ⚠️ Ese recorte NO puede ir en la migración: el texto está publicado en siete idiomas, y cortarlo
 * por SQL dejaría las traducciones diciendo algo que ya no está en el español. Por el ORM se
 * retraducen. Es la regla de CLAUDE.md §Qué entra por migración y qué tiene que entrar por comando.
 *
 * Idempotente: si una ficha ya tiene cuerpo, no se toca — para no pisar lo que se edite luego
 * desde el panel.
 */
#[AsCommand(
    name: 'app:pms:guia:tanda-servicios',
    description: 'Escribe los textos de la sección Servicios y de las cocinas, y enciende las fichas.'
)]
final class PmsGuiaTandaServiciosCommand extends Command
{
    /** Las siete casas menos la 5, que tiene inducción de una hornilla y no comparte gas. */
    private const array GAS_COMPARTIDO = [2, 3, 4, 7];

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

        $io->section('Fichas de servicios');

        foreach ($this->servicios() as $nombre => [$titulo, $publicado, $agente]) {
            $this->escribir($io, $seco, $nombre, $titulo, $publicado, $agente);
        }

        $io->section('Cocinas');

        for ($casa = 1; $casa <= 7; ++$casa) {
            [$titulo, $publicado, $agente, $pasos] = $this->cocina($casa);
            $this->escribir($io, $seco, sprintf('Cocina (casa %d)', $casa), $titulo, $publicado, $agente, $pasos);
        }

        $io->section('Las descripciones sueltan el bloque de servicios');
        $recortadas = $this->recortarDescripciones($io, $seco);

        $io->section('Encender');
        $encendidos = $this->encender($io, $seco);

        if (!$seco) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%d descripción(es) recortada(s), %d enlace(s) %s.',
            $recortadas,
            $encendidos,
            $seco ? 'se encenderían' : 'encendidos'
        ));

        return Command::SUCCESS;
    }

    /** @param list<string> $pasos */
    private function escribir(
        SymfonyStyle $io,
        bool $seco,
        string $nombre,
        string $titulo,
        string $publicado,
        string $agente,
        array $pasos = []
    ): void {
        $item = $this->em->getRepository(PmsGuiaItem::class)->findOneBy(['nombreInterno' => $nombre]);

        if ($item === null) {
            $io->warning(sprintf('No encuentro «%s»: ¿corrió la migración?', $nombre));

            return;
        }

        // Si ya tiene cuerpo es que alguien lo editó en el panel: no se pisa.
        if ($item->getAgenteContenido() !== null) {
            $io->text(sprintf('· %s ya tiene texto, no se toca.', $nombre));

            return;
        }

        $io->text(sprintf('+ %s', $nombre));

        if ($seco) {
            return;
        }

        $item->setTitulo([['language' => 'es', 'content' => $titulo]])
            ->setDescripcion([['language' => 'es', 'content' => $publicado]])
            ->setAgenteContenido($agente)
            ->setAgentePasos($pasos);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private function servicios(): array
    {
        return [
            'Lavandería (general)' => [
                'Lavandería',
                '<p>🧺 Hay <strong>lavanderías económicas a media cuadra</strong>, justo frente a '
                . 'la gasolinera.</p>'
                . '<p>👕 Y si prefieres no salir, nuestro personal de limpieza ofrece servicio de '
                . 'lavandería por <strong>1 dólar el kilo</strong>. Avísanos por el chat y lo '
                . 'coordinamos.</p>'
                . '<p>⚠️ Te pedimos que no laves ropa con el agua del grifo del departamento.</p>',
                <<<'TXT'
                Hay lavanderias economicas a media cuadra, frente a la gasolinera. Y nuestro personal
                de limpieza ofrece servicio de lavanderia a 1 dolar el kilo: se coordina por el chat.

                Ofrecelo con confianza, es un servicio. Solo si pregunta por lavar en el
                departamento, dile que preferimos que no se lave ropa con el agua del grifo.
                TXT,
            ],
            'Toallas y ropa de cama (general)' => [
                'Toallas y ropa de cama',
                '<p>🛏️ Entregamos un <strong>set de toallas de cuerpo por huésped</strong> para toda '
                . 'la estancia.</p>'
                . '<p>🔄 En estancias largas hacemos un <strong>cambio de cortesía cada 4 días</strong>, '
                . 'previa solicitud: avísanos por el chat.</p>'
                . '<p>Si necesitas recambios antes de ese plazo, se puede — tienen un costo de '
                . 'gestión y lavandería.</p>',
                <<<'TXT'
                Se entrega un set de toallas de cuerpo por huesped para toda la estancia.

                En estancias largas hay un cambio de cortesia cada 4 dias, pero hay que pedirlo: no
                se hace solo. Que avise por el chat.
                TXT,
                // El coste baja de peldaño: quien pregunta por las toallas no esta pidiendo un
                // presupuesto. Ver CLAUDE.md, la regla de no decir lo negativo a priori.
            ],
            'Tabla de planchar (general)' => [
                'Plancha y tabla',
                '<p>👔 Si necesitas planchar, <strong>pídenos la tabla</strong> por el chat y te la '
                . 'llevamos.</p>'
                . '<p>⚠️ La plancha y la secadora de cabello se usan <strong>solo en el baño</strong>, '
                . 'nunca sobre la cama ni los muebles.</p>',
                <<<'TXT'
                Hay tabla de planchar a solicitud: que la pida por el chat y se la llevamos.

                Si va a planchar, recuerdale que la plancha y la secadora de cabello se usan solo en
                el bano — es para que no se dañen las sabanas ni los muebles, y se dice como
                recomendacion, no como reprimenda.
                TXT,
            ],
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: list<string>}
     */
    private function cocina(int $casa): array
    {
        $induccion = $casa === 5;

        $equipo = $induccion
            ? 'cocina de inducción de una hornilla, microondas, refrigerador, menaje y cubiertos'
            : 'cocina de 4 hornillas a gas, microondas, olla arrocera, refrigerador, menaje y cubiertos';

        $publicado = sprintf(
            '<p>🍳 La cocina tiene <strong>%s</strong>.</p>%s',
            $equipo,
            $induccion
                ? '<p>👉 Al ser de <strong>inducción</strong>, sólo calienta con ollas de fondo '
                . 'plano y magnético. Si una olla no calienta, casi siempre es eso.</p>'
                : '<p>👉 Las hornillas son <strong>a gas</strong>.</p>'
        );

        $agente = sprintf(
            <<<'TXT'
            La cocina tiene %s.

            OJO CON EL MICROONDAS: es lo unico que hay para calentar. No hay horno. Si dice que
            intento «usar el hornito» y no le funciono, casi seguro es el microondas y esperaba otra
            cosa — nombraselo asi, «el microondas», en vez de dejar que lo deduzca.

            No hay licuadora.%s
            TXT,
            $equipo,
            $induccion
                ? "\n\nEsta cocina es de INDUCCION y de una sola hornilla: solo calienta con ollas de "
                    . 'fondo plano y magnetico. Si dice que no calienta, preguntale que olla esta usando.'
                : ''
        );

        $pasos = in_array($casa, self::GAS_COMPARTIDO, true)
            ? [
                <<<'TXT'
                Si no prende ninguna hornilla: en esta casita la cocina y la ducha comparten el mismo
                balon de gas. Pidele que compruebe si sale agua caliente en la ducha.

                - Si tampoco sale agua caliente, es el balon: esta vacio y se lo cambiamos. Diselo y
                  avisa al equipo indicando que es cambio de balon.
                - Si el agua sale caliente, hay gas y el problema es de la cocina. Avisa al equipo.
                TXT,
            ]
            : [];

        return [
            'Cocina',
            $publicado,
            $agente,
            $pasos,
        ];
    }

    /**
     * Saca el bloque «Servicios y detalles» de las siete descripciones.
     *
     * Es idéntico en las siete —mismo MD5, 434 caracteres— y ya vive en su ficha propia. Dejarlo
     * duplicado es garantizar que algún día digan cosas distintas, como pasó con las duchas.
     */
    private function recortarDescripciones(SymfonyStyle $io, bool $seco): int
    {
        $recortadas = 0;

        for ($casa = 1; $casa <= 7; ++$casa) {
            $nombre = sprintf('Descripción (casa %d)', $casa);
            $item = $this->em->getRepository(PmsGuiaItem::class)->findOneBy(['nombreInterno' => $nombre]);

            if ($item === null) {
                continue;
            }

            $cuerpo = (string) $item->getAgenteContenido();
            $corte = mb_strpos($cuerpo, 'Servicios y detalles');

            if ($corte === false) {
                $io->text(sprintf('· %s ya está recortada.', $nombre));

                continue;
            }

            $io->text(sprintf('- %s pierde %d caracteres', $nombre, mb_strlen($cuerpo) - $corte));
            ++$recortadas;

            if (!$seco) {
                $item->setAgenteContenido(rtrim(mb_substr($cuerpo, 0, $corte)));
            }
        }

        return $recortadas;
    }

    /** Enciende los enlaces que la migración dejó apagados. */
    private function encender(SymfonyStyle $io, bool $seco): int
    {
        $encendidos = 0;

        /** @var list<PmsGuiaSeccionHasItem> $enlaces */
        $enlaces = $this->em->getRepository(PmsGuiaSeccionHasItem::class)->findBy(['activo' => false]);

        foreach ($enlaces as $enlace) {
            $item = $enlace->getItem();

            // Sólo se enciende lo que ya tiene texto: un enlace apagado sin cuerpo es una ficha a
            // medio hacer, y publicarla vacía es peor que no publicarla.
            if ($item === null || $item->getAgenteContenido() === null) {
                continue;
            }

            $io->text(sprintf('✓ %s', $item->getNombreInterno()));
            ++$encendidos;

            if (!$seco) {
                $enlace->setActivo(true);
            }
        }

        return $encendidos;
    }
}
