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
 * «Ábrela por completo… y si sale demasiado caliente, ábrela todavía más» (17/10/2026).
 *
 * Imposible de seguir, y lo vio Jorge: abierta del todo no se puede abrir más. Lo que la frase
 * quería decir es lo contrario de lo que el huésped haría por instinto: si sale muy caliente, **no
 * se cierra la caliente** —con menos chorro estos calentadores de paso calientan MÁS, y pasados los
 * 80 grados se apagan—, **se añade fría**: abrir un poco la manija fría, o girar la palanca un poco
 * hacia el lado frío.
 *
 * Estaba en las siete duchas, en la app y en lo que cuenta el asistente. De paso:
 * - la app de la casa 6 no tenía el aviso de no cerrar el agua, que sí tenía su asistente;
 * - el texto del asistente de la 6 y la 7 iba sin tildes («NO esta», «Preguntale en cual esta»).
 *
 * ── Reemplazos EXACTOS, no reescritura ──────────────────────────────────────
 * Cada tramo se cambia sólo si está tal cual; si no —alguien lo editó—, esa ducha no se toca y
 * se avisa. Lo demás (tildes, el párrafo del agua que no se acaba, los pasos) queda intacto. Es la
 * lección de `corregir-duchas`, que reescribía entero y ya no debe relanzarse.
 *
 * Idempotente: un tramo ya cambiado se reconoce y se salta. Por comando y no por SQL: la app está
 * en siete idiomas y el traductor rehace los otros seis al cambiar el español.
 */
#[AsCommand(
    name: 'app:pms:guia:duchas-regular-con-fria',
    description: 'Corrige «ábrela todavía más» en las siete duchas: si sale muy caliente, se añade fría.',
    hidden: true,
)]
final class PmsGuiaDuchasRegularConFriaCommand extends Command
{
    /**
     * [casa, superficie, texto actual exacto, texto nuevo]. `app` es la fila en español de
     * `descripcion`; `agente` es `agente_contenido`.
     *
     * @var list<array{0: int, 1: 'app'|'agente', 2: string, 3: string}>
     */
    private const array REEMPLAZOS = [
        [1, 'app',
            '<p>👉 Ábrela <strong>por completo</strong> y espera unos segundos. Si sale demasiado caliente, ábrela <strong>todavía más</strong>: en estos calentadores a gas, cuanta más agua pasa, menos caliente sale.</p><p>⚠️ No cierres la caliente para bajar la temperatura: al reducir el chorro el agua sale más caliente todavía y el calentador puede apagarse solo por seguridad. Regula con la fría si hace falta.</p>',
            '<p>👉 Ábrela <strong>por completo</strong> y espera unos segundos: estos calentadores a gas necesitan un chorro fuerte para encenderse.</p><p>⚠️ Si sale demasiado caliente, <strong>no cierres la caliente</strong>: con menos chorro el agua sale todavía más caliente y el calentador puede apagarse solo por seguridad. Abre <strong>poco a poco la fría</strong> hasta que esté a tu gusto.</p>'],
        [1, 'agente',
            'Ábrela del todo y espera unos segundos a que salga caliente. Si sale DEMASIADO caliente,
ábrela todavía más: en estos calentadores a gas, cuanta más agua pasa, menos caliente sale.
Es al revés de lo que uno espera, y conviene decírselo tal cual.

Solo si aun así sigue muy caliente, que abra poco a poco la otra manija, la fría.

NO debe cerrar la caliente para bajar la temperatura: al reducir el chorro el agua sale más
caliente todavía, y si pasa de 80 grados el calentador se apaga solo por seguridad. Por eso
se recomienda dejarla siempre con un chorro medio o alto.

',
            'Que la abra del todo y espere unos segundos: estos calentadores a gas necesitan un chorro
fuerte para encenderse.

Si sale DEMASIADO caliente, que NO cierre la caliente: en estos calentadores, cuanta menos agua
pasa, más caliente sale —es al revés de lo que uno espera, y conviene decírselo tal cual—, y
si pasa de 80 grados el calentador se apaga solo por seguridad. Lo que tiene que hacer es
abrir poco a poco la otra manija, la fría, dejando la caliente abierta del todo.

'],
        [2, 'app',
            '<p>👉 Ábrela <strong>por completo</strong> y espera unos segundos. Si sale demasiado caliente, ábrela <strong>todavía más</strong>: en estos calentadores a gas, cuanta más agua pasa, menos caliente sale.</p><p>⚠️ No cierres la caliente para bajar la temperatura: al reducir el chorro el agua sale más caliente todavía y el calentador puede apagarse solo por seguridad. Regula con la fría si hace falta.</p>',
            '<p>👉 Ábrela <strong>por completo</strong> y espera unos segundos: estos calentadores a gas necesitan un chorro fuerte para encenderse.</p><p>⚠️ Si sale demasiado caliente, <strong>no cierres la caliente</strong>: con menos chorro el agua sale todavía más caliente y el calentador puede apagarse solo por seguridad. Abre <strong>poco a poco la fría</strong> hasta que esté a tu gusto.</p>'],
        [2, 'agente',
            'Ábrela del todo y espera unos segundos a que salga caliente. Si sale DEMASIADO caliente,
ábrela todavía más: en estos calentadores a gas, cuanta más agua pasa, menos caliente sale.
Es al revés de lo que uno espera, y conviene decírselo tal cual.

Solo si aun así sigue muy caliente, que abra poco a poco la otra manija, la fría.

NO debe cerrar la caliente para bajar la temperatura: al reducir el chorro el agua sale más
caliente todavía, y si pasa de 80 grados el calentador se apaga solo por seguridad. Por eso
se recomienda dejarla siempre con un chorro medio o alto.

',
            'Que la abra del todo y espere unos segundos: estos calentadores a gas necesitan un chorro
fuerte para encenderse.

Si sale DEMASIADO caliente, que NO cierre la caliente: en estos calentadores, cuanta menos agua
pasa, más caliente sale —es al revés de lo que uno espera, y conviene decírselo tal cual—, y
si pasa de 80 grados el calentador se apaga solo por seguridad. Lo que tiene que hacer es
abrir poco a poco la otra manija, la fría, dejando la caliente abierta del todo.

'],
        [3, 'app',
            '<p>👉 Ábrela <strong>por completo</strong> y espera unos segundos. Si sale demasiado caliente, ábrela <strong>todavía más</strong>: en estos calentadores a gas, cuanta más agua pasa, menos caliente sale.</p><p>⚠️ No cierres la caliente para bajar la temperatura: al reducir el chorro el agua sale más caliente todavía y el calentador puede apagarse solo por seguridad. Regula con la fría si hace falta.</p>',
            '<p>👉 Ábrela <strong>por completo</strong> y espera unos segundos: estos calentadores a gas necesitan un chorro fuerte para encenderse.</p><p>⚠️ Si sale demasiado caliente, <strong>no cierres la caliente</strong>: con menos chorro el agua sale todavía más caliente y el calentador puede apagarse solo por seguridad. Abre <strong>poco a poco la fría</strong> hasta que esté a tu gusto.</p>'],
        [3, 'agente',
            'Ábrela del todo y espera unos segundos a que salga caliente. Si sale DEMASIADO caliente,
ábrela todavía más: en estos calentadores a gas, cuanta más agua pasa, menos caliente sale.
Es al revés de lo que uno espera, y conviene decírselo tal cual.

Solo si aun así sigue muy caliente, que abra poco a poco la otra manija, la fría.

NO debe cerrar la caliente para bajar la temperatura: al reducir el chorro el agua sale más
caliente todavía, y si pasa de 80 grados el calentador se apaga solo por seguridad. Por eso
se recomienda dejarla siempre con un chorro medio o alto.

',
            'Que la abra del todo y espere unos segundos: estos calentadores a gas necesitan un chorro
fuerte para encenderse.

Si sale DEMASIADO caliente, que NO cierre la caliente: en estos calentadores, cuanta menos agua
pasa, más caliente sale —es al revés de lo que uno espera, y conviene decírselo tal cual—, y
si pasa de 80 grados el calentador se apaga solo por seguridad. Lo que tiene que hacer es
abrir poco a poco la otra manija, la fría, dejando la caliente abierta del todo.

'],
        [4, 'app',
            '<p>👉 Ábrela <strong>por completo</strong> y espera unos segundos. Si sale demasiado caliente, ábrela <strong>todavía más</strong>: en estos calentadores a gas, cuanta más agua pasa, menos caliente sale.</p><p>⚠️ No cierres la caliente para bajar la temperatura: al reducir el chorro el agua sale más caliente todavía y el calentador puede apagarse solo por seguridad. Regula con la fría si hace falta.</p>',
            '<p>👉 Ábrela <strong>por completo</strong> y espera unos segundos: estos calentadores a gas necesitan un chorro fuerte para encenderse.</p><p>⚠️ Si sale demasiado caliente, <strong>no cierres la caliente</strong>: con menos chorro el agua sale todavía más caliente y el calentador puede apagarse solo por seguridad. Abre <strong>poco a poco la fría</strong> hasta que esté a tu gusto.</p>'],
        [4, 'agente',
            'Ábrela del todo y espera unos segundos a que salga caliente. Si sale DEMASIADO caliente,
ábrela todavía más: en estos calentadores a gas, cuanta más agua pasa, menos caliente sale.
Es al revés de lo que uno espera, y conviene decírselo tal cual.

Solo si aun así sigue muy caliente, que abra poco a poco la otra manija, la fría.

NO debe cerrar la caliente para bajar la temperatura: al reducir el chorro el agua sale más
caliente todavía, y si pasa de 80 grados el calentador se apaga solo por seguridad. Por eso
se recomienda dejarla siempre con un chorro medio o alto.

',
            'Que la abra del todo y espere unos segundos: estos calentadores a gas necesitan un chorro
fuerte para encenderse.

Si sale DEMASIADO caliente, que NO cierre la caliente: en estos calentadores, cuanta menos agua
pasa, más caliente sale —es al revés de lo que uno espera, y conviene decírselo tal cual—, y
si pasa de 80 grados el calentador se apaga solo por seguridad. Lo que tiene que hacer es
abrir poco a poco la otra manija, la fría, dejando la caliente abierta del todo.

'],
        [5, 'app',
            '<p>👉 Ábrela <strong>por completo</strong> y espera unos segundos. Si sale demasiado caliente, ábrela <strong>todavía más</strong>: en estos calentadores a gas, cuanta más agua pasa, menos caliente sale.</p><p>⚠️ No cierres la caliente para bajar la temperatura: al reducir el chorro el agua sale más caliente todavía y el calentador puede apagarse solo por seguridad. Regula con la fría si hace falta.</p>',
            '<p>👉 Ábrela <strong>por completo</strong> y espera unos segundos: estos calentadores a gas necesitan un chorro fuerte para encenderse.</p><p>⚠️ Si sale demasiado caliente, <strong>no cierres la caliente</strong>: con menos chorro el agua sale todavía más caliente y el calentador puede apagarse solo por seguridad. Abre <strong>poco a poco la fría</strong> hasta que esté a tu gusto.</p>'],
        [5, 'agente',
            'Ábrela del todo y espera unos segundos a que salga caliente. Si sale DEMASIADO caliente,
ábrela todavía más: en estos calentadores a gas, cuanta más agua pasa, menos caliente sale.
Es al revés de lo que uno espera, y conviene decírselo tal cual.

Solo si aun así sigue muy caliente, que abra poco a poco la otra manija, la fría.

NO debe cerrar la caliente para bajar la temperatura: al reducir el chorro el agua sale más
caliente todavía, y si pasa de 80 grados el calentador se apaga solo por seguridad. Por eso
se recomienda dejarla siempre con un chorro medio o alto.

',
            'Que la abra del todo y espere unos segundos: estos calentadores a gas necesitan un chorro
fuerte para encenderse.

Si sale DEMASIADO caliente, que NO cierre la caliente: en estos calentadores, cuanta menos agua
pasa, más caliente sale —es al revés de lo que uno espera, y conviene decírselo tal cual—, y
si pasa de 80 grados el calentador se apaga solo por seguridad. Lo que tiene que hacer es
abrir poco a poco la otra manija, la fría, dejando la caliente abierta del todo.

'],
        [6, 'app',
            '<p>👉 Lleva la palanca <strong>del todo</strong> hacia ese lado y espera unos segundos. Si sale demasiado caliente, ábrela todavía más: cuanta más agua pasa, menos caliente sale.</p>',
            '<p>👉 Lleva la palanca <strong>del todo</strong> hacia ese lado y espera unos segundos: estos calentadores a gas necesitan un chorro fuerte para encenderse.</p><p>⚠️ Si sale demasiado caliente, <strong>no cierres el agua</strong>: con menos chorro sale todavía más caliente y el calentador puede apagarse solo por seguridad. Gira la palanca <strong>un poco hacia el lado frío</strong> hasta que esté a tu gusto.</p>'],
        [6, 'agente',
            'Esta casita tiene dos baños y los dos son de una sola palanca (monocomando), pero el agua
caliente NO esta del mismo lado. Preguntale en cual esta antes de darle la instrucción:

- Baño de ABAJO: el caliente es hacia la DERECHA.
- Baño de ARRIBA: el caliente es hacia la IZQUIERDA.

En los dos, llevar la palanca del todo hacia ese lado y esperar unos segundos. Si sale
DEMASIADO caliente, hay que abrirla todavía más: en estos calentadores a gas, cuanta más
agua pasa, menos caliente sale. Es al revés de lo que uno espera.

NO debe cerrar el paso para bajar la temperatura: al reducir el chorro el agua sale más
caliente todavía, y si pasa de 80 grados el calentador se apaga solo por seguridad.

',
            'Esta casita tiene dos baños y los dos son de una sola palanca (monocomando), pero el agua
caliente NO está del mismo lado. Pregúntale en cuál está antes de darle la instrucción:

- Baño de ABAJO: el caliente es hacia la DERECHA.
- Baño de ARRIBA: el caliente es hacia la IZQUIERDA.

En los dos, llevar la palanca del todo hacia ese lado y esperar unos segundos: estos
calentadores a gas necesitan un chorro fuerte para encenderse.

Si sale DEMASIADO caliente, que NO cierre el agua: cuanta menos agua pasa, más caliente sale
—es al revés de lo que uno espera—, y si pasa de 80 grados el calentador se apaga solo por
seguridad. Lo que tiene que hacer es girar la palanca un poco hacia el lado frío.

'],
        [7, 'app',
            '<ul><li>Baño con <strong>una sola palanca</strong>: llévala hacia la izquierda.</li><li>Baño de <strong>dos manijas</strong>: la caliente es la izquierda.</li></ul><p>👉 Abre <strong>del todo</strong> y espera unos segundos. Si sale demasiado caliente, abre todavía más: cuanta más agua pasa, menos caliente sale.</p>',
            '<ul><li>Baño con <strong>una sola palanca</strong>: llévala del todo hacia la izquierda.</li><li>Baño de <strong>dos manijas</strong>: abre del todo la izquierda, que es la caliente.</li></ul><p>👉 Espera unos segundos: estos calentadores a gas necesitan un chorro fuerte para encenderse.</p><p>⚠️ Si sale demasiado caliente, <strong>no cierres el agua caliente</strong>: con menos chorro sale todavía más caliente y el calentador puede apagarse solo por seguridad. Añade <strong>fría poco a poco</strong>: gira la palanca un poco hacia la derecha, o abre un poco la manija fría.</p>'],
        [7, 'agente',
            'Esta casita tiene dos baños con grifos distintos, pero en los DOS el agua caliente esta a
la izquierda, así que no hace falta preguntarle en cual esta:

- Baño de una sola palanca: llevarla hacia la izquierda.
- Baño de dos manijas: la caliente es la izquierda.

En los dos, abrir del todo y esperar unos segundos. Si sale DEMASIADO caliente, hay que
abrir todavía más: en estos calentadores a gas, cuanta más agua pasa, menos caliente sale.
Es al revés de lo que uno espera.

NO debe cerrar el paso para bajar la temperatura: al reducir el chorro el agua sale más
caliente todavía, y si pasa de 80 grados el calentador se apaga solo por seguridad.

',
            'Esta casita tiene dos baños con grifos distintos, pero en los DOS el agua caliente está a
la izquierda, así que no hace falta preguntarle en cuál está:

- Baño de una sola palanca: llevarla del todo hacia la izquierda.
- Baño de dos manijas: abrir del todo la izquierda, que es la caliente.

En los dos, esperar unos segundos: estos calentadores a gas necesitan un chorro fuerte para
encenderse.

Si sale DEMASIADO caliente, que NO cierre el agua caliente: cuanta menos agua pasa, más
caliente sale —es al revés de lo que uno espera—, y si pasa de 80 grados el calentador se
apaga solo por seguridad. Lo que tiene que hacer es añadir fría poco a poco: girar la palanca
un poco hacia la derecha, o abrir un poco la manija fría.

'],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');
        $cambios = 0;
        $avisos = 0;

        foreach (self::REEMPLAZOS as [$casa, $superficie, $viejo, $nuevo]) {
            $nombre = sprintf('Ducha (casa %d)', $casa);
            $item = $this->em->getRepository(PmsGuiaItem::class)->findOneBy(['nombreInterno' => $nombre]);

            if (!$item instanceof PmsGuiaItem) {
                $io->warning(sprintf('No encuentro «%s».', $nombre));
                ++$avisos;
                continue;
            }

            $esAgente = $superficie === 'agente';
            $actual = $esAgente ? (string) $item->getAgenteContenido() : $this->espanol($item);

            if (str_contains($actual, $nuevo)) {
                $io->text(sprintf('· %s, %s — ya corregido', $nombre, $superficie));
                continue;
            }

            if (!str_contains($actual, $viejo)) {
                $io->warning(sprintf('%s, %s: el texto no es el esperado (¿editado a mano?). Sin tocar.', $nombre, $superficie));
                ++$avisos;
                continue;
            }

            $corregido = str_replace($viejo, $nuevo, $actual);

            if ($esAgente) {
                $item->setAgenteContenido($corregido);
            } else {
                $this->ponerEspanol($item, $corregido);
            }

            ++$cambios;
            $io->text(sprintf('+ %s, %s', $nombre, $esAgente ? 'asistente' : 'app (7 idiomas)'));
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->success(sprintf('%d tramo(s) %s; %d aviso(s).', $cambios, $seco ? 'se corregirían' : 'corregidos', $avisos));

        return $avisos > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function espanol(PmsGuiaItem $item): string
    {
        foreach ($item->getDescripcion() ?? [] as $fila) {
            if (($fila['language'] ?? null) === 'es') {
                return (string) ($fila['content'] ?? '');
            }
        }

        return '';
    }

    /** Sólo la fila en español: las otras seis llevan la huella del texto viejo y se rehacen. */
    private function ponerEspanol(PmsGuiaItem $item, string $contenido): void
    {
        $filas = $item->getDescripcion() ?? [];

        foreach ($filas as $i => $fila) {
            if (($fila['language'] ?? null) === 'es') {
                $filas[$i]['content'] = $contenido;
            }
        }

        $item->setDescripcion($filas);
    }
}
