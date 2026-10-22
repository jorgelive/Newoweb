<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Message\Entity\MessageTemplate;
use App\Pms\Guia\PmsGuiaEnlaceDelEquipo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cuerpo OFICIAL de Meta para las plantillas que mandan un enlace del equipo: las instrucciones van
 * en la ficha, la plantilla sólo avisa y lleva el BOTÓN (22/10/2026).
 *
 * ── Por qué así, y no con la clave ───────────────────────────────────────────
 * Meta rechazó todas las plantillas que daban o mencionaban una clave —cinco redacciones, medido
 * con sondas el 21/10/2026, `docs/Mensajeria.md` «Plantillas que se ofrecen a mano»—. Ésta no lleva
 * ninguna: nombra la reserva (lo que hace de un mensaje un aviso de servicio) y manda a la ficha por
 * un botón, el patrón de `guia_llegada`. Una sonda con ESTE texto y ESTE botón fue APROBADA como
 * UTILITY el 22/10/2026. No se toca el texto sin repetir la sonda (`var/probar-clasificador-meta.php`).
 *
 * El mismo texto para las tres —«las instrucciones de tu reserva»—: el botón decide qué ficha abre.
 * Una plantilla de Meta por plantilla local, porque cada una sube con su propio nombre y su botón
 * lleva su propia variable (`{{ acceso_<código>_path }}`).
 *
 * Los textos del botón van a mano en los siete idiomas, como en `MessageCrearGuiaLlegadaBotonesCommand`:
 * Meta corta en 25 caracteres y el traductor no lo sabe.
 *
 * Nace `is_official_meta: false`; la marca la pone `app:whatsapp:sync-templates` al aprobarse.
 * Subir: `msg:meta:push <código> --todos`. Idempotente (no toca una oficial), `--dry-run`, `hidden`.
 */
#[AsCommand(
    name: 'app:pms:plantillas-instrucciones-meta',
    description: 'Cuerpo de Meta (aviso + botón al enlace) para llave_emergencia, pago_efectivo_caja y llaves_interiores.',
    hidden: true,
)]
final class PmsPlantillasInstruccionesMetaCommand extends Command
{
    /** plantilla local => [nombre en Meta, código de la ficha que abre el botón] */
    private const array PLANTILLAS = [
        'llave_emergencia' => ['instrucciones_llave_emergencia_v1', 'llave-emergencia'],
        'pago_efectivo_caja' => ['instrucciones_pago_efectivo_v1', 'pago-efectivo'],
        'llaves_interiores' => ['instrucciones_llaves_interiores_v1', 'llaves-interiores'],
    ];

    /** El texto que aprobó Meta en la sonda del 22/10/2026. */
    private const string CUERPO = "Hola {{guest_name}}, te enviamos las instrucciones de tu reserva {{locator}} en {{room_name}}.\n\n"
        . 'Las tienes en el botón de abajo; el enlace es solo para ti.';

    /** @var array<string, string> */
    private const array BOTON = [
        'es' => 'Ver instrucciones',
        'en' => 'View instructions',
        'pt' => 'Ver instruções',
        'fr' => 'Voir les instructions',
        'it' => 'Vedi le istruzioni',
        'de' => 'Anleitung ansehen',
        'nl' => 'Instructies bekijken',
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

        foreach (self::PLANTILLAS as $codigo => [$nombreMeta, $ficha]) {
            $plantilla = $repo->findOneBy(['code' => $codigo]);

            if (!$plantilla instanceof MessageTemplate) {
                $io->error(sprintf('No existe «%s».', $codigo));

                return Command::FAILURE;
            }

            $meta = $plantilla->getWhatsappMetaTmpl() ?? [];

            if (($meta['is_official_meta'] ?? false) === true) {
                $io->text(sprintf('= «%s» ya es oficial en Meta.', $codigo));

                continue;
            }

            $variable = PmsGuiaEnlaceDelEquipo::variable($ficha) . '_path';
            $io->text(sprintf('%s → %s · botón {{1}} = {{ %s }}', $codigo, $nombreMeta, $variable));

            if ($dryRun) {
                continue;
            }

            $plantilla->setWhatsappMetaTmpl([
                'is_active' => true,
                'category' => 'UTILITY',
                'meta_template_name' => $nombreMeta,
                'is_official_meta' => false,
                'header' => [],
                'footer' => [],
                'body' => [['language' => 'es', 'content' => self::CUERPO]],
                'buttons_map' => [[
                    'index' => 0,
                    'type' => 'url',
                    'content' => 'https://pax.openperu.pe/{{1}}',
                    'resolver_key' => $variable,
                    'button_text' => [['language' => 'es', 'content' => self::BOTON['es']]],
                ]],
                'ejemplos' => array_map(
                    static fn (): array => ['guest_name' => 'Anna', 'locator' => 'H6Q49C', 'room_name' => 'Casita 3'],
                    self::BOTON,
                ),
            ]);
            $hechas[] = $plantilla;
        }

        if ($dryRun || $hechas === []) {
            $io->note($dryRun ? 'Simulación: no se ha escrito nada.' : 'Nada que hacer.');

            return Command::SUCCESS;
        }

        // El listener traduce el cuerpo en este flush; después, el botón a mano en cada idioma.
        $this->em->flush();

        foreach ($hechas as $plantilla) {
            $this->botonAMano($plantilla);
            $plantilla->setEjecutarTraduccion(false);
        }

        $this->em->flush();
        $io->success(sprintf('%d plantilla(s) con cuerpo de Meta. Falta subirlas: msg:meta:push <código> --todos', count($hechas)));

        return Command::SUCCESS;
    }

    /** El texto del botón en cada idioma, conservando el `origenHash` que dejó el traductor. */
    private function botonAMano(MessageTemplate $plantilla): void
    {
        $meta = $plantilla->getWhatsappMetaTmpl() ?? [];
        $hashes = [];

        foreach ($meta['buttons_map'][0]['button_text'] ?? [] as $texto) {
            $hashes[$texto['language'] ?? ''] = $texto['origenHash'] ?? null;
        }

        $traducidos = [];
        foreach (self::BOTON as $idioma => $texto) {
            $fila = ['language' => $idioma, 'content' => $texto];
            if (($hashes[$idioma] ?? null) !== null) {
                $fila['origenHash'] = $hashes[$idioma];
            }
            $traducidos[] = $fila;
        }

        $meta['buttons_map'][0]['button_text'] = $traducidos;
        $plantilla->setWhatsappMetaTmpl($meta);
    }
}
