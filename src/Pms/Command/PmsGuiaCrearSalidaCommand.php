<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsGuia;
use App\Pms\Entity\PmsGuiaHasSeccion;
use App\Pms\Entity\PmsGuiaItem;
use App\Pms\Entity\PmsGuiaSeccion;
use App\Pms\Entity\PmsGuiaSeccionHasItem;
use App\Pms\Enum\PmsGuiaSeccionTipo;
use App\Pms\Enum\PmsGuiaVisibilidad;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La sección «Salida» de la guía, común a todas las casitas, con sus instrucciones de salida.
 *
 * ── Por qué ─────────────────────────────────────────────────────────────────
 * Las instrucciones para dejar la casa —llaves, cocina, basura, luces— sólo vivían en el texto del
 * `check_out` de WhatsApp: una página entera que enterraba la pregunta de la hora. El aviso nuevo
 * (`aviso_salida`) es corto y las enlaza con `{{salida_url}}`, que abre la guía en la sección de
 * tipo `salida`. Contenido aprobado por Jorge el 01/10/2026, sacado del `check_out` de Booking.
 *
 * Una sola sección enlazada a las siete guías, como «Pagos y Reglamento (general)»: lo que se dice
 * al salir es lo mismo en todas. Si un día una casita necesita algo propio, se le hace la suya.
 *
 * Por comando y no por migración: título y descripción son contenido publicado y se traducen a
 * siete idiomas al guardar (`#[AutoTranslate]`). Idempotente por `nombreInterno`.
 *
 *   php bin/console app:pms:guia:crear-salida --dry-run
 *   php bin/console app:pms:guia:crear-salida
 */
#[AsCommand(
    name: 'app:pms:guia:crear-salida',
    description: 'Crea la sección «Salida» de la guía con sus instrucciones y la enlaza a todas las casitas.',
    hidden: true,
)]
final class PmsGuiaCrearSalidaCommand extends Command
{
    private const string SECCION = 'Salida (general)';
    private const string ITEM = 'Instrucciones de salida (general)';

    /** Detrás de «Pagos y Reglamento»: es lo último que se mira, el último día. */
    private const int ORDEN_EN_LA_GUIA = 6;

    private const string CUERPO = '<p>🕙 Deja el departamento a más tardar a las <strong>{{ hora_checkout }}</strong></p>'
        . '<p>🔑 <strong>Entrega de llaves</strong></p>'
        . '<ul><li>Deja la llave numerada en la caja fuerte donde la recogiste.</li>'
        . '<li>Deja la otra llave dentro del departamento, en el lugar donde la encontraste.</li>'
        . '<li>Cierra bien la puerta principal al salir.</li></ul>'
        . '<p>🍽️ <strong>Cocina</strong></p>'
        . '<ul><li>Si usaste utensilios de cocina, déjalos lavados o enjuagados.</li></ul>'
        . '<p>🗑️ <strong>Basura</strong></p>'
        . '<ul><li>Deposita la basura en la plataforma frente a la casa.</li></ul>'
        . '<p>✅ <strong>Antes de salir</strong></p>'
        . '<ul><li>Cierra todas las ventanas y puertas.</li>'
        . '<li>Apaga las luces y desconecta los aparatos eléctricos 💡</li>'
        . '<li>Revisa que no olvidas nada: celular, cargador, adaptador 🔌</li></ul>'
        . '<p>🧳 <strong>Equipaje</strong></p>'
        . '<p>Si necesitas que guardemos tu equipaje después del check-out, avísanos y lo coordinamos.</p>';

    private const string AGENTE = "SALIDA. Hasta \"hora_check_out\". Al irse:\n"
        . "- Llaves: la numerada, a la caja fuerte donde la recogió; la otra, dentro del departamento, "
        . "donde la encontró. Que cierre bien la puerta principal.\n"
        . "- Cocina: utensilios lavados o enjuagados.\n"
        . "- Basura: en la plataforma frente a la casa.\n"
        . "- Antes de salir: ventanas y puertas cerradas, luces apagadas, aparatos desconectados, y que "
        . "revise que no olvida nada.\n\n"
        . 'Si te dice a qué hora sale, apúntalo con confirmar_hora. Si necesita guardar el equipaje, '
        . 'está en el tema «Equipaje y horarios flexibles».';

    /**
     * Los títulos cortos, a mano en cada idioma. Sin contexto, el traductor leía «Check-out» como
     * «Kasse» o «Vérifier», «Salida» como la salida de una autopista, y en neerlandés el ítem
     * acababa en «salir de la tienda». El cuerpo, con frases enteras, sí lo traduce bien.
     */
    private const array TITULO_SECCION = [
        'en' => 'Departure', 'pt' => 'Saída', 'fr' => 'Départ', 'it' => 'Partenza', 'de' => 'Abreise', 'nl' => 'Vertrek',
    ];
    private const array SUBTITULO_SECCION = [
        'en' => 'Check-out', 'pt' => 'Check-out', 'fr' => 'Check-out', 'it' => 'Check-out', 'de' => 'Check-out', 'nl' => 'Check-out',
    ];
    private const array TITULO_ITEM = [
        'en' => 'Check-out instructions', 'pt' => 'Instruções de saída', 'fr' => 'Instructions de départ',
        'it' => 'Istruzioni per la partenza', 'de' => 'Hinweise zur Abreise', 'nl' => 'Instructies bij vertrek',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
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

        $seccion = $this->em->getRepository(PmsGuiaSeccion::class)->findOneBy(['nombreInterno' => self::SECCION]);

        if ($seccion === null) {
            $io->text('+ sección ' . self::SECCION . ' con «' . self::ITEM . '»');

            if (!$seco) {
                $seccion = (new PmsGuiaSeccion())
                    ->setNombreInterno(self::SECCION)
                    ->setTipo(PmsGuiaSeccionTipo::Salida)
                    ->setIcono('fa-door-open')
                    ->setTitulo([['language' => 'es', 'content' => 'Salida']])
                    ->setSubtitulo([['language' => 'es', 'content' => 'Check-out']]);

                $item = (new PmsGuiaItem())
                    ->setNombreInterno(self::ITEM)
                    ->setTipo(PmsGuiaItem::TIPO_TARJETA)
                    ->setVisibilidad(PmsGuiaVisibilidad::Cliente)
                    ->setCategoria('general')
                    ->setIcono('fa-key')
                    ->setTitulo([['language' => 'es', 'content' => 'Instrucciones de salida']])
                    ->setDescripcion([['language' => 'es', 'content' => self::CUERPO]])
                    ->setAgenteTerminos('salida, check-out, checkout, me voy, dejar las llaves, dónde dejo la llave, basura, qué hago al salir')
                    ->setAgenteContenido(self::AGENTE);

                $this->em->persist($seccion);
                $this->em->persist($item);
                $this->em->persist((new PmsGuiaSeccionHasItem())->setSeccion($seccion)->setItem($item)->setOrden(0));

                // `AutoTranslate` corre al persistir y pisa cualquier traducción puesta antes.
                $this->em->flush();

                $seccion->setTitulo(self::aMano($seccion->getTitulo(), self::TITULO_SECCION))
                    ->setSubtitulo(self::aMano($seccion->getSubtitulo(), self::SUBTITULO_SECCION));
                $item->setTitulo(self::aMano($item->getTitulo(), self::TITULO_ITEM));
            }
        } else {
            $io->text('· ' . self::SECCION . ' ya existe: no se toca su contenido.');
        }

        $enlazadas = 0;
        foreach ($this->em->getRepository(PmsGuia::class)->findAll() as $guia) {
            $ya = $seccion !== null && $this->em->getRepository(PmsGuiaHasSeccion::class)->findOneBy(['guia' => $guia, 'seccion' => $seccion]) !== null;

            if ($ya) {
                continue;
            }

            ++$enlazadas;
            if (!$seco && $seccion !== null) {
                $this->em->persist((new PmsGuiaHasSeccion())->setGuia($guia)->setSeccion($seccion)->setOrden(self::ORDEN_EN_LA_GUIA)->setActivo(true));
            }
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->success(sprintf('%d guía(s) %s la sección de salida.', $enlazadas, $seco ? 'recibirían' : 'reciben'));

        return Command::SUCCESS;
    }

    /**
     * Cambia el texto de cada idioma y deja la fila como estaba: el `origenHash` que puso el
     * traductor es lo que le dice que esa traducción está al día y que no la rehaga.
     *
     * @param list<array{language?: string, content?: string|null}> $filas
     * @param array<string, string>                                 $textos idioma → texto
     *
     * @return list<array{language?: string, content?: string|null}>
     */
    private static function aMano(array $filas, array $textos): array
    {
        foreach ($filas as $i => $fila) {
            $idioma = $fila['language'] ?? '';
            if (isset($textos[$idioma])) {
                $filas[$i]['content'] = $textos[$idioma];
            }
        }

        return $filas;
    }
}
