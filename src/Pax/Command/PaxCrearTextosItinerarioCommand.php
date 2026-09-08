<?php

declare(strict_types=1);

namespace App\Pax\Command;

use App\Pax\Entity\UiI18n;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La cadena del botón de imprimir de la guía del cliente.
 *
 * ── Por qué un comando y no una migración ───────────────────────────────────
 * Misma razón que {@see PaxCrearTextosCobroCommand}: `UiI18n::$contenido` lleva
 * `#[AutoTranslate(sourceLanguage: 'es')]` y ese listener cuelga de `prePersist`. Un `INSERT`
 * en SQL se lo salta y la cadena nacería sólo en español.
 *
 * ⚠️ **Y aquí eso se ve, no se intuye.** El botón se probó sobre una propuesta en inglés y
 * salió «IMPRIMIR» en medio de «DETAILS» y «SUMMARY»: el respaldo del `||` no falla —devuelve
 * el castellano y la pantalla se pinta entera—, así que un hueco del diccionario sólo se
 * descubre mirando. Es la misma familia de fallo mudo que persigue este proyecto.
 *
 * Idempotente por la clave natural: si ya existe no se toca, ni para reescribir su español.
 *
 *   php bin/console pax:textos:itinerario --dry-run
 *   php bin/console pax:textos:itinerario
 */
#[AsCommand(
    name: 'pax:textos:itinerario',
    description: 'Crea las cadenas UiI18n de la guía del itinerario que faltan. Idempotente.',
)]
final class PaxCrearTextosItinerarioCommand extends Command
{
    private const string SCOPE = 'cotizacion';

    /**
     * ⚠️ **Una sola clave para el botón y su tooltip.** El botón enseña «Imprimir» y el `title`
     * explica que también sirve para guardar en PDF; podrían ser dos cadenas, pero la segunda
     * sólo la lee quien deja el ratón encima —nadie en un móvil, que es donde más se usa— y
     * cada clave de más es una fila más que traducir a siete idiomas para siempre.
     *
     * @var array<string, string>
     */
    private const array TEXTOS = [
        'cot_imprimir' => 'Imprimir',
        // «Lo tuyo»: con quién comparte cada subgrupo. Ver CotizacionFile::$miIdentidad.
        'cot_personas' => 'personas',
        'cot_tu' => 'tú',
        // El rol de quien responde del grupo. En VERBO y en minúscula —«coordina», no
        // «COORDINADOR»—: es un dato de servicio para saber a quién buscar, no un galón, y al
        // lado de un nombre un sustantivo en mayúsculas se lee como un cargo.
        // ⚠️ El PLURAL de noche no existía y el singular sí, porque hasta el 07/09/2026 la única
        // frase que las nombraba era «Noche 2/4» — siempre en singular. Con «5 días, 4 noches» en
        // la cabecera hace falta el plural, o sale en español en los siete idiomas.
        'cot_noches' => 'noches',
        'cot_rol_coordinador' => 'coordina',
        'cot_rol_supervisor' => 'supervisa',
        'cot_ver_mis_grupos' => 'Ver mis grupos',
        'cot_ocultar_mis_grupos' => 'Ocultar mis grupos',

        // 🔥 **La puerta de entrada estaba entera en castellano.** Estas seis se escribieron con su
        // respaldo `||` y nunca se sembraron: un pasajero extranjero se topaba con el formulario
        // de identificación —lo PRIMERO que ve— en un idioma que no es el suyo, y sin un error que
        // lo denunciara. Se descubrió cruzando las 183 claves que usa `pax` contra las 233 de la
        // tabla; el respaldo hace que un hueco sólo se vea mirando.
        'cot_lo_tuyo' => 'Lo tuyo',
        'cot_no_soy_yo' => 'No soy yo',
        'cot_su_reserva' => 'Su reserva:',
        'cot_identificate_titulo' => 'Identifícate para ver tu viaje',
        'cot_identificate_motivo' => 'Esta propuesta lleva datos de cada persona —tu vuelo, tus horarios—, '
            . 'así que te pedimos dos datos para enseñarte los tuyos.',
        'cot_identificate_documento' => 'Número de documento',
        'cot_identificate_nacimiento' => 'Fecha de nacimiento',
        'cot_identificate_entrar' => 'Ver mi viaje',

        // 🔥 **Lo que el pasajero TIENE que hacer, y en su idioma.** Estas seis nacieron con su
        // respaldo `||` el 08/09/2026 y se siembran el mismo día, que es la lección de las de
        // arriba: un respaldo en castellano no falla, sólo deja al extranjero delante de un texto
        // que no entiende — y como no hay error, se descubre cruzando claves a mano.
        //
        // ⚠️ Del aviso de las tarjetas depende que llegue al aeropuerto con el boarding pass ya
        // descargado. Es la frase de esta pantalla que más caro sale traducir tarde.
        'cot_mis_tarjetas' => 'Tus tarjetas de embarque',
        'cot_mis_tarjetas_aviso' => 'Ábrelas y enséñalas en el control y en la puerta de embarque. '
            . 'Guárdalas en tu móvil antes de salir, por si no hay señal.',
        'cot_mis_documentos' => 'Tus documentos',
        'cot_mis_documentos_motivo' => 'Los necesitamos para emitir tus boletos y para el control migratorio. '
            . 'Sólo los ve el equipo que arma tu viaje, y se borran un mes después de tu regreso.',
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

        $repo = $this->em->getRepository(UiI18n::class);
        $creadas = [];
        $existentes = [];

        foreach (self::TEXTOS as $clave => $es) {
            if ($repo->find($clave) !== null) {
                $existentes[] = $clave;
                continue;
            }

            $creadas[] = [$clave, $es];

            if ($simular) {
                continue;
            }

            $texto = (new UiI18n())
                ->setId($clave)
                ->setScope(self::SCOPE)
                // El listener rellena los otros idiomas al persistir; aquí sólo va el origen.
                ->setContenido([['language' => 'es', 'content' => $es]]);

            $this->em->persist($texto);
        }

        if ($existentes !== []) {
            $io->writeln(sprintf('Ya existían (no se tocan): %s', implode(', ', $existentes)));
        }

        if ($creadas === []) {
            $io->success('No falta ninguna cadena.');

            return Command::SUCCESS;
        }

        $io->table(['Clave', 'Español'], $creadas);

        if ($simular) {
            $io->note(sprintf('Simulación: se crearían %d. Sin --dry-run se escriben y se traducen.', count($creadas)));

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d cadena(s) creada(s) y encolada(s) para traducir.', count($creadas)));

        return Command::SUCCESS;
    }
}
