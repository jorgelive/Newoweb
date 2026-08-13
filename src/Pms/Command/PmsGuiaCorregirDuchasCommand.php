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
 * Corrige de qué lado sale el agua caliente en cada ducha.
 *
 * ### El fallo, que venía de antes
 *
 * El cuerpo **publicado** decía «manija derecha» en las siete casitas; el `agente_contenido` decía
 * «izquierda» en las siete. Se contradecían entre sí, y ninguno era cierto para todas: el lado
 * **varía por casa y por baño**. No se veía porque cada superficie era coherente consigo misma.
 *
 * Es de los errores más caros que puede tener esta guía: el huésped lo lee a las once de la noche
 * con el agua fría.
 *
 * ### La realidad, confirmada por el negocio
 *
 * ```
 *   casas 1, 3, 4, 5   un baño, dos manijas              caliente IZQUIERDA
 *   casa 2             un baño, dos manijas              caliente DERECHA   ← la excepción
 *   casa 6             DOS baños, ambos monocomando      abajo DERECHA · arriba IZQUIERDA
 *   casa 7             DOS baños: palanca y dos manijas  ambos IZQUIERDA
 * ```
 *
 * ⚠️ El texto de la casa 6 estaba **copiado del de la 7**: describía un baño de dos manijas que
 * ahí no existe. Y «abajo / arriba» sustituye a «Baño A / Baño B», para que el huésped sepa de
 * cuál le hablan sin tener que ir a mirar el grifo.
 *
 * ### Por qué comando y no migración
 *
 * Toca `descripcion`, que está publicada **en siete idiomas**. Un `UPDATE` en SQL las borraría
 * todas —o peor, dejaría traducciones que siguen diciendo el lado equivocado—. Por el ORM,
 * `AutoTranslationEventListener` las regenera solo. Mismo motivo que
 * {@see PmsGuiaCrearTelevisorCommand}.
 *
 * Es idempotente: escribe siempre lo mismo, así que se puede relanzar.
 */
#[AsCommand(
    name: 'app:pms:guia:corregir-duchas',
    description: 'Corrige el lado del agua caliente en las siete duchas, en los tres sitios a la vez.'
)]
final class PmsGuiaCorregirDuchasCommand extends Command
{
    /** Las casas de un solo baño con grifo de dos manijas: casa => lado del agua caliente. */
    private const array UN_BANO = [
        1 => 'izquierda',
        2 => 'derecha',
        3 => 'izquierda',
        4 => 'izquierda',
        5 => 'izquierda',
    ];

    /** Dónde la ducha y la cocina comparten balón de gas. */
    private const array GAS_COMPARTIDO = [2, 3, 4, 7];

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
        $tocados = 0;

        foreach (self::UN_BANO as $casa => $lado) {
            $tocados += $this->aplicar(
                $io,
                $seco,
                $casa,
                $this->publicadoUnBano($lado),
                $this->agenteUnBano($lado),
                [$this->pasoUnBano($lado), $this->hornilla($casa)],
                sprintf('un baño · caliente %s', $lado)
            );
        }

        $tocados += $this->aplicar(
            $io,
            $seco,
            6,
            $this->publicadoCasa6(),
            $this->agenteCasa6(),
            [$this->pasoCasa6()],
            'dos baños monocomando · abajo derecha, arriba izquierda'
        );

        $tocados += $this->aplicar(
            $io,
            $seco,
            7,
            $this->publicadoCasa7(),
            $this->agenteCasa7(),
            [$this->pasoCasa7(), $this->hornilla(7)],
            'dos baños distintos · los dos izquierda'
        );

        if (!$seco) {
            $this->em->flush();
        }

        $io->success(sprintf('%d ducha(s) %s.', $tocados, $seco ? 'se corregirían' : 'corregidas'));

        return Command::SUCCESS;
    }

    /** @param list<string> $pasos */
    private function aplicar(
        SymfonyStyle $io,
        bool $seco,
        int $casa,
        string $publicado,
        string $agente,
        array $pasos,
        string $resumen
    ): int {
        $nombre = sprintf('Ducha (casa %d)', $casa);
        $item = $this->em->getRepository(PmsGuiaItem::class)->findOneBy(['nombreInterno' => $nombre]);

        if ($item === null) {
            $io->warning(sprintf('No encuentro «%s».', $nombre));

            return 0;
        }

        $io->text(sprintf('· %s — %s', $nombre, $resumen));

        if ($seco) {
            return 1;
        }

        // `setAgentePasos()` compacta los vacíos, así que el hueco de las casas sin gas
        // compartido no deja un peldaño mudo.
        $item->setDescripcion([['language' => 'es', 'content' => $publicado]])
            ->setAgenteContenido($this->limpiar($agente))
            ->setAgentePasos(array_map($this->limpiar(...), $pasos));

        return 1;
    }

    private function publicadoUnBano(string $lado): string
    {
        return sprintf(
            '<p>La <strong>manija %s</strong> controla el <strong>agua caliente</strong>.</p>'
            . '<p>👉 Ábrela <strong>por completo</strong> y espera unos segundos. Si sale demasiado '
            . 'caliente, ábrela <strong>todavía más</strong>: en estos calentadores a gas, cuanta '
            . 'más agua pasa, menos caliente sale.</p>'
            . '<p>⚠️ No cierres la caliente para bajar la temperatura: al reducir el chorro el agua '
            . 'sale más caliente todavía y el calentador puede apagarse solo por seguridad. Regula '
            . 'con la fría si hace falta.</p>',
            $lado
        );
    }

    private function agenteUnBano(string $lado): string
    {
        return sprintf(
            <<<'TXT'
            La manija %s es la del agua caliente.

            Abrela del todo y espera unos segundos a que salga caliente. Si sale DEMASIADO caliente,
            abrela todavia mas: en estos calentadores a gas, cuanta mas agua pasa, menos caliente sale.
            Es al reves de lo que uno espera, y conviene decirselo tal cual.

            Solo si aun asi sigue muy caliente, que abra poco a poco la otra manija, la fria.

            NO debe cerrar la caliente para bajar la temperatura: al reducir el chorro el agua sale mas
            caliente todavia, y si pasa de 80 grados el calentador se apaga solo por seguridad. Por eso
            se recomienda dejarla siempre con un chorro medio o alto.
            TXT,
            $lado
        );
    }

    private function pasoUnBano(string $lado): string
    {
        return sprintf(
            <<<'TXT'
            Que abra SOLO la manija %s, la del agua caliente, del todo, y espere un minuto sin tocar
            la fria.

            Estos calentadores necesitan un chorro fuerte para encenderse: con la fria abierta o el
            agua a medio abrir, muchas veces no llegan a arrancar. Es la causa mas comun y se resuelve
            asi.
            TXT,
            $lado
        );
    }

    private function publicadoCasa6(): string
    {
        return '<p>Esta casita tiene <strong>dos baños</strong> y los grifos son de <strong>una '
            . 'sola palanca</strong>, pero el agua caliente no está del mismo lado:</p>'
            . '<ul><li>Baño de <strong>abajo</strong>: caliente hacia la <strong>derecha</strong>.</li>'
            . '<li>Baño de <strong>arriba</strong>: caliente hacia la <strong>izquierda</strong>.</li></ul>'
            . '<p>👉 Lleva la palanca <strong>del todo</strong> hacia ese lado y espera unos '
            . 'segundos. Si sale demasiado caliente, ábrela todavía más: cuanta más agua pasa, menos '
            . 'caliente sale.</p>';
    }

    private function agenteCasa6(): string
    {
        return <<<'TXT'
            Esta casita tiene dos banos y los dos son de una sola palanca (monocomando), pero el agua
            caliente NO esta del mismo lado. Preguntale en cual esta antes de darle la instruccion:

            - Bano de ABAJO: el caliente es hacia la DERECHA.
            - Bano de ARRIBA: el caliente es hacia la IZQUIERDA.

            En los dos, llevar la palanca del todo hacia ese lado y esperar unos segundos. Si sale
            DEMASIADO caliente, hay que abrirla todavia mas: en estos calentadores a gas, cuanta mas
            agua pasa, menos caliente sale. Es al reves de lo que uno espera.

            NO debe cerrar el paso para bajar la temperatura: al reducir el chorro el agua sale mas
            caliente todavia, y si pasa de 80 grados el calentador se apaga solo por seguridad.
            TXT;
    }

    private function pasoCasa6(): string
    {
        return <<<'TXT'
            Que lleve la palanca del todo hacia el lado caliente —DERECHA en el bano de abajo,
            IZQUIERDA en el de arriba— y espere un minuto sin moverla.

            Estos calentadores necesitan un chorro fuerte para encenderse: a medio abrir muchas veces
            no llegan a arrancar. Si no sabes en que bano esta, preguntaselo: el lado cambia.
            TXT;
    }

    private function publicadoCasa7(): string
    {
        return '<p>Esta casita tiene <strong>dos baños</strong> con grifos distintos, aunque en los '
            . 'dos el <strong>agua caliente está a la izquierda</strong>:</p>'
            . '<ul><li>Baño con <strong>una sola palanca</strong>: llévala hacia la izquierda.</li>'
            . '<li>Baño de <strong>dos manijas</strong>: la caliente es la izquierda.</li></ul>'
            . '<p>👉 Abre <strong>del todo</strong> y espera unos segundos. Si sale demasiado '
            . 'caliente, abre todavía más: cuanta más agua pasa, menos caliente sale.</p>';
    }

    private function agenteCasa7(): string
    {
        return <<<'TXT'
            Esta casita tiene dos banos con grifos distintos, pero en los DOS el agua caliente esta a
            la izquierda, asi que no hace falta preguntarle en cual esta:

            - Bano de una sola palanca: llevarla hacia la izquierda.
            - Bano de dos manijas: la caliente es la izquierda.

            En los dos, abrir del todo y esperar unos segundos. Si sale DEMASIADO caliente, hay que
            abrir todavia mas: en estos calentadores a gas, cuanta mas agua pasa, menos caliente sale.
            Es al reves de lo que uno espera.

            NO debe cerrar el paso para bajar la temperatura: al reducir el chorro el agua sale mas
            caliente todavia, y si pasa de 80 grados el calentador se apaga solo por seguridad.
            TXT;
    }

    private function pasoCasa7(): string
    {
        return <<<'TXT'
            Que abra SOLO el agua caliente —a la izquierda, sea la palanca o la manija— del todo, y
            espere un minuto sin tocar la fria.

            Estos calentadores necesitan un chorro fuerte para encenderse: con la fria abierta o el
            agua a medio abrir, muchas veces no llegan a arrancar.
            TXT;
    }

    /** La comprobación del gas compartido. Cadena vacía donde no lo comparten. */
    private function hornilla(int $casa): string
    {
        if (!in_array($casa, self::GAS_COMPARTIDO, true)) {
            return '';
        }

        return <<<'TXT'
            Pidele que vaya a la cocina y encienda una hornilla: en esta casita la ducha y la cocina
            comparten el mismo balon de gas, asi que la hornilla dice si hay gas o no.

            - Si la hornilla NO prende, el balon esta vacio. No es una averia: se le cambia. Diselo
              asi y avisa al equipo indicando que es cambio de balon.
            - Si la hornilla SI prende, hay gas y entonces el problema es del calentador. Avisa al
              equipo diciendo que la hornilla enciende: hace falta un tecnico, no un balon.

            Si todavia no lo ha comprobado, pideselo y espera su respuesta antes de avisar a nadie.
            TXT;
    }

    /** Quita la sangría del heredoc: no debe viajar al prompt. */
    private function limpiar(string $texto): string
    {
        return trim(preg_replace('/^[ \t]+/m', '', $texto) ?? $texto);
    }
}
