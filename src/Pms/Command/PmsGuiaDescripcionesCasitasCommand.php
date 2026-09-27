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
 * La distribución y el acceso de cada casita, casita por casita (desde el 27/09/2026).
 *
 * ── Qué toca, y qué no ──────────────────────────────────────────────────────
 * De cada ficha `descripcion-casa-N`:
 * - en la guía publicada, SÓLO el bloque «Distribución», hasta «Servicios y detalles». La
 *   presentación y los servicios no se tocan;
 * - en el campo del agente, la distribución y el acceso. La presentación de arriba se conserva
 *   tal cual estaba.
 *
 * ── El criterio, dictado por el dueño con la Casita 6 ───────────────────────
 * - **La distribución por plantas**, con cada baño donde está. Las fichas decían «baño
 *   privado» en habitaciones donde no lo había.
 * - **El mobiliario, sólo al agente** («Sala/comedor», «Estar» a secas en la guía). Si no hay
 *   sala, el agente lo sabe dicho: si no, la inventa.
 * - **Las gradas, vendiendo**: primero lo que gana (nivel elevado: tranquilidad, luz,
 *   privacidad), luego el número. La escalera interior sólo si alguien tiene dificultad.
 * - **Termina preguntando** si prefieren evitar escaleras, sólo antes de reservar. La respuesta
 *   la recogen las instrucciones de prospecto e interesado (`PmsInstruccionesDominio`).
 *
 * Por ORM: la descripción lleva `#[AutoTranslate]` y sus traducciones se rehacen solas al
 * cambiar el español (llevan `origenHash`).
 *
 * Añadir una casita es añadir su entrada a {@see self::CASITAS}. Oculto, idempotente por
 * contenido.
 */
#[AsCommand(
    name: 'app:pms:guia:descripciones-casitas',
    description: 'Distribución por plantas y texto de acceso de cada casita. Idempotente.',
    hidden: true,
)]
final class PmsGuiaDescripcionesCasitasCommand extends Command
{
    private const string INICIO_DISTRIBUCION = '<h3>Distribución';
    private const string FIN_DISTRIBUCION = '<h3>Servicios y detalles</h3>';
    private const string INICIO_DISTRIBUCION_AGENTE = "\nDistribución";

    private const string PREGUNTA = 'Si todavía no ha reservado, termina preguntando si a alguien del grupo le cuesta subir escaleras o prefieren evitarlas. Si ya está alojado, no preguntes: sólo describe.';

    private const string CUANDO = 'ACCESO Y NIVELES. No lo cuentes de entrada: sólo si preguntan por gradas, escaleras, accesibilidad, o si viaja alguien mayor o con movilidad reducida.';

    /**
     * @var array<string, array{publica: string, agente: string, acceso: string}>
     */
    private const array CASITAS = [
        'descripcion-casa-1' => [
            'publica' => <<<'HTML'
                <h3>Distribución</h3>
                <ul>
                <li>🛋️ <strong>Sala/comedor.</strong></li>
                <li>🍳 <strong>Cocina:</strong> cocina de 4 hornillas, menajería, cubiertos, horno microondas, olla arrocera y refrigerador.</li>
                <li>🛏️ <strong>Habitación 1:</strong> dos camas individuales y una cama doble, con su propio baño completo, TV con Roku (incluye Netflix y <strong>HBO Max</strong>).</li>
                <li>🛏️ <strong>Habitación 2:</strong> dos camas dobles, con su propio baño completo, TV con Roku (incluye Netflix y <strong>HBO Max</strong>).</li>
                </ul>
                <p><strong>Dos baños completos</strong>, uno dentro de cada habitación. Tienen agua caliente con calentador a gas, jabón líquido para manos y papel higiénico.</p>
                HTML,
            'agente' => <<<'TXT'
                Distribución

                Todo en una sola planta:
                - 🛋️ Sala/comedor: UN SOLO AMBIENTE, con una mesa para 6 personas y dos sillones con una mesa de centro.
                - 🍳 Cocina: cocina de 4 hornillas, menajería, cubiertos, horno microondas, olla arrocera y refrigerador.
                - 🛏️ Habitación 1: dos camas individuales y una cama doble, con su propio baño completo dentro, TV con Roku (incluye Netflix y HBO Max).
                - 🛏️ Habitación 2: dos camas dobles, con su propio baño completo dentro, TV con Roku (incluye Netflix y HBO Max).

                DOS BAÑOS COMPLETOS, uno dentro de cada habitación. Tienen agua caliente con calentador a gas, jabón líquido para manos y papel higiénico.

                Para comer: 6 en la mesa, y la mesa de centro de los sillones también sirve, así que el resto del grupo puede comer ahí, de forma más informal. Cuéntalo si preguntan dónde comen todos.
                TXT,
            'acceso' => <<<'TXT'
                Es la de acceso más cómodo: la puerta da directamente a la calle, sin gradas para llegar, ideal si viajan con maletas pesadas, con personas mayores o con alguien con dificultad para subir escaleras. Por dentro todo está en una sola planta, con apenas unos escalones: se bajan dos al entrar a la sala y dos más hacia las habitaciones. Cada habitación tiene su propio baño completo, así que nadie cruza la casa de noche.

                Si hay alguien en silla de ruedas, dile esos cuatro escalones de bajada: no es accesible sin ayuda.
                TXT,
        ],
        'descripcion-casa-2' => [
            'publica' => <<<'HTML'
                <h3>Distribución</h3>
                <ul>
                <li>🛋️ <strong>Sala/comedor.</strong></li>
                <li>🍳 <strong>Cocina:</strong> cocina de 4 hornillas, menajería, cubiertos, horno microondas, olla arrocera y refrigerador.</li>
                <li>🛁 <strong>Baño completo</strong> en el área social: agua caliente con calentador a gas, jabón líquido para manos y papel higiénico.</li>
                <li>🛏️ <strong>Habitación 1:</strong> dos camas dobles y una cama individual, TV con Roku (incluye Netflix y <strong>HBO Max</strong>).</li>
                <li>🛏️ <strong>Habitación 2:</strong> una cama doble, TV con Roku (incluye Netflix y <strong>HBO Max</strong>).</li>
                </ul>
                HTML,
            'agente' => <<<'TXT'
                Distribución

                - 🛋️ Sala/comedor: UN SOLO AMBIENTE, con una mesa para 6 personas y dos sillones pequeños con una mesa de centro.
                - 🍳 Cocina: cocina de 4 hornillas, menajería, cubiertos, horno microondas, olla arrocera y refrigerador.
                - 🛁 UN SOLO BAÑO, completo, en el área social. Ninguna habitación tiene baño propio: si preguntan, dilo tal cual. Agua caliente con calentador a gas, jabón líquido para manos y papel higiénico.
                - 🛏️ Habitación 1: dos camas dobles y una cama individual, TV con Roku (incluye Netflix y HBO Max).
                - 🛏️ Habitación 2: una cama doble, TV con Roku (incluye Netflix y HBO Max).

                Para comer: 6 en la mesa, y la mesa de centro de los sillones también sirve para el resto. Cuéntalo si preguntan dónde comen todos.
                TXT,
            'acceso' => <<<'TXT'
                Su puerta da a la calle Saphi, pero la vivienda está en un segundo nivel: para llegar se sube un corto tramo de 10 escalones, y a cambio queda por encima de la calle, con más luz y tranquilidad. Dentro, dos escalones hasta la sala y uno pequeño a la cocina.

                ⚠️ Que la puerta esté a pie de calle sirve para ENCONTRARLA, no quiere decir que no haya escaleras: nunca la ofrezcas como sin gradas.

                {PREGUNTA}
                TXT,
        ],
        'descripcion-casa-3' => [
            'publica' => <<<'HTML'
                <h3>Distribución</h3>
                <ul>
                <li>🍽️ <strong>Comedor con área de cocina:</strong> cocina de 4 hornillas, menajería, cubiertos, horno microondas, olla arrocera y refrigerador.</li>
                <li>🛁 <strong>Baño completo</strong> en el área social: agua caliente con calentador a gas, jabón líquido para manos y papel higiénico.</li>
                <li>🛏️ <strong>Habitación 1:</strong> dos camas dobles, TV con Roku (incluye Netflix y <strong>HBO Max</strong>).</li>
                <li>🛏️ <strong>Habitación 2:</strong> dos camas dobles, TV con Roku (incluye Netflix y <strong>HBO Max</strong>).</li>
                </ul>
                HTML,
            'agente' => <<<'TXT'
                Distribución

                - 🍽️ Comedor con área de cocina, UN SOLO AMBIENTE: mesa con 4 sillas y un sillón. NO HAY SALA: si preguntan, dilo así. Cocina de 4 hornillas, menajería, cubiertos, horno microondas, olla arrocera y refrigerador.
                - 🛁 UN SOLO BAÑO, completo, en el área social. Ninguna habitación tiene baño propio. Agua caliente con calentador a gas, jabón líquido para manos y papel higiénico.
                - 🛏️ Habitación 1: dos camas dobles, TV con Roku (incluye Netflix y HBO Max).
                - 🛏️ Habitación 2: dos camas dobles, TV con Roku (incluye Netflix y HBO Max).

                ESPACIO PARA UN GRUPO GRANDE. No lo cuentes de entrada: sólo si preguntan dónde comen todos, dónde sentarse o por la comodidad para 8. Para comer hay 4 sillas y un sillón, y no hay otro espacio donde sentarse: si van 8, se turnan. La capacidad es «hasta» 8 compartiendo las camas dobles, así que con el grupo completo se gana en precio y se cede en amplitud. Dilo con naturalidad, sin disculparte. Si todavía no ha reservado y buscan más amplitud, la Casita 1 (mesa para 6, sillones y un baño en cada habitación) o la 6 (tres habitaciones, sitio para comer 10): mira con consultar_disponibilidad cuál está libre en sus fechas y ofrécela. Si ya está alojado, no ofrezcas otra casita.
                TXT,
            'acceso' => <<<'TXT'
                Está en el pasaje, al pie de las gradas: no hay que subir nada para llegar. Al entrar se bajan tres escalones al comedor y dos más hacia las habitaciones.

                Si hay alguien en silla de ruedas, dile esos escalones de bajada: no es accesible sin ayuda.
                TXT,
        ],
        'descripcion-casa-4' => [
            'publica' => <<<'HTML'
                <h3>Distribución</h3>
                <ul>
                <li>🍳 <strong>Cocina:</strong> cocina de 4 hornillas, menajería, cubiertos, horno microondas, olla arrocera y refrigerador.</li>
                <li>🛁 <strong>Baño completo</strong>, junto a la cocina: agua caliente con calentador a gas, jabón líquido para manos y papel higiénico.</li>
                <li>🛏️ <strong>Habitación:</strong> una cama doble y dos camas individuales, mesa con dos sillas, un pequeño sillón y TV con Roku (incluye Netflix y <strong>HBO Max</strong>).</li>
                </ul>
                HTML,
            'agente' => <<<'TXT'
                Distribución

                Al entrar hay un pequeño distribuidor con la cocina y dos puertas: la del baño y la de la habitación.
                - 🍳 Cocina: pequeña, sin mesa. Cocina de 4 hornillas, menajería, cubiertos, horno microondas, olla arrocera y refrigerador.
                - 🛁 UN SOLO BAÑO, completo, junto a la cocina (no dentro de la habitación). Agua caliente con calentador a gas, jabón líquido para manos y papel higiénico.
                - 🛏️ Habitación: una cama doble y dos camas individuales, una mesa con dos sillas y un pequeño sillón, TV con Roku (incluye Netflix y HBO Max). NO HAY SALA NI COMEDOR aparte: se come en la mesa de la habitación.

                ESPACIO PARA 4. No lo cuentes de entrada: sólo si preguntan dónde comen o dónde sentarse. Hay una mesa con dos sillas y un pequeño sillón: si van 4, se turnan para comer. Es una casita compacta y económica; dilo con naturalidad, sin disculparte. Si todavía no ha reservado y buscan más amplitud, la Casita 3, su vecina (comedor con 4 sillas y dos habitaciones), o la 2 (mesa para 6 y sillones): mira con consultar_disponibilidad cuál está libre en sus fechas y ofrécela. Si ya está alojado, no ofrezcas otra casita.
                TXT,
            'acceso' => <<<'TXT'
                Está en el pasaje: no hay que subir nada para llegar. Al entrar se bajan tres escalones al distribuidor, donde están la cocina y el baño, y dos más a la habitación.

                Si hay alguien en silla de ruedas, dile esos escalones de bajada: no es accesible sin ayuda.
                TXT,
        ],
        'descripcion-casa-5' => [
            'publica' => <<<'HTML'
                <h3>Distribución</h3>
                <ul>
                <li>🍽️ <strong>Comedor con área de cocina:</strong> cocina de inducción de una hornilla, menajería, cubiertos, horno microondas y refrigerador.</li>
                <li>🛏️ <strong>Habitación:</strong> una cama doble, TV con Roku (incluye Netflix y <strong>HBO Max</strong>).</li>
                <li>🛁 <strong>Baño completo</strong>, al costado del comedor: agua caliente con calentador a gas, jabón líquido para manos y papel higiénico.</li>
                </ul>
                HTML,
            'agente' => <<<'TXT'
                Distribución

                Todo en una sola planta:
                - 🍽️ Comedor con área de cocina, UN SOLO AMBIENTE: mesa para 4 personas. NO HAY SALA: si preguntan, dilo así. Cocina de inducción de UNA hornilla, menajería, cubiertos, horno microondas y refrigerador: da para desayunos y comidas sencillas, no para cocinar a lo grande.
                - 🛏️ Habitación: una cama doble, TV con Roku (incluye Netflix y HBO Max).
                - 🛁 Baño completo, al costado del comedor (no dentro de la habitación): agua caliente con calentador a gas, jabón líquido para manos y papel higiénico.

                Es para dos: la mesa para 4 les deja sitio de sobra para comer o trabajar.
                TXT,
            'acceso' => <<<'TXT'
                Está en un segundo nivel sobre el pasaje: para llegar se sube un corto tramo de 10 escalones, y a cambio queda más tranquila y con más luz. Dentro no se sube ni se baja nada: todo está en la misma planta.

                {PREGUNTA}
                TXT,
        ],
        'descripcion-casa-6' => [
            'publica' => <<<'HTML'
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
                HTML,
            'agente' => <<<'TXT'
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
                TXT,
            'acceso' => <<<'TXT'
                Está en un nivel elevado respecto al pasaje, lo que le da más tranquilidad, luz y privacidad. Para llegar se suben dos tramos cortos de escalera, 20 escalones en total. Por dentro es un dúplex: abajo la sala, el comedor, la cocina y un baño completo; arriba las habitaciones con su propio baño completo. Así la zona de descanso queda totalmente separada de las áreas sociales, algo que los huéspedes valoran mucho cuando viajan en familia o en grupo.

                {PREGUNTA}

                Si te dicen que a alguien le cuesta subir, cuéntale también que a las habitaciones se sube por una escalera interior.
                TXT,
        ],
        'descripcion-casa-7' => [
            'publica' => <<<'HTML'
                <h3>Distribución</h3>
                <p><strong>Planta baja</strong></p>
                <ul>
                <li>🍽️ <strong>Comedor.</strong></li>
                <li>🍳 <strong>Cocina:</strong> cocina de 4 hornillas, menajería, cubiertos, horno microondas, olla arrocera y refrigerador.</li>
                <li>🛏️ <strong>Habitación 1:</strong> tres camas dobles, con su propio baño completo, TV con Roku (incluye Netflix y <strong>HBO Max</strong>).</li>
                <li>🛁 <strong>Baño completo</strong> en el área social.</li>
                </ul>
                <p><strong>Planta alta</strong></p>
                <ul>
                <li>🛏️ <strong>Habitación 2:</strong> dos camas dobles, TV con Roku (incluye Netflix y <strong>HBO Max</strong>).</li>
                </ul>
                <p><strong>Dos baños completos</strong>, los dos en la planta baja: uno dentro de la Habitación 1 y otro en el área social. Tienen agua caliente con calentador a gas, jabón líquido para manos y papel higiénico.</p>
                HTML,
            'agente' => <<<'TXT'
                Distribución

                Planta baja:
                - 🍽️ Comedor: mesa con 6 sillas. NO HAY SALA: si preguntan, dilo así.
                - 🍳 Cocina: cocina de 4 hornillas, menajería, cubiertos, horno microondas, olla arrocera y refrigerador.
                - 🛏️ Habitación 1: tres camas dobles, con su propio baño completo dentro, TV con Roku (incluye Netflix y HBO Max).
                - 🛁 Baño completo en el área social.

                Planta alta:
                - 🛏️ Habitación 2: dos camas dobles, TV con Roku (incluye Netflix y HBO Max). No tiene baño: usa el del área social, abajo.

                DOS BAÑOS COMPLETOS, los dos en la planta baja: uno dentro de la Habitación 1 y otro en el área social. Tienen agua caliente con calentador a gas, jabón líquido para manos y papel higiénico.

                ESPACIO PARA UN GRUPO GRANDE. No lo cuentes de entrada: sólo si preguntan dónde comen todos, dónde sentarse o por la comodidad para 10. Para comer hay 6 sillas y no hay otro espacio donde sentarse: si van 10, se turnan. La capacidad es «hasta» 10 compartiendo las camas dobles, así que con el grupo completo se gana en precio y se cede en amplitud. Dilo con naturalidad, sin disculparte. Si todavía no ha reservado y buscan más amplitud, la Casita 6 tiene tres habitaciones y sitio para comer 10 a la vez: mira con consultar_disponibilidad que esté libre en sus fechas y ofrécela. Si ya está alojado, no ofrezcas otra casita.
                TXT,
            'acceso' => <<<'TXT'
                Está en un nivel elevado respecto al pasaje, lo que le da más tranquilidad, luz y privacidad. Para llegar se suben dos tramos cortos de escalera, 20 escalones en total. Por dentro, casi todo está en una sola planta: el comedor, la cocina, un baño completo y la habitación de tres camas dobles, que tiene su propio baño. La habitación de dos camas dobles está en un segundo nivel, apartada y tranquila.

                {PREGUNTA}

                Si te dicen que a alguien le cuesta subir: quien duerma en la habitación de abajo no vuelve a subir nada en toda la estancia. A la de arriba se sube por una escalera interior, y su baño es el del área social, abajo: dilo si preguntan dónde está el baño.
                TXT,
        ],
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
        $cambios = 0;

        foreach (self::CASITAS as $codigo => $contenido) {
            $item = $this->em->getRepository(PmsGuiaItem::class)->findOneBy(['codigo' => $codigo]);

            if (!$item instanceof PmsGuiaItem) {
                $io->error(sprintf('No existe la ficha «%s».', $codigo));

                return Command::FAILURE;
            }

            $publica = $this->distribucionPublica($item, $contenido['publica']);
            $agente = $this->textoDelAgente($item, $contenido['agente'], $contenido['acceso']);

            if ($publica === false || $agente === null) {
                $io->error(sprintf('«%s»: no está donde se esperaba; míralo en el panel.', $codigo));

                return Command::FAILURE;
            }

            if ($publica !== null) {
                $io->text(sprintf('<fg=green>~ %s: distribución publicada</>', $codigo));
                $cambios++;

                if (!$simular) {
                    $item->setDescripcion([['language' => 'es', 'content' => $publica]]);
                }
            }

            if ($agente !== (string) $item->getAgenteContenido()) {
                $io->text(sprintf('<fg=green>~ %s: texto del agente</>', $codigo));
                $cambios++;

                if (!$simular) {
                    $item->setAgenteContenido($agente);
                }
            }
        }

        if ($cambios === 0) {
            $io->success('Ya estaba todo.');

            return Command::SUCCESS;
        }

        if ($simular) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success('Hecho. Las traducciones de la guía se rehicieron al guardar.');

        return Command::SUCCESS;
    }

    /**
     * El cuerpo publicado con la distribución nueva; null si ya la tenía; false si no se
     * encuentra el bloque —a ciegas no se corta nada que alguien pudo escribir a mano—.
     */
    private function distribucionPublica(PmsGuiaItem $item, string $bloque): string|false|null
    {
        $cuerpo = null;

        foreach ($item->getDescripcion() ?? [] as $fila) {
            if (($fila['language'] ?? null) === 'es') {
                $cuerpo = (string) ($fila['content'] ?? '');
                break;
            }
        }

        if ($cuerpo === null) {
            return false;
        }

        $inicio = strpos($cuerpo, self::INICIO_DISTRIBUCION);
        $fin = strpos($cuerpo, self::FIN_DISTRIBUCION);

        if ($inicio === false || $fin === false || $fin < $inicio) {
            return false;
        }

        if (trim(substr($cuerpo, $inicio, $fin - $inicio)) === trim($bloque)) {
            return null;
        }

        return substr($cuerpo, 0, $inicio) . trim($bloque) . "\n" . substr($cuerpo, $fin);
    }

    /** Presentación de siempre + distribución + acceso; null si no se reconoce la ficha. */
    private function textoDelAgente(PmsGuiaItem $item, string $distribucion, string $acceso): ?string
    {
        $actual = (string) $item->getAgenteContenido();
        $corte = strpos($actual, self::INICIO_DISTRIBUCION_AGENTE);

        if ($corte === false) {
            return null;
        }

        return trim(substr($actual, 0, $corte)) . "\n\n" . trim($distribucion) . "\n\n"
            . self::CUANDO . "\n\n" . str_replace('{PREGUNTA}', self::PREGUNTA, trim($acceso));
    }
}
