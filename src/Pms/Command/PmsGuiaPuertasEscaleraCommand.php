<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsGuiaItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La escalera de «no puedo entrar» en las siete puertas y en las llaves (20/10/2026).
 *
 * ### El caso
 *
 * 19/10, 20:05. Los de la Casita 4, recién llegados y de noche: «hemos encontrado la puerta, pero
 * la llave no abre». **Estaban en la puerta de la Casita 3.** El agente no les preguntó si era su
 * puerta: escaló tres veces, la última «como emergencia», y les dijo que esperaran con la llave
 * metida. Diecisiete minutos forcejeando con la cerradura de María Capetillo, que al día siguiente
 * avisó de que «los de al lado abrieron nuestra puerta». Las cámaras lo aclararon: probó su llave
 * en la puerta equivocada y no entró. Las puertas no llevan número, a propósito, por seguridad: por
 * eso la puerta es lo PRIMERO que se confirma.
 *
 * ### El protocolo (Jorge, 20/10/2026)
 *
 * 1. Primer aviso de problema: TODO de una vez — su puerta con croquis y vídeo, su número de
 *    llave, el sentido de giro, y el truco si lo hay (Casita 4: el jalador; Casita 2: sólo la
 *    cerradura de abajo).
 * 2. Sólo en la 2 y la 4, si sigue sin abrir: reafirmar llave, giro y truco.
 * 3. Si aun así no abre: escalar como emergencia. Lo dispara solo `consultar_guia` al agotarse los
 *    pasos (`debes_escalar`), y `escalar_al_equipo` no deja avisar mientras quede un paso.
 *
 * «No encuentro mi llave» depende del MOMENTO, y ése lo da el código (`{{ momento_llave }}`,
 * `PmsGuiaContexto::momentoDeLaLlave()`): en su llegada —el día de entrada y hasta las 07:00 del
 * siguiente— se valida que de verdad no está antes de escalar; ya alojado, no estar en la caja es
 * lo normal y es el tema «Pérdida de llaves».
 *
 * Casita 5: todavía sin vídeo de ingreso; se añade cuando lo haya y se vuelve a correr.
 *
 * ⚠️ **Las llaves de emergencia NO están aquí, a propósito.** Viven en la caja fuerte de arriba y
 * el agente no lo sabe: un «no se lo digas al huésped» se incumple (`CLAUDE.md`, «la supresión no
 * funciona»), y aquí sería contarle a cualquiera dónde están las llaves de todas las casitas. El
 * agente escala; el dato lo tiene el equipo (`docs/PmsGuiaHuesped.md`).
 *
 * Sólo toca `agentePasos`, texto que lee el agente y no se traduce. Idempotente, con `--dry-run`.
 */
#[AsCommand(
    name: 'app:pms:guia:puertas-escalera',
    description: 'Escalera de «no puedo entrar» en las siete puertas y en las llaves (protocolo del 20/10/2026).',
)]
final class PmsGuiaPuertasEscaleraCommand extends Command
{
    /** Se repite al final de cada primer paso: lo que se le pide al huésped tras darle todo. */
    private const string CIERRE = 'Díselo todo junto y con seguridad, como la solución que suele ser, y pídele '
        . 'que te diga si así abre. Que no fuerce la llave.';

    /** Por qué la puerta va primero: las puertas no llevan número. */
    private const string SIN_NUMERO = 'Las puertas no llevan número, a propósito, y es fácil probar la llave en '
        . 'la de otra casita: por eso esto va PRIMERO.';

    /**
     * Dónde empieza, dentro de `agenteContenido`, el bloque «si no puede entrar» que pone este
     * comando. Lo de antes del marcador es del editor y no se toca; lo de después se reescribe.
     */
    private const string MARCADOR = "\n\n━━ SI NO PUEDE ENTRAR O NO ENCUENTRA SU LLAVE ━━\n";

    /** El último paso de las puertas sin truco: con todo dado, sólo queda avisar. */
    private const string ESCALAR_PUERTA = 'SI SIGUE SIN ABRIR después de haberle dado todo (su puerta, la llave '
        . '{{ numero }}, el giro): no se lo repitas. Avisa al equipo como EMERGENCIA con escalar_al_equipo —no '
        . 'puede entrar—, contando lo que ya comprobó, y dile que ya avisaste.';

    /** El último paso de las llaves: sólo cuando ha CONFIRMADO que no está, o que la perdió. */
    private const string ESCALAR_LLAVE = 'SI TE HA CONFIRMADO que su llave no está en la caja, o que la perdió y '
        . 'necesita entrar: ya no queda nada que probar. Ahora mismo: {{ momento_llave }} Avisa al equipo como '
        . 'EMERGENCIA con escalar_al_equipo y el motivo que toca: si está en su llegada, «su llave no estaba en la '
        . 'caja en el check-in: necesita la llave de emergencia»; si ya está alojado y la perdió, «perdió su llave '
        . 'y necesita entrar: darle la llave de emergencia y hacer la cobranza de la pérdida». Dile que ya avisaste.';

    /**
     * Fichas cuyo texto del agente se REESCRIBE entero (no lleva bloque añadido).
     *
     * «Pérdida de llaves» decía el costo del cerrajero de entrada, y el agente lo soltaba a quien
     * escribía «perdimos la llave» (ensayo del 21/10/2026). Con «SI PREGUNTA» delante lo soltó igual:
     * la supresión no funciona (`CLAUDE.md`). El costo sale del texto del agente y queda un puntero;
     * lo explica el equipo al cobrar. Y faltaba el motivo de escalada que decidió Jorge: darle la llave
     * de emergencia y hacer la cobranza.
     *
     * @var array<string, string>
     */
    private const array REESCRITAS = [
        'Perdida llaves (general)' => "Se entrega UN solo juego de llaves.\n\n"
            . 'SI LA HA PERDIDO Y NECESITA ENTRAR AHORA: avisa al equipo como EMERGENCIA con escalar_al_equipo y '
            . 'este motivo: «perdió su llave y necesita entrar: darle la llave de emergencia y hacer la cobranza de '
            . 'la pérdida». Dile que ya avisaste y que le escribirán por aquí. No le des instrucciones para entrar: '
            . "se las da el equipo.\n\n"
            . 'SI LA HA PERDIDO PERO NO NECESITA ENTRAR AHORA (está dentro, o todavía la busca): dile que lo pasas '
            . 'al equipo y avisa con escalar_al_equipo, SIN emergencia, con el motivo «perdió su llave: cambiar los '
            . "pines de la cerradura y hacer la cobranza de la pérdida».\n\n"
            // ⚠️ El costo NO va aquí, ni siquiera con «SI PREGUNTA»: en el ensayo lo soltó igual en el
            // primer mensaje. Se queda el puntero —sin él, lo NIEGA (`CLAUDE.md`)— y lo explica el equipo
            // al cobrar.
            . 'SI PREGUNTA SI TIENE COSTO O QUÉ PASA AHORA CON LA CERRADURA: dile que el equipo se lo detalla por '
            . 'aquí. No le digas que no tiene costo.',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    /**
     * Ficha → pasos. El paso 0 de la escalera es su `agenteContenido`; éstos son el 1 y el 2.
     *
     * @return array<string, list<string>>
     */
    private function pasos(): array
    {
        $llave = '2) Que use la llave número {{ numero }}: cada llave lleva su número en el llavero, y en la '
            . 'caja fuerte hay de otras casitas.';

        return [
            'Puerta (casa 1)' => [
                "SI NO ENCUENTRA SU PUERTA O DICE QUE NO ABRE, dale todo de una vez:\n"
                . '1) Que compruebe que está en SU puerta: la entrada está en la CALLE, no en el pasaje; es la '
                . 'puerta verde a nivel de calle, justo a la izquierda del portón. ' . self::SIN_NUMERO
                . " Mándale el croquis {{ croquis }} y el vídeo del recorrido {{ video_ingreso }}.\n"
                . $llave . "\n"
                . "3) Que gire la llave en sentido ANTIHORARIO.\n"
                . self::CIERRE,
            ],
            'Puerta (casa 2)' => [
                "SI NO ENCUENTRA SU PUERTA O DICE QUE NO ABRE, dale todo de una vez:\n"
                . '1) Que compruebe que está en SU puerta: la entrada está en la CALLE, no en el pasaje; es la '
                . 'puerta de madera verde a nivel de calle. ' . self::SIN_NUMERO
                . " Mándale el croquis {{ croquis }} y el vídeo del recorrido {{ video_ingreso }}.\n"
                . $llave . "\n"
                . '3) Que use SÓLO la cerradura de ABAJO, girando en sentido ANTIHORARIO. La de arriba está '
                . "abierta: si intenta con esa, no abre.\n"
                . self::CIERRE,
                'SI SIGUE SIN ABRIR: confírmale otra vez, con calma y en este orden, las tres cosas que lo '
                . 'resuelven casi siempre: que es la puerta de madera verde a nivel de calle; que la llave es la '
                . 'número {{ numero }}; y que usa SÓLO la cerradura de ABAJO, girando en sentido '
                . 'ANTIHORARIO. Pídele que te diga si así abre, y que no fuerce la llave.',
            ],
            'Puerta (casa 3)' => [
                "SI NO ENCUENTRA SU PUERTA O DICE QUE NO ABRE, dale todo de una vez:\n"
                . '1) Que compruebe que está en SU puerta: entrando al pasaje, es la SEGUNDA puerta, justo al pie '
                . 'de las gradas, de color verde. La primera a la izquierda es OTRA casita. ' . self::SIN_NUMERO
                . " Mándale el croquis {{ croquis }} y el vídeo del recorrido {{ video_ingreso }}.\n"
                . $llave . "\n"
                . "3) Que gire la llave en sentido HORARIO.\n"
                . self::CIERRE,
            ],
            'Puerta (casa 4)' => [
                "SI NO ENCUENTRA SU PUERTA O DICE QUE NO ABRE, QUE LA LLAVE SE ATASCA O NO GIRA, dale todo de una vez:\n"
                . '1) Que compruebe que está en SU puerta: entrando al pasaje, es la PRIMERA puerta a la izquierda, '
                . 'muy cerca de la caja fuerte, de color verde. La que está al pie de las gradas es OTRA casita. '
                . self::SIN_NUMERO
                . " Mándale el croquis {{ croquis }} y el vídeo del recorrido {{ video_ingreso }}.\n"
                . $llave . "\n"
                . '3) Que agarre el jalador de la puerta y tire de ella hacia sí con firmeza MIENTRAS gira la llave '
                . 'en sentido HORARIO. Sin tirar, la cerradura roza y la llave parece atascada; tirando del '
                . "jalador, gira. La fuerza va en el jalador, no en la llave.\n"
                . self::CIERRE,
                'SI SIGUE SIN ABRIR: confírmale otra vez, con calma y en este orden, las tres cosas que lo '
                . 'resuelven casi siempre: que es la PRIMERA puerta a la izquierda del pasaje (no la del pie de '
                . 'las gradas); que la llave es la número {{ numero }}; y que tire del jalador hacia sí '
                . 'MIENTRAS gira en sentido HORARIO. Pídele que te diga si así abre, y que no fuerce la llave.',
            ],
            'Puerta (casa 5)' => [
                "SI NO ENCUENTRA SU PUERTA O DICE QUE NO ABRE, dale todo de una vez:\n"
                . '1) Que compruebe que está en SU puerta: sube las gradas del final del pasaje y, al terminarlas, '
                . 'la tiene de frente, de color verde. ' . self::SIN_NUMERO
                . " Mándale el croquis {{ croquis }}.\n"
                . $llave . "\n"
                . "3) Que gire la llave en sentido ANTIHORARIO.\n"
                . self::CIERRE,
            ],
            'Puerta (casa 6)' => [
                "SI NO ENCUENTRA SU PUERTA O DICE QUE NO ABRE, dale todo de una vez:\n"
                . '1) Que compruebe que está en SU puerta: sube las gradas del final del pasaje, gira a la '
                . 'izquierda, luego a la derecha, de nuevo a la izquierda y sube las gradas; es la puerta verde '
                . 'de la DERECHA, y dentro, la del departamento es la ROJA a la izquierda. ' . self::SIN_NUMERO
                . " Mándale el croquis {{ croquis }} y el vídeo del recorrido {{ video_ingreso }}.\n"
                . $llave . "\n"
                . '3) Que gire la llave en sentido HORARIO. Sólo la puerta verde exterior está cerrada; la roja '
                . "interior está sin asegurar.\n"
                . self::CIERRE,
            ],
            'Puerta (casa 7)' => [
                "SI NO ENCUENTRA SU PUERTA O DICE QUE NO ABRE, dale todo de una vez:\n"
                . '1) Que compruebe que está en SU puerta: sube las gradas del final del pasaje y gira a la '
                . 'derecha; es la PRIMERA puerta del lado derecho, de color ROJO. ' . self::SIN_NUMERO
                . " Mándale el croquis {{ croquis }} y el vídeo del recorrido {{ video_ingreso }}.\n"
                . $llave . "\n"
                . "3) Que gire la llave en sentido HORARIO.\n"
                . self::CIERRE,
            ],
            // El momento lo pone el código (`PmsGuiaContexto::momentoDeLaLlave()`): validar que de verdad
            // no está sólo tiene sentido en la llegada (Jorge, 20/10/2026).
            'Llaves (general)' => [
                "SI DICE QUE NO ENCUENTRA SU LLAVE EN LA CAJA. Ahora mismo: {{ momento_llave }}\n"
                . '— Si ESTÁ EN SU LLEGADA: antes de nada, valida que de verdad no está. Que compruebe que '
                . 'abrió la caja de ABAJO, que es donde está su llave, y que mire TODAS las llaves: cada una '
                . 'lleva su número en el llavero y la suya es la número {{ numero }}. Si no consigue abrir la '
                . 'caja, mándale el vídeo {{ video_caja_llaves }}. Pídele que te CONFIRME si así la encuentra o '
                . 'si de verdad no está. Si te CONFIRMA que no está: avisa al equipo como emergencia con '
                . 'escalar_al_equipo, con este motivo: «su llave no estaba en la caja en el check-in: necesita '
                . "la llave de emergencia»; y dile que ya avisaste para que le den una llave.\n"
                . '— Si YA ESTÁ ALOJADO: que no esté en la caja es lo normal, porque la recogió al llegar. '
                . 'Pregúntale si la tiene él o alguien de su grupo. Si la ha PERDIDO y necesita entrar: avisa al '
                . 'equipo como emergencia con escalar_al_equipo, con este motivo: «perdió su llave y necesita '
                . 'entrar: darle la llave de emergencia y hacer la cobranza de la pérdida»; y cuéntale lo que '
                . "dice el tema «Pérdida de llaves» sobre el costo, sólo si lo pregunta.\n"
                . '— Si TODAVÍA NO ES SU DÍA DE ENTRADA: su llave se deja para su llegada; díselo así y avisa al '
                . 'equipo con escalar_al_equipo, sin marcar emergencia.',
            ],
        ];
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Sólo dice qué cambiaría.')] bool $dryRun = false,
    ): int {
        $repo = $this->em->getRepository(PmsGuiaItem::class);
        $cambios = 0;

        // ⚠️ El primer «no abre» va en el PASO 0 (`agenteContenido`), no en el 1. `consultar_guia`
        // sirve el 0 ante un «no funciona» a secas y sólo sube si el modelo dice que ya lo probó; en
        // el ensayo del 21/10/2026 lo dijo con la Casita 4 y no con la 2 ni la 3, que se quedaron
        // sólo con «compruebe la puerta». El protocolo pide TODO desde el primer problema.
        foreach ($this->pasos() as $ficha => $porPeldano) {
            $item = $repo->findOneBy(['nombreInterno' => $ficha]);

            if (!$item instanceof PmsGuiaItem) {
                $io->error(sprintf('No existe la ficha «%s». No se toca nada más.', $ficha));

                return Command::FAILURE;
            }

            $bloque = $porPeldano[0];
            $resto = array_slice($porPeldano, 1);
            // Cada ficha deja al menos UN paso: es lo que frena `escalar_al_equipo` hasta que se ha
            // dado todo (`EscaleraDeTemas::pendienteReciente()`). Sin pasos, escalaba al primer aviso.
            $pasos = $resto !== [] ? $resto : [$ficha === 'Llaves (general)' ? self::ESCALAR_LLAVE : self::ESCALAR_PUERTA];

            $base = (string) $item->getAgenteContenido();
            $corte = strpos($base, trim(self::MARCADOR));
            $base = rtrim($corte === false ? $base : substr($base, 0, $corte));
            $contenido = $base . self::MARCADOR . $bloque;

            if ($item->getAgentePasos() === $pasos && $item->getAgenteContenido() === $contenido) {
                $io->text(sprintf('= %s ya está.', $ficha));

                continue;
            }

            $io->section($ficha);
            $io->writeln('<fg=green>paso 0 (bloque):</> ' . $bloque);
            foreach ($pasos as $i => $paso) {
                $io->writeln(sprintf('<fg=green>paso %d:</> %s', $i + 1, $paso));
            }

            if (!$dryRun) {
                $item->setAgenteContenido($contenido);
                $item->setAgentePasos($pasos);
            }
            ++$cambios;
        }

        foreach (self::REESCRITAS as $ficha => $texto) {
            $item = $repo->findOneBy(['nombreInterno' => $ficha]);

            if (!$item instanceof PmsGuiaItem) {
                $io->error(sprintf('No existe la ficha «%s». No se toca nada más.', $ficha));

                return Command::FAILURE;
            }

            if ($item->getAgenteContenido() === $texto) {
                $io->text(sprintf('= %s ya está.', $ficha));

                continue;
            }

            $io->section($ficha);
            $io->writeln('<fg=red>- ' . (string) $item->getAgenteContenido() . '</>');
            $io->writeln('<fg=green>+ ' . $texto . '</>');

            if (!$dryRun) {
                $item->setAgenteContenido($texto);
            }
            ++$cambios;
        }

        if ($dryRun) {
            $io->note(sprintf('Simulación: %d ficha(s) cambiarían.', $cambios));

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d ficha(s) al día.', $cambios));

        return Command::SUCCESS;
    }
}
