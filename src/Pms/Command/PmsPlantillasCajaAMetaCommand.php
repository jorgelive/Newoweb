<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Message\Entity\MessageTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Da cuerpo de Meta a `llave_emergencia` y `caja_dinero` para que salgan FUERA de la ventana de 24 h.
 *
 * ── Por qué (Jorge, 21/10/2026) ──────────────────────────────────────────────
 * Las dos nacieron como texto dentro de la ventana, y casi siempre basta: el huésped acaba de
 * escribir. Pero puede LLAMAR por teléfono, y entonces el equipo le pide al agente del panel que se
 * la mande (`enviar_plantilla`) con la ventana cerrada. Por Beds24 (Booking, Airbnb) llega igual; a
 * una reserva DIRECTA no le llegaba nada, porque fuera de ventana sólo salen plantillas oficiales.
 *
 * ── Lo que Meta exige ───────────────────────────────────────────────────────
 * - Variables SIN espacios: `{{guest_name}}`, no `{{ guest_name }}`.
 * - Ni empezar ni TERMINAR en una variable.
 * - Ejemplos de cada variable: los pone `PmsMessageDataResolver` (datos de muestra).
 *
 * 🔥 **El primer intento (copiar el texto de dentro de ventana) se rechazó ENTERO: 14 de 14 con
 * `INCORRECT_CATEGORY`** (21/10/2026). Meta no dice qué categoría quería. Las tres sospechas, y lo
 * que este cuerpo hace con cada una:
 * - **No nombraba ninguna operación.** Un UTILITY tiene que ir sobre una transacción concreta del
 *   usuario: el cuerpo abre ahora con la reserva (`{{locator}}`) y el alojamiento.
 * - **«Marca el código X»** huele a AUTENTICACIÓN, cuya única forma admitida es el texto fijo de
 *   verificación. Se dice «marca en el teclado X», sin la palabra.
 * - **«Cualquier duda, escríbenos por aquí»** invita a conversar, que es lo que el clasificador
 *   asocia a marketing (le pasó a `mensaje_pendiente`, ver `MensajeEnEsperaDeVentana::PLANTILLA`).
 *   El cierre dice ahora POR QUÉ se manda.
 *
 * Por eso el cuerpo de Meta ya NO es copia del de dentro de ventana: está escrito aquí.
 *
 * El cuerpo se escribe en español y lo traduce `AutoTranslationEventListener` al guardar; en
 * `llave_emergencia` la etiqueta física «emergencia» se restaura después
 * (`PmsCrearPlantillaLlaveEmergenciaCommand::conEtiqueta()`). Nace `is_official_meta: false`: la
 * marca la pone sola `app:whatsapp:sync-templates` cuando Meta la aprueba. Subir:
 * `msg:meta:push <code> --todos`.
 *
 * Idempotente: reescribe el cuerpo de Meta mientras no sea oficial (uno aprobado no se toca), con
 * `--dry-run`. Nace `hidden`.
 */
#[AsCommand(
    name: 'app:pms:plantillas-caja-a-meta',
    description: 'Cuerpo oficial de Meta para «llave_emergencia» y «caja_dinero» (envío fuera de ventana).',
    hidden: true,
)]
final class PmsPlantillasCajaAMetaCommand extends Command
{
    /**
     * El cuerpo de Meta, en español: lo demás lo traduce el listener.
     *
     * @var array<string, string>
     */
    private const array CUERPOS = [
        PmsCrearPlantillaLlaveEmergenciaCommand::CODIGO => <<<'TXT'
            Información sobre tu reserva {{locator}} en {{room_name}}.

            Hola {{guest_name}}, estas son las instrucciones para retirar la llave de emergencia de tu alojamiento:

            1️⃣ En el pasadizo hay dos cajas fuertes digitales. La de la llave de emergencia es la de ARRIBA — la de abajo es la de las llaves de siempre.
            2️⃣ Marca en el teclado {{codigo_caja_dinero}} y gira la perilla hacia la derecha para abrir.
            3️⃣ Dentro hay una cartuchera celeste. Toma la llave con la etiqueta «emergencia» y el número de tu casita ({{room_name}}).
            4️⃣ Cierra la caja girando la perilla hacia la izquierda y asegúrate de que quedó bien cerrada.

            📷 Así es la caja: {{foto_caja_dinero}}
            🎥 Cómo se abre: {{video_caja_dinero}}

            Te enviamos estas instrucciones porque las solicitaste a nuestro equipo de recepción.
            TXT,
        'caja_dinero' => <<<'TXT'
            Información sobre el pago de tu reserva {{locator}} en {{room_name}}.

            Hola {{guest_name}}, estas son las instrucciones para dejar el pago en efectivo que acordaste con nuestro equipo:

            1️⃣ En el pasadizo hay dos cajas fuertes digitales. La del pago es la de ARRIBA — la de abajo es la de las llaves.
            2️⃣ Marca en el teclado {{codigo_caja_dinero}} y gira la perilla hacia la derecha para abrir.
            3️⃣ Antes de cerrar, toma una foto del dinero dentro de la caja y envíanosla: es tu comprobante y el nuestro.
            4️⃣ Cierra girando la perilla hacia la izquierda y asegúrate de que quedó bien cerrada.

            📷 Así es la caja: {{foto_caja_dinero}}
            🎥 Cómo se abre: {{video_caja_dinero}}

            Te enviamos estas instrucciones para completar el pago de tu reserva.
            TXT,
    ];

    /**
     * Lo que se le dice al agente: además de lo que ya decía, que la mande cuando se lo pide el equipo.
     *
     * @var array<string, string>
     */
    private const array AGENTE_USO = [
        PmsCrearPlantillaLlaveEmergenciaCommand::CODIGO => 'Instrucciones para sacar la LLAVE DE EMERGENCIA de la '
            . 'caja fuerte de arriba (la del dinero). La manda el equipo cuando el huésped no puede entrar porque su '
            . 'llave no estaba en la caja en el check-in o porque la perdió. SI TE LO PIDE ALGUIEN DEL EQUIPO —por '
            . 'ejemplo, porque el huésped llamó por teléfono—, mándala. EL HUÉSPED NO PUEDE PEDIRLA por su cuenta: '
            . 'si la pide él, no la mandes y escala al equipo. Si la perdió, el equipo además hace la cobranza.',
        'caja_dinero' => 'Instrucciones para dejar un pago en EFECTIVO en la caja fuerte del pasaje. La manda el '
            . 'equipo cuando se ha acordado que el huésped pague así. SI TE LO PIDE ALGUIEN DEL EQUIPO —por ejemplo, '
            . 'porque lo acordó con el huésped por teléfono—, mándala. EL HUÉSPED NO PUEDE PEDIRLA por su cuenta: si '
            . 'la pide él, no la mandes y consulta al equipo. El importe no va en el texto: dilo tú en el mensaje.',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Sólo dice qué haría.')] bool $dryRun = false,
    ): int {
        $repo = $this->em->getRepository(MessageTemplate::class);
        $hechas = [];

        foreach (self::AGENTE_USO as $codigo => $agenteUso) {
            $plantilla = $repo->findOneBy(['code' => $codigo]);

            if (!$plantilla instanceof MessageTemplate) {
                $io->error(sprintf('No existe «%s». No se toca nada más.', $codigo));

                return Command::FAILURE;
            }

            $meta = $plantilla->getWhatsappMetaTmpl() ?? [];

            // Una aprobada no se toca: el nombre en Meta no se edita y el cuerpo vivo es ése.
            if (($meta['is_official_meta'] ?? false) === true) {
                $io->text(sprintf('= «%s» ya es oficial en Meta.', $codigo));

                continue;
            }

            $cuerpo = self::CUERPOS[$codigo];

            $io->section($codigo);
            $io->writeln($cuerpo);

            if ($dryRun) {
                continue;
            }

            $plantilla->setWhatsappMetaTmpl([
                'is_active' => true,
                'category' => 'UTILITY',
                // Con sufijo desde el primer día: el nombre en Meta no se edita (docs/Mensajeria.md §18).
                'meta_template_name' => $codigo . '_v1',
                'is_official_meta' => false,
                'header' => [],
                'footer' => [],
                'body' => [['language' => 'es', 'content' => $cuerpo]],
                'buttons_map' => [],
            ] + $meta);
            $plantilla->setAgenteUso($agenteUso);
            $hechas[] = $plantilla;
        }

        if ($dryRun) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        // Primero se guarda y el listener traduce; después, la etiqueta física de la llave.
        $this->em->flush();

        foreach ($hechas as $plantilla) {
            if ($plantilla->getCode() === PmsCrearPlantillaLlaveEmergenciaCommand::CODIGO) {
                $fijadas = 0;
                $plantilla->setWhatsappMetaTmpl(PmsCrearPlantillaLlaveEmergenciaCommand::conEtiqueta($plantilla->getWhatsappMetaTmpl(), $fijadas));
                $plantilla->setEjecutarTraduccion(false);
                $io->text(sprintf('Etiqueta «emergencia» restaurada en %d idioma(s) del cuerpo de Meta.', $fijadas));
            }
        }

        $this->em->flush();
        $io->success(sprintf(
            '%d plantilla(s) con cuerpo de Meta. Falta subirlas: msg:meta:push <code> --todos',
            count($hechas)
        ));

        return Command::SUCCESS;
    }
}
