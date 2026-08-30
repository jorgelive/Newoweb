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
 * Devuelve las tildes y las eñes que le faltan al español de la guía.
 *
 * ── Qué encontró ────────────────────────────────────────────────────────────
 * Buena parte de la guía se escribió sin diacríticos: 28 campos `agenteContenido` no tienen **ni
 * un acento** en 300–1300 caracteres —las siete duchas, las siete cocinas, los siete
 * televisores, `Pago`, `Reglas`, `Llaves`…— y catorce `descripcion` van a medias.
 *
 * Sólo lo sufre quien lee en **español**, y está comprobado por qué:
 *
 * - Las **traducciones salieron bien**: el traductor entendió «jabon liquido» y escribió «liquid
 *   hand soap», «savon liquide», «sabonete líquido». El original malo no las contaminó.
 * - El **agente tampoco lo copia literal**: reescribe. En la conversación de 66V8US contestó
 *   sobre la ducha con todas sus tildes leyendo un texto que no tiene ninguna.
 *
 * ⚠️ **Esto NO reacentúa prosa.** Es una lista **cerrada** de palabras que sólo tienen una
 * lectura posible. Lo que exige leer la frase —`abrela`, `todavia`, `decirselo`, y sobre todo
 * `aun`, `esta`, `el`, `si`, que cambian de significado con la tilde— se queda fuera a
 * propósito: una lista no puede decidir eso y equivocarse aquí escribe en la guía del huésped.
 *
 * ── Por qué comando y no migración ──────────────────────────────────────────
 * `descripcion` y `titulo` llevan `#[AutoTranslate]`. Un `UPDATE` en SQL se salta el listener.
 * Va por ORM y **en modo seguro** —sin `sobreescribirTraduccion`—, que sólo rellena los idiomas
 * vacíos: el español se corrige y los otros seis, que ya están bien, no se tocan ni se vuelven a
 * pagar. `agenteContenido` y `agentePasos` son texto plano y viajan en el mismo flush.
 *
 * **Idempotente**: la segunda pasada no encuentra nada porque las palabras ya llevan su tilde.
 *
 *   php bin/console pms:guia:tildes --dry-run
 *   php bin/console pms:guia:tildes
 */
#[AsCommand(
    name: 'pms:guia:tildes',
    description: 'Repone tildes y eñes en el español de la guía. Lista cerrada, idempotente.',
)]
final class PmsGuiaTildesCommand extends Command
{
    /**
     * Las que aparecen de verdad en la base, todas de lectura única.
     *
     * ⚠️ NO metas aquí `aun`, `esta`, `el`, `si`, `mi`, `tu` ni `solo`: la tilde les cambia el
     * significado y sólo la frase decide. Y `ano` tampoco —«ano» es una palabra— aunque hoy no
     * aparezca: el día que alguien escriba «ano nuevo», que lo arregle una persona.
     *
     * @var array<string, string>
     */
    private const array PALABRAS = [
        'mas' => 'más',
        'asi' => 'así',
        'jabon' => 'jabón',
        'liquido' => 'líquido',
        'higenico' => 'higiénico',
        'higienico' => 'higiénico',
        'dias' => 'días',
        'dia' => 'día',
        'aqui' => 'aquí',
        'banos' => 'baños',
        'bano' => 'baño',
        'numero' => 'número',
        'estan' => 'están',
        'ademas' => 'además',
        'direccion' => 'dirección',
        'deposito' => 'depósito',
        'facil' => 'fácil',
        'garantia' => 'garantía',
        'despues' => 'después',
        'cancelacion' => 'cancelación',
        'codigo' => 'código',
        'atencion' => 'atención',
        'telefono' => 'teléfono',
        'ninos' => 'niños',
        // «no hay danos» no es una errata de ortografía: sin la eñe, la frase deja de significar
        // lo que dice. Es la que más urgía de toda la lista.
        'danos' => 'daños',
        'tambien' => 'también',
        'ultimo' => 'último',
        'util' => 'útil',
        'minimo' => 'mínimo',

        // ── Segunda tanda ────────────────────────────────────────────────────
        // Salieron de sacar el vocabulario real de la guía en vez de adivinar qué faltaba. Son
        // igual de inequívocas que las de arriba —ninguna existe en español sin su tilde— y
        // varias estaban en el texto de la ducha, que es justo el que un huésped no encontró.
        'todavia' => 'todavía',
        'abrela' => 'ábrela',
        'reves' => 'revés',
        'unico' => 'único',
        'nombraselo' => 'nómbraselo',
        'confusion' => 'confusión',
        'decirselo' => 'decírselo',
        'fria' => 'fría',
        'continuacion' => 'continuación',
        'guia' => 'guía',
        'induccion' => 'inducción',
        'lavanderia' => 'lavandería',
        'deberia' => 'debería',
        'extravia' => 'extravía',
        'estadia' => 'estadía',
        'anticipacion' => 'anticipación',
        'numeros' => 'números',
        'codigos' => 'códigos',
        'envejeceria' => 'envejecería',
        'instruccion' => 'instrucción',
        'antelacion' => 'antelación',
        'publico' => 'público',
        'electronica' => 'electrónica',
        'excepcion' => 'excepción',
        'envia' => 'envía',
        'condicion' => 'condición',
        'peticion' => 'petición',
        'cortesia' => 'cortesía',
        'recomendacion' => 'recomendación',
        'magnetico' => 'magnético',
        'ahi' => 'ahí',

        // Tercera pasada del mismo método: se barre otra vez el vocabulario después de aplicar,
        // porque cada tanda destapa la siguiente. Aquí se agotó.
        //
        // 🔑 `codigos` NO hace falta aunque aparezca: sus dos apariciones están dentro de
        // `consultar_codigos`, el nombre de una skill. El guion bajo cuenta como `\w`, así que
        // el lookaround lo protege solo — y eso es exactamente lo que tiene que pasar.
        'huesped' => 'huésped',
        'huespedes' => 'huéspedes',
        'pidelo' => 'pídelo',
        'pidelos' => 'pídelos',
        'lavanderias' => 'lavanderías',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice qué cambiaría');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simular = (bool) $input->getOption('dry-run');

        $items = $this->em->getRepository(PmsGuiaItem::class)->findAll();
        $tocados = 0;
        $cambios = 0;

        foreach ($items as $item) {
            $enEste = 0;

            // ── Texto plano del agente ──
            $antes = $item->getAgenteContenido();
            $despues = $antes !== null ? $this->corregir($antes) : null;

            if ($despues !== $antes) {
                $enEste += $this->contar($antes ?? '', $despues ?? '');
                if (!$simular) {
                    $item->setAgenteContenido($despues);
                }
            }

            // ── Los peldaños, si los tiene ──
            $pasos = $item->getAgentePasos();
            $pasosNuevos = array_map(fn (string $p): string => $this->corregir($p), $pasos);

            if ($pasosNuevos !== $pasos) {
                $enEste += $this->contar(implode(' ', $pasos), implode(' ', $pasosNuevos));
                if (!$simular) {
                    $item->setAgentePasos($pasosNuevos);
                }
            }

            // ── Lo que lee el huésped: SÓLO la entrada española ──
            foreach (['Descripcion', 'Titulo'] as $campo) {
                $get = 'get' . $campo;
                $set = 'set' . $campo;
                /** @var list<array{language?: string, content?: string|null}> $i18n */
                $i18n = $item->$get() ?? [];
                $nuevo = $i18n;
                $huboCambio = false;

                foreach ($nuevo as $k => $entrada) {
                    if (strtolower((string) ($entrada['language'] ?? '')) !== 'es') {
                        continue;
                    }

                    $texto = (string) ($entrada['content'] ?? '');
                    $corregido = $this->corregir($texto);

                    if ($corregido !== $texto) {
                        $enEste += $this->contar($texto, $corregido);
                        $nuevo[$k]['content'] = $corregido;
                        $huboCambio = true;
                    }
                }

                if ($huboCambio && !$simular) {
                    $item->$set($nuevo);
                }
            }

            if ($enEste > 0) {
                ++$tocados;
                $cambios += $enEste;
                $io->writeln(sprintf('  <info>%-40s</info> %d', $item->getNombreInterno() ?? '(sin nombre)', $enEste));
            }
        }

        if ($cambios === 0) {
            $io->success('No falta ninguna tilde de la lista.');

            return Command::SUCCESS;
        }

        if ($simular) {
            $io->note(sprintf('Simulación: %d correcciones en %d ítems. Sin --dry-run se escriben.', $cambios, $tocados));

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d correcciones en %d ítems.', $cambios, $tocados));

        return Command::SUCCESS;
    }

    /**
     * Aplica la lista respetando las mayúsculas y **sin entrar en el marcado**.
     *
     * `descripcion` es HTML y lleva marcadores `{{ check_in }}`. Reemplazar a ciegas podría tocar
     * el valor de un atributo o el nombre de una variable, así que el texto se parte por
     * etiquetas y llaves y sólo se corrigen los trozos que el huésped ve.
     */
    private function corregir(string $texto): string
    {
        // Los trozos impares del split son las etiquetas y los marcadores: se dejan intactos.
        $partes = preg_split('/(<[^>]*>|\{\{[^}]*\}\})/u', $texto, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($partes === false) {
            return $texto;
        }

        foreach ($partes as $i => $parte) {
            if ($i % 2 === 1) {
                continue;
            }

            $partes[$i] = $this->corregirTrozo($parte);
        }

        return implode('', $partes);
    }

    private function corregirTrozo(string $trozo): string
    {
        foreach (self::PALABRAS as $mal => $bien) {
            // El lookaround incluye las vocales acentuadas y la eñe: sin eso, «mas» casaría
            // dentro de «más» ya corregido y el comando dejaría de ser idempotente.
            $patron = '/(?<![\w\x{00E1}\x{00E9}\x{00ED}\x{00F3}\x{00FA}\x{00F1}\x{00C1}\x{00C9}\x{00CD}\x{00D3}\x{00DA}\x{00D1}])'
                . preg_quote($mal, '/')
                . '(?![\w\x{00E1}\x{00E9}\x{00ED}\x{00F3}\x{00FA}\x{00F1}\x{00C1}\x{00C9}\x{00CD}\x{00D3}\x{00DA}\x{00D1}])/iu';

            $trozo = (string) preg_replace_callback(
                $patron,
                static function (array $m) use ($bien): string {
                    $original = $m[0];

                    // MAYÚSCULAS enteras y Capitalizada se conservan; el resto va en minúscula.
                    if ($original === mb_strtoupper($original)) {
                        return mb_strtoupper($bien);
                    }

                    if (mb_substr($original, 0, 1) === mb_strtoupper(mb_substr($original, 0, 1))) {
                        return mb_strtoupper(mb_substr($bien, 0, 1)) . mb_substr($bien, 1);
                    }

                    return $bien;
                },
                $trozo,
            );
        }

        return $trozo;
    }

    /** Cuántas palabras cambiaron entre las dos versiones. */
    private function contar(string $antes, string $despues): int
    {
        $n = 0;

        foreach (self::PALABRAS as $mal => $bien) {
            $patron = '/(?<![\w\x{00E1}\x{00E9}\x{00ED}\x{00F3}\x{00FA}\x{00F1}])'
                . preg_quote($mal, '/')
                . '(?![\w\x{00E1}\x{00E9}\x{00ED}\x{00F3}\x{00FA}\x{00F1}])/iu';

            $n += preg_match_all($patron, $antes) ?: 0;
            $n -= preg_match_all($patron, $despues) ?: 0;
        }

        return max(0, $n);
    }
}
