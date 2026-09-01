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
 * Crea las dos plantillas de pago al huésped: `pago_texto` y `pago`.
 *
 * ── Por qué un comando y no una migración ───────────────────────────────────
 * Los cuerpos llevan `#[AutoTranslate(sourceLanguage: 'es')]` y ese listener se engancha a
 * `prePersist`. Un `INSERT` en SQL se lo salta y las plantillas nacerían **sólo en español**:
 * un huésped alemán recibiría el cuerpo en castellano sin que fallara nada. Regla de CLAUDE.md.
 *
 * **Idempotente por `code`**: las que ya existan no se tocan, ni siquiera para reescribir su
 * español — puede haberlas afinado alguien desde el panel, y pisarlas sería descartar ese
 * trabajo sin avisar.
 *
 *   php bin/console msg:plantillas:pago --dry-run
 *   php bin/console msg:plantillas:pago
 *
 * ── Por qué son DOS y no una ────────────────────────────────────────────────
 * `pago_texto` lleva el detalle de verdad y **no puede ser plantilla de Meta**: un parámetro de
 * Meta no admite saltos de línea —y el bloque son cuatro renglones— y su cuerpo aprobado es
 * texto fijo, mientras que aquí el texto de alrededor cambia según se pida adelanto, saldo o
 * total. Por eso su `whatsapp_meta_tmpl` nace **apagado a propósito**: así
 * `MessageDispatcher::resolveChannels()` no la ofrece por ese canal.
 *
 * `pago` cubre el único hueco que deja la anterior: WhatsApp con la ventana de 24 h cerrada.
 * Corta, con un solo botón, y el detalle vive en la ficha del huésped.
 */
#[AsCommand(
    name: 'msg:plantillas:pago',
    description: 'Crea `pago_texto` y `pago` si no existen. Idempotente.',
)]
final class MessageCrearPlantillasPagoCommand extends Command
{
    /**
     * El cuerpo del detalle, para Beds24 y para el enlace manual.
     *
     * ⚠️ **Sin un «Te envío el resumen:» delante del bloque**, y es deliberado: `{{ bloque_pago }}`
     * viene VACÍO cuando el read-model calla —cruce de monedas sin imputar, datos incompletos—, y
     * hoy hay tres reservas así. Con esa frase, el mensaje anunciaría un resumen que no llega.
     * Sin ella el cuerpo se sostiene solo, porque la primera línea del bloque ya es su título.
     *
     * ⚠️ «Respóndeme por aquí» sí vale aquí, al revés que en la ficha del huésped: esto se lee
     * DENTRO de un chat, así que el «aquí» apunta a algo real.
     */
    private const string CUERPO_TEXTO = <<<'TXT'
        ¡Hola {{guest_name}}!

        Tu reserva: {{estancias}}

        {{bloque_pago}}

        Los detalles y medios de pago los puedes ver en el enlace a continuación:
        🔗 {{account_url}}

        Cualquier duda, respóndeme por aquí.
        TXT;

    /**
     * El cuerpo de Meta: fijo, corto y con dos parámetros escalares.
     *
     * ⚠️ Cumple las tres reglas de forma que Meta valida al aprobar: no empieza ni termina con
     * variable, no hay dos seguidas, y ningún parámetro lleva saltos de línea —por eso viaja
     * `importe_a_pagar`, que es una línea, y no `bloque_pago`.
     *
     * «Queda pendiente un pago» es neutro a propósito: sirve para adelanto, saldo y total sin
     * comprometer el texto fijo. Diciendo «adelanto» haría falta otra plantilla aprobada para
     * quien ya pagó algo.
     */
    private const string CUERPO_META = <<<'TXT'
        Hola {{guest_name}}, te escribo sobre el pago de tu reserva en Centro Cusco Inti.
        Queda pendiente un pago por {{importe_a_pagar}}. En el enlace verás las formas de pago disponibles y los datos para hacerlo.
        👇 Ábrelo con el botón de abajo.
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
        $repo = $this->em->getRepository(MessageTemplate::class);
        $filas = [];

        foreach ([$this->detalle(), $this->meta()] as $plantilla) {
            if ($repo->findOneBy(['code' => $plantilla->getCode()]) !== null) {
                $filas[] = [$plantilla->getCode(), '<comment>ya existe, no se toca</comment>'];
                continue;
            }

            $filas[] = [$plantilla->getCode(), $simular ? 'se crearía' : '<info>creada</info>'];

            if (!$simular) {
                $this->em->persist($plantilla);
            }
        }

        if (!$simular) {
            // Un solo flush: `AutoTranslate` corre en `prePersist` y rellena los seis idiomas
            // que faltan de cada cuerpo.
            $this->em->flush();
        }

        $io->table(['Plantilla', 'Qué pasa'], $filas);

        if ($simular) {
            $io->note('Simulación: no se ha escrito nada.');
        } else {
            $io->success('Listo. `pago` todavía tiene que subirse y aprobarse en Meta como `pago_v1`.');
        }

        return Command::SUCCESS;
    }

    /** `pago_texto`: el detalle completo, sólo por texto libre. */
    private function detalle(): MessageTemplate
    {
        $cuerpo = [['language' => 'es', 'content' => self::CUERPO_TEXTO]];

        return (new MessageTemplate())
            ->setCode('pago_texto')
            ->setName('Detalle de pago')
            ->setContextType('pms_reserva')
            // Vale para todos los canales de venta: el bloque ya se adapta solo a cada caso.
            ->setAllowedSources([])
            // El huésped puede pedírsela él mismo («¿cómo pago?»), que es el caso más frecuente
            // — y estando dentro de la ventana de 24 h por definición, el texto libre siempre sale.
            ->setAutoenvioHabilitada(true)
            ->setAgenteUso(
                'Manda el detalle de pago de la reserva: cuánto se pide ahora, cuánto sale con '
                . 'tarjeta, y el enlace a su ficha con los medios y los datos de cuenta. Úsala '
                . 'cuando el huésped pregunte cómo pagar, cuánto debe, o pida los datos para '
                . 'hacerlo. NO la uses si su cuenta ya está saldada.'
            )
            // `disable_meta_buttons`: el enlace ya va escrito en el texto. Con la emulación de
            // botones encendida saldría dos veces, igual que en `welcome_airbnb` y `enviar_guia`.
            ->setBeds24Tmpl(['is_active' => true, 'disable_meta_buttons' => true, 'body' => $cuerpo])
            ->setWhatsappLinkTmpl(['body' => $cuerpo])
            // ⚠️ **`is_active` SÍ, pero `is_official_meta` NO**, y la diferencia es toda.
            //
            // El canal de WhatsApp se ofrece por esta columna (`MessageDispatcher::resolveChannels()`),
            // así que apagarla dejaba a esta plantilla **sin poder salir por WhatsApp ni dentro de
            // la ventana** — cuando es justo el caso para el que se escribió. Encendida, el canal
            // se ofrece y `WhatsappMetaSendMappingStrategy` toma el cuerpo de
            // `whatsapp_link_tmpl`, que es el rico.
            //
            // Y `is_official_meta` en `false` es lo que la bloquea FUERA de la ventana: ahí salta
            // «Plantilla NO oficial fuera de ventana», que es el mensaje correcto — para eso
            // está `pago`. El cuerpo se deja vacío a propósito: no hay versión de Meta de esto.
            ->setWhatsappMetaTmpl(['is_active' => true, 'is_official_meta' => false, 'body' => []])
            ->setEmailTmpl(['is_active' => false, 'subject' => [], 'body' => []]);
    }

    /** `pago`: la de Meta, para cuando la ventana de 24 h está cerrada. */
    private function meta(): MessageTemplate
    {
        return (new MessageTemplate())
            ->setCode('pago')
            ->setName('Aviso de pago (plantilla Meta)')
            ->setContextType('pms_reserva')
            ->setAllowedSources([])
            // NO es de autoenvío: si el huésped acaba de escribir, la ventana está abierta y lo
            // que toca es `pago_texto`, con el detalle. Ésta la manda el equipo.
            ->setAutoenvioHabilitada(false)
            ->setAgenteUso(
                'SÓLO cuando la ventana de 24 h de WhatsApp está cerrada y hay que hablar de '
                . 'pagos: es una plantilla aprobada, no lleva el detalle, y su único trabajo es '
                . 'llevar al huésped a su ficha. Si la ventana está abierta, usa pago_texto.'
            )
            ->setWhatsappMetaTmpl([
                'is_active' => true,
                'category' => 'UTILITY',
                // ⚠️ Con sufijo desde el primer día: reescribir en Meta es crear otra y borrar
                // la vieja, y borrar bloquea el nombre 30 días. Con `_v1` la primera rotación
                // es cambiar este campo por `pago_v2`. Ver §18 de docs/Mensajeria.md.
                'meta_template_name' => 'pago_v1',
                // `false` hasta que Meta la apruebe de verdad. Ponerlo a mano haría que el panel
                // dijera «aprobada» sobre algo que nunca se subió.
                'is_official_meta' => false,
                'header' => [],
                'footer' => [],
                'body' => [['language' => 'es', 'content' => self::CUERPO_META]],
                'buttons_map' => [[
                    'index' => 0,
                    'type' => 'url',
                    // El dominio es fijo en la plantilla aprobada; sólo viaja el sufijo.
                    'content' => 'https://pax.openperu.pe/{{1}}',
                    'resolver_key' => 'account_path',
                    'button_text' => [['language' => 'es', 'content' => 'Ver mi reserva']],
                ]],
            ])
            // Por Beds24 no hay ventana que se cierre, así que ahí siempre sirve `pago_texto`.
            // Tener las dos activas sería dos formas de decir lo mismo por el mismo canal.
            ->setBeds24Tmpl(['is_active' => false, 'body' => []])
            ->setWhatsappLinkTmpl(['body' => []])
            ->setEmailTmpl(['is_active' => false, 'subject' => [], 'body' => []]);
    }
}
