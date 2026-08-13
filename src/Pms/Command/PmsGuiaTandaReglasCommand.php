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
 * «Reglas» se queda con reglas: fuera los servicios, dentro las mascotas.
 *
 * ### Qué sale
 *
 * Dos servicios que estaban ahí porque no había dónde ponerlos, y que ya tienen ficha propia en la
 * sección Servicios:
 *
 * - Las dos líneas de **lavandería** (las de media cuadra y el $1/kg), que colgaban de la regla
 *   «no lavar ropa con el agua del grifo».
 * - La **tabla de planchar**, que colgaba de «las planchas se usan sólo en el baño».
 *
 * ⚠️ **Pero se deja el puntero.** Quitar la alternativa y dejar sólo la prohibición convierte la
 * ficha en un muro de noes: quien lee «no laves ropa aquí» tiene que poder saber dónde sí. La
 * regla se queda; el detalle se va y queda la señal de dónde está.
 *
 * ### Qué entra
 *
 * **No se aceptan mascotas.** No estaba escrito en ninguna parte, y es pregunta de prospecto —de
 * las que deciden una reserva—. Va dicho y ya: sin explicar por qué, que no le interesa a nadie y
 * suena a excusa.
 *
 * ### Qué baja de peldaño
 *
 * «La grasa y el maquillaje pueden requerir limpieza especial o reposición **a costo del
 * huésped**» sale del peldaño 0. Al que pregunta si puede fumar no se le contesta con una factura.
 * Se dice cuando pregunta qué pasa si algo se estropea, o cuando ya ocurrió.
 *
 * ### Por qué comando y no migración
 *
 * Toca `descripcion`, publicada en siete idiomas: por SQL las traducciones se quedarían diciendo
 * lo que el español ya no dice. Ver CLAUDE.md §Qué entra por migración y qué tiene que entrar por
 * comando.
 */
#[AsCommand(
    name: 'app:pms:guia:tanda-reglas',
    description: 'Saca los servicios de «Reglas», añade las mascotas y baja lo punitivo a un paso.'
)]
final class PmsGuiaTandaReglasCommand extends Command
{
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

        $item = $this->em->getRepository(PmsGuiaItem::class)->findOneBy(['nombreInterno' => 'Reglas (general)']);

        if ($item === null) {
            $io->error('No encuentro «Reglas (general)».');

            return Command::FAILURE;
        }

        $io->definitionList(
            ['publicado antes' => mb_strlen((string) $this->soloEspanol($item->getContenidoParaCliente()))],
            ['agente antes' => mb_strlen((string) $item->getAgenteContenido())],
            ['pasos antes' => $item->pasosDisponibles()]
        );

        $io->text([
            '- salen: las dos líneas de lavandería y la tabla de planchar (ya tienen ficha)',
            '- queda el puntero a Servicios, para no dejar una prohibición sin alternativa',
            '+ entra: no se aceptan mascotas',
            '↓ baja al paso 1: el coste de limpieza especial o reposición',
        ]);

        if (!$seco) {
            $item->setDescripcion([['language' => 'es', 'content' => $this->publicado()]])
                ->setAgenteContenido($this->paraElAgente())
                ->setAgentePasos([$this->pasoDelCoste()]);

            $this->em->flush();
        }

        $io->success($seco ? 'Nada escrito (--dry-run).' : 'Reglas reordenada y retraducida.');

        return Command::SUCCESS;
    }

    /** @param array<int, mixed> $traducciones */
    private function soloEspanol(array $traducciones): ?string
    {
        foreach ($traducciones as $t) {
            if (is_array($t) && ($t['language'] ?? null) === 'es') {
                return (string) ($t['content'] ?? '');
            }
        }

        return null;
    }

    /** Lo que lee el huésped en la app. Se conserva el estilo de la ficha original. */
    private function publicado(): string
    {
        return <<<'HTML'
            <ul style="list-style-type: disc;">
            <li>🎉 <strong>No se permiten fiestas ni reuniones ruidosas.</strong></li>
            </ul>
            <p style="margin-left: 40px;"><span style="color: #45b0e1;">➤</span> Las horas de silencio son de 10:00 p.m. a 8:00 a.m., tal como se indica en todas las plataformas de reserva.</p>
            <ul>
            <li>🚭 <strong>No está permitido fumar</strong>, encender velas ni usar incienso dentro de la vivienda.</li>
            <li>🐾 <strong>No se aceptan mascotas.</strong></li>
            <li>👫 <strong>Solo los huéspedes registrados</strong> pueden ingresar a la propiedad.</li>
            </ul>
            <p style="margin-left: 40px;"><span style="color: #45b0e1;">➤</span> No se permiten visitantes no registrados.</p>
            <p style="margin-left: 40px;"><span style="color: #45b0e1;">➤</span> La tarifa se calcula por persona, y los huéspedes adicionales deben ser pagados.</p>
            <ul>
            <li>🛏️ No comer alimentos 🍗🍕 ni aplicar maquillaje 💄 sobre las camas.</li>
            <li>👶 Familias con niños pequeños: por favor, supervisar cuando usen marcadores o lápices de colores 🖍️ en la mesa, para evitar manchas en la ropa de cama o paredes.</li>
            <li>💦 No laves ropa con el agua del grifo del departamento.</li>
            </ul>
            <p style="margin-left: 40px;"><span style="color: #45b0e1;">➤</span> En <strong>Servicios</strong> tienes las lavanderías cercanas y nuestro servicio de lavado.</p>
            <ul>
            <li>🔌 Las planchas de cabello o secadoras deben usarse solo en el baño.</li>
            </ul>
            <p style="margin-left: 40px;"><span style="color: #45b0e1;">➤</span> Si necesitas planchar, pídenos la tabla: la encuentras en <strong>Servicios</strong>.</p>
            <ul>
            <li>🍽️ Reporta platos, vasos o utensilios rotos para que podamos reemplazarlos.</li>
            </ul>
            <p style="margin-left: 40px;"><span style="color: #45b0e1;">➤</span> Si notas algún daño al momento del check-in, infórmanos de inmediato.</p>
            <ul>
            <li>🔥 Los balones de gas duran entre 10 y 15 días.</li>
            </ul>
            <p style="margin-left: 40px;"><span style="color: #45b0e1;">➤</span> Por favor, úsalos responsablemente al cocinar o ducharte.</p>
            <ul>
            <li>💡 Antes de salir, verifica:
            <ul>
            <li>Que las perillas de la cocina estén apagadas</li>
            <li>Que las luces estén apagadas</li>
            <li>Que los calefactores (si se alquilaron) estén desenchufados</li>
            <li>Especialmente importante si viajas con niños.</li>
            </ul>
            </li>
            </ul>
            HTML;
    }

    /** Lo que cuenta el asistente: texto plano, sin emojis y sin segunda persona. */
    private function paraElAgente(): string
    {
        return <<<'TXT'
            Las normas de la casa. Contesta SOLO la que le interesa, no se las recites todas.

            - No se permiten fiestas ni reuniones ruidosas. Horas de silencio: de 10 de la noche a 8
              de la mañana, y esto figura en todas las plataformas de reserva.
            - No se puede fumar, ni encender velas, ni usar incienso dentro de la vivienda.
            - NO se aceptan mascotas. Dilo claro y sin rodeos si lo pregunta; no des explicaciones ni
              prometas consultarlo.
            - Solo entran los huespedes registrados: no se admiten visitantes. La tarifa es por
              persona y los huespedes adicionales se pagan.
            - No se come ni se usa maquillaje sobre las camas.
            - Con ninos pequenos, que supervisen los marcadores y lapices de colores en la mesa.
            - No se lava ropa con el agua del grifo. Si pregunta donde, eso es lavanderia: hay
              opciones cerca y servicio propio.
            - Las planchas de cabello y secadoras, solo en el bano. Si va a planchar ropa, hay tabla
              de planchar a solicitud.
            - Que avise si se rompe un plato, un vaso o un utensilio, para reponerlo. Y si al llegar
              encuentra algo dañado, que lo diga de inmediato.
            - Los balones de gas duran entre 10 y 15 dias; conviene usarlos con cabeza al cocinar y
              ducharse.
            - Antes de salir: perillas de la cocina apagadas, luces apagadas y calefactores
              desenchufados si se alquilaron.
            TXT;
    }

    /**
     * El coste, que no se dice a priori.
     *
     * Sale cuando pregunta qué pasa si algo se mancha o se rompe, o cuando ya ocurrió. Al que
     * pregunta si puede fumar no se le contesta con una factura.
     */
    private function pasoDelCoste(): string
    {
        return <<<'TXT'
            Si pregunta que pasa cuando algo se mancha o se rompe, o si ya ha pasado:

            La grasa y el maquillaje son dificiles de quitar de la ropa de cama y a veces obligan a
            una limpieza especial o a reponer la pieza; ese coste corre por cuenta del huesped. Lo
            mismo con los daños que no se reporten al llegar.

            Diselo como una explicacion, no como una amenaza, y sin cifras: el importe depende de lo
            que sea y lo confirma el equipo.
            TXT;
    }
}
