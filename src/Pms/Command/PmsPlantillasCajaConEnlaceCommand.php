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
 * `llave_emergencia` y `pago_efectivo_caja` dejan de llevar los pasos y el código: llevan el ENLACE a su
 * ficha (`{{ acceso_llave_emergencia }}`, `{{ acceso_pago_efectivo }}`), que los enseña.
 *
 * ── Por qué ──────────────────────────────────────────────────────────────────
 * - **Una sola fuente.** Los pasos vivían en la plantilla (cuatro canales × siete idiomas); ahora
 *   viven en la ficha (`app:pms:guia:enlaces-del-equipo`). Cambiar un paso es una edición.
 * - **El código no se queda en el chat.** Escrito en el mensaje, seguía ahí meses después; en la
 *   ficha se lee al abrir el enlace, que caduca, y siempre el vigente.
 * - **Abre la puerta a Meta.** Una plantilla con la clave se rechaza (medido el 21/10/2026); una
 *   con un enlace sigue el patrón que ya aprueba (`guia_llegada`).
 *
 * Sólo se reescribe el ESPAÑOL de Beds24 y del enlace de WhatsApp: el listener traduce los demás.
 * El cuerpo oficial de Meta no se toca (sigue vacío). Idempotente: si el español ya lleva el enlace,
 * no hace nada. Con `--dry-run`. Nace `hidden`.
 */
#[AsCommand(
    name: 'app:pms:plantillas-caja-con-enlace',
    description: '«llave_emergencia» y «pago_efectivo_caja» mandan el enlace a su ficha en vez de los pasos.',
    hidden: true,
)]
final class PmsPlantillasCajaConEnlaceCommand extends Command
{
    /** @var array<string, string> plantilla => cuerpo en español */
    private const array CUERPOS = [
        'llave_emergencia' => <<<'TXT'
            Hola {{ guest_name }}, aquí tienes cómo sacar la llave de emergencia para entrar a {{ room_name }}, con el código de la caja, la foto y el vídeo:

            {{ acceso_llave_emergencia }}

            El enlace es sólo para ti: no lo compartas.
            TXT,
        'pago_efectivo_caja' => <<<'TXT'
            Hola {{ guest_name }}, aquí tienes cómo dejar el pago en efectivo en la caja fuerte, con el código, la foto y el vídeo:

            {{ acceso_pago_efectivo }}

            Antes de cerrar la caja, envíanos por aquí una foto del dinero dentro: es tu comprobante y el nuestro. El enlace es sólo para ti: no lo compartas.
            TXT,
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
        $cambiadas = 0;

        foreach (self::CUERPOS as $codigo => $cuerpo) {
            $plantilla = $repo->findOneBy(['code' => $codigo]);

            if (!$plantilla instanceof MessageTemplate) {
                $io->error(sprintf('No existe «%s».', $codigo));

                return Command::FAILURE;
            }

            $variable = (string) preg_replace('/.*(\{\{ acceso_\w+ \}\}).*/s', '$1', $cuerpo);

            if (str_contains(json_encode($plantilla->getWhatsappLinkTmpl()) ?: '', $variable)) {
                $io->text(sprintf('= «%s» ya manda el enlace.', $codigo));

                continue;
            }

            $io->section($codigo);
            $io->writeln($cuerpo);

            if ($dryRun) {
                continue;
            }

            // Sólo el español: con el resto fuera, el listener ve idiomas que faltan y los traduce.
            $soloEspanol = [['language' => 'es', 'content' => $cuerpo]];
            $plantilla->setBeds24Tmpl(['body' => $soloEspanol] + ($plantilla->getBeds24Tmpl() ?? []));
            $plantilla->setWhatsappLinkTmpl(['body' => $soloEspanol] + ($plantilla->getWhatsappLinkTmpl() ?? []));
            ++$cambiadas;
        }

        if ($dryRun) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d plantilla(s) mandan ahora el enlace a su ficha.', $cambiadas));

        return Command::SUCCESS;
    }
}
