<?php

declare(strict_types=1);

namespace App\Finanzas\Command;

use App\Finanzas\Entity\FinMedioCobro;
use App\Finanzas\Enum\FinMedioCobroTipo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La nota de cobro de cada medio: lo que el huésped tiene que HACER con ese número.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * Un número de cuenta no dice cómo se usa, y en Western Union esa diferencia cuesta dinero:
 * la empresa ofrece **enviar a una cuenta bancaria** además del giro para recojo en tienda, y
 * un envío hecho por esa vía **no lo podemos cobrar**. El huésped no lo sabe —para él las dos
 * cosas son «mandar por Western Union»— y descubrirlo después es un giro perdido.
 *
 * La nota es el único sitio donde eso se puede decir, así que es contenido de negocio y vive
 * aquí, versionado, no tecleado una vez en el panel.
 *
 * ── Por qué un comando y no una migración ───────────────────────────────────
 * `FinMedioCobro::$nota` lleva `#[AutoTranslate(sourceLanguage: 'es')]`, y ese listener se
 * engancha a `preUpdate`. Un `UPDATE` en SQL **se lo salta**: el español diría que no se envíe
 * a una cuenta bancaria y los otros seis idiomas seguirían con el texto viejo, que no lo dice.
 * Un huésped alemán leería la versión sin el aviso, que es el caso que este comando existe
 * para evitar. Es la regla de CLAUDE.md: lo que dispara listeners entra por el ORM.
 *
 * ⚠️ **Y hace falta `setSobreescribirTraduccion(true)`.** El modo por defecto del listener es
 * «seguro»: sólo rellena los idiomas VACÍOS y respeta los que ya tienen texto. Al reescribir
 * una nota que ya estaba traducida, sin el flag el castellano cambiaría y los otros seis se
 * quedarían con la versión anterior — un desacuerdo silencioso entre idiomas, que es peor que
 * no haber tocado nada. El servicio lo devuelve a `false` solo tras ejecutarse.
 *
 * **Idempotente por el texto**: si el español ya coincide, no se toca ni se retraduce.
 *
 *   php bin/console fin:medios:notas --dry-run   # dice qué cambiaría
 *   php bin/console fin:medios:notas             # escribe y retraduce a los 7
 */
#[AsCommand(
    name: 'fin:medios:notas',
    description: 'Fija la nota de uso de cada medio de cobro y la retraduce. Idempotente.',
)]
final class FinNotasMedioCobroCommand extends Command
{
    /**
     * El castellano es la fuente; los otros seis los escribe el listener.
     *
     * Aquí va **sólo lo que es del medio**: cómo se ejecuta ese pago concreto. Cómo avisarnos
     * después NO va aquí aunque lo estuviera —ver `PmsChannel::CHAT_SIN_IMAGENES`—, porque
     * depende del canal de la reserva y este catálogo lo comparten el PMS y las cotizaciones.
     *
     * La cadena vacía **borra** la nota: es como se retira una que ya no debería estar.
     *
     * @var array<string, string>
     */
    private const array NOTAS = [
        // El aviso va DESPUÉS de la instrucción positiva y no en su lugar: quien lee «no
        // hagas X» sin saber antes qué SÍ hacer se queda sin saberlo. Y va explícito, porque
        // «recojo en tienda» describe nuestro lado del giro y el huésped elige el suyo en una
        // pantalla donde las dos opciones se llaman parecido.
        FinMedioCobroTipo::WESTERN_UNION->value =>
            'Envío en efectivo para recojo en tienda Western Union en Perú. '
            // ⚠️ «a ese nombre» NO vale, aunque el nombre esté un renglón más arriba: el
            // traductor lo leyó como el REMITENTE en italiano y en neerlandés («a nome di
            // questo mittente», «op naam van de afzender»), y con eso el huésped pondría su
            // propio nombre y el giro quedaría incobrable — el mismo fallo que esta nota
            // existe para evitar, sólo que en dos idiomas. Se nombra el papel entero.
            . 'El destinatario del giro es el nombre indicado en este recuadro. '
            . 'No uses la opción de envío a una cuenta bancaria: Western Union también la '
            . 'ofrece y ese dinero no lo podemos cobrar. '
            // Sin decir POR DÓNDE: eso lo pone el aviso de canal, que es quien sabe si el
            // chat de esta reserva admite imágenes. Aquí acabaría repetido y en desacuerdo.
            . 'En cuanto lo envíes, pásanos el número de seguimiento (MTCN).',

        // ⚠️ Vacías a propósito, y no es que les falte texto: llevaban «el chat de Booking no
        // admite imagenes» —con la errata propagada a los otros seis idiomas— y eso se le
        // estaba enseñando a huéspedes de reservas DIRECTAS, que no vinieron por Booking. Lo
        // que decían de útil («avísanos cuando lo hayas hecho») lo dice ahora el aviso de
        // canal, una vez y en el idioma correcto.
        FinMedioCobroTipo::YAPE->value => '',
        FinMedioCobroTipo::PLIN->value => '',
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

        $medios = $this->em->getRepository(FinMedioCobro::class)->findAll();
        $tocados = 0;

        foreach ($medios as $medio) {
            $esperado = self::NOTAS[$medio->getTipo()->value] ?? null;

            if ($esperado === null) {
                continue;
            }

            // `?? ''` para que la nota AUSENTE y el `''` del mapa se comparen iguales: si no,
            // el comando volvería a «borrar» lo ya borrado en cada pasada.
            if (($medio->getNotaEn('es') ?? '') === $esperado) {
                $io->writeln(sprintf('  <comment>=</comment> %s ya la tiene', $medio->getTipo()->label()));
                continue;
            }

            ++$tocados;
            $io->writeln(sprintf('  <info>~</info> %s', $medio->getTipo()->label()));
            $io->writeln(sprintf('      antes: %s', $medio->getNotaEn('es') ?? '—'));
            $io->writeln(sprintf('      ahora: %s', $esperado !== '' ? $esperado : '<comment>(sin nota)</comment>'));

            if ($simular) {
                continue;
            }

            $medio
                ->setNota($esperado === '' ? [] : [['language' => 'es', 'content' => $esperado]])
                // Ver el aviso de la cabecera: sin esto los otros seis idiomas se quedan con
                // el texto anterior y sólo el castellano avisa de lo de la cuenta bancaria.
                ->setSobreescribirTraduccion(true);
        }

        if ($tocados === 0) {
            $io->success('Todas las notas están al día.');

            return Command::SUCCESS;
        }

        if ($simular) {
            $io->note(sprintf('Simulación: se reescribirían %d. Sin --dry-run se traducen a los 7.', $tocados));

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d nota(s) reescrita(s) y retraducida(s).', $tocados));

        return Command::SUCCESS;
    }
}
