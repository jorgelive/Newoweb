<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsGuiaItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La Casita 6 por plantas, y sus gradas contadas empezando por lo que ganan (27/09/2026).
 *
 * ── La distribución publicada estaba mal ────────────────────────────────────
 * Ponía «baño privado» en las habitaciones 1 y 3. La 6 es un dúplex: un baño completo en el área
 * social de abajo y otro en la planta alta, fuera de las habitaciones. Faltaban además la mesa
 * de la cocina (4), los dos sillones del comedor y el estar de arriba (mesa y 4 sillas). Datos
 * dictados por el dueño.
 *
 * ── Las gradas, vendiendo ───────────────────────────────────────────────────
 * El texto del agente empezaba por el número —«se SUBEN 20 escalones»— y contaba los 12
 * peldaños interiores y los 32 totales a cualquiera que preguntara. El dueño lo reescribió con
 * la ventaja delante (nivel elevado: tranquilidad, luz, privacidad) y sin la escalera interior,
 * que a quien no le cuesta subir no le aporta nada. Esa escalera se cuenta SÓLO si hay alguien
 * con dificultad: omitirla del todo sería que se la encontrara al llegar.
 *
 * Termina preguntando si prefieren evitar escaleras —por dificultad o por gusto— y la respuesta
 * la recogen las instrucciones de prospecto e interesado, que ofrecen la 1, la 3 y la 4
 * (`PmsInstruccionesDominio`). Al huésped ya alojado no se le pregunta: no tiene nada que elegir.
 *
 * Por ORM y no por SQL: la descripción lleva `#[AutoTranslate]` y sus seis traducciones se
 * rehacen al cambiar el español (llevan `origenHash`).
 *
 * Nace `hidden` porque es de una vez. Idempotente por contenido.
 */
#[AsCommand(
    name: 'app:pms:guia:casita-6',
    description: 'Casita 6: distribución por plantas y texto de gradas del agente. Idempotente.',
    hidden: true,
)]
final class PmsGuiaCasita6Command extends Command
{
    private const string CODIGO = 'descripcion-casa-6';

    private const string MARCA_DISTRIBUCION = '<h3>Distribución</h3>';
    private const string MARCA_SIGUIENTE = '<h3>Servicios y detalles</h3>';

    private const string DISTRIBUCION = <<<'HTML'
        <h3>Distribución (dúplex)</h3>
        <p><strong>Planta baja — área social</strong></p>
        <ul>
        <li>🛋️ <strong>Sala/comedor.</strong></li>
        <li>🍳 <strong>Cocina:</strong> cocina de 4 hornillas, menajería, cubiertos, horno microondas, olla arrocera y refrigerador.</li>
        <li>🛁 <strong>Baño completo.</strong></li>
        </ul>
        <p><strong>Planta alta — descanso</strong></p>
        <ul>
        <li>🛋️ <strong>Estar.</strong></li>
        <li>🛏️ <strong>Habitación 1:</strong> dos camas dobles, TV con Roku (incluye Netflix y <strong>HBO Max</strong>).</li>
        <li>🛏️ <strong>Habitación 2:</strong> dos camas dobles, TV con Roku (incluye Netflix y <strong>HBO Max</strong>).</li>
        <li>🛏️ <strong>Habitación 3:</strong> dos camas dobles, TV con Roku (incluye Netflix y <strong>HBO Max</strong>).</li>
        <li>🛁 <strong>Baño completo.</strong></li>
        </ul>
        <p>Los dos baños tienen agua caliente con calentador a gas, jabón líquido para manos y papel higiénico.</p>
        HTML;

    private const string AGENTE = <<<'TXT'
        Hermoso apartamento independiente y privado con capacidad para 10 personas, ubicado en una avenida amplia y tranquila, con vista al bosque. Ideal para familias o grupos que buscan comodidad y excelente ubicación.

        - 📍 Ubicación: a 3 cuadras de la Plaza de Armas
        - 👥 Capacidad: hasta 10 huéspedes
        - 📶 Internet: por Wi-Fi para trabajo o entretenimiento.
        - 📺 Streaming: Netflix y HBO Max mediante Roku
        - 💧 Agua caliente: 24/7

        Distribución (dúplex)

        Planta baja — área social:
        - 🛋️ Sala/comedor: UN SOLO AMBIENTE, con una mesa para 6 personas y dos sillones.
        - 🍳 Cocina: con su propia mesa para 4 personas; cocina de 4 hornillas, menajería, cubiertos, horno microondas, olla arrocera y refrigerador.
        - 🛁 Baño completo.

        Planta alta — descanso:
        - 🛋️ Estar: un espacio de estar con una mesa y 4 sillas.
        - 🛏️ Habitación 1: dos camas dobles, TV con Roku (incluye Netflix y HBO Max).
        - 🛏️ Habitación 2: dos camas dobles, TV con Roku (incluye Netflix y HBO Max).
        - 🛏️ Habitación 3: dos camas dobles, TV con Roku (incluye Netflix y HBO Max).
        - 🛁 Baño completo.

        Los dos baños tienen agua caliente con calentador a gas, jabón líquido para manos y papel higiénico.

        Para comer se sientan hasta 10 a la vez: 6 en la sala/comedor y 4 en la cocina. Arriba hay 4 sillas más en el estar. Si preguntan por mesas, sillas o dónde comen todos juntos, cuéntalo así; la guía publicada no lo detalla.

        ACCESO Y NIVELES. No lo cuentes de entrada: sólo si preguntan por gradas, escaleras, accesibilidad, o si viaja alguien mayor o con movilidad reducida.

        Está en un nivel elevado respecto al pasaje, lo que le da más tranquilidad, luz y privacidad. Para llegar se suben dos tramos cortos de escalera, 20 escalones en total. Por dentro es un dúplex: abajo la sala, el comedor, la cocina y un baño completo; arriba las habitaciones con su propio baño completo. Así la zona de descanso queda totalmente separada de las áreas sociales, algo que los huéspedes valoran mucho cuando viajan en familia o en grupo.

        Si todavía no ha reservado, termina preguntando si a alguien del grupo le cuesta subir escaleras o prefieren evitarlas. Si ya está alojado, no preguntes: sólo describe.

        Si te dicen que a alguien le cuesta subir, cuéntale también que a las habitaciones se sube por una escalera interior.
        TXT;

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

        $item = $this->em->getRepository(PmsGuiaItem::class)->findOneBy(['codigo' => self::CODIGO]);

        if (!$item instanceof PmsGuiaItem) {
            $io->error(sprintf('No existe la ficha «%s».', self::CODIGO));

            return Command::FAILURE;
        }

        $cuerpo = null;

        foreach ($item->getDescripcion() ?? [] as $fila) {
            if (($fila['language'] ?? null) === 'es') {
                $cuerpo = (string) ($fila['content'] ?? '');
                break;
            }
        }

        if ($cuerpo === null) {
            $io->error('La ficha no tiene descripción en español.');

            return Command::FAILURE;
        }

        $tocado = false;

        if (!str_contains($cuerpo, 'Planta baja — área social')) {
            $inicio = strpos($cuerpo, self::MARCA_DISTRIBUCION);
            $fin = strpos($cuerpo, self::MARCA_SIGUIENTE);

            // A ciegas no: si la ficha se reordenó a mano, cortar por aproximación se llevaría
            // por delante lo que alguien escribió.
            if ($inicio === false || $fin === false || $fin < $inicio) {
                $io->error('La distribución publicada no está donde se esperaba: míralo en el panel.');

                return Command::FAILURE;
            }

            $io->text('<fg=green>~ guía publicada: distribución por plantas, un baño en cada una</>');

            if (!$simular) {
                $nuevo = substr($cuerpo, 0, $inicio) . self::DISTRIBUCION . "\n" . substr($cuerpo, $fin);
                $item->setDescripcion([['language' => 'es', 'content' => $nuevo]]);
            }

            $tocado = true;
        }

        if (trim((string) $item->getAgenteContenido()) !== trim(self::AGENTE)) {
            $io->text('<fg=green>~ agente: distribución por plantas y gradas contadas vendiendo</>');

            if (!$simular) {
                $item->setAgenteContenido(self::AGENTE);
            }

            $tocado = true;
        }

        if (!$tocado) {
            $io->success('Ya estaba.');

            return Command::SUCCESS;
        }

        if ($simular) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success('Casita 6 al día. Las traducciones de la guía se rehicieron al guardar.');

        return Command::SUCCESS;
    }
}
