<?php

declare(strict_types=1);

namespace App\Agent\Command;

use App\Agent\Access\AgentActor;
use App\Agent\Provider\Google\GoogleAISkillAdapter;
use App\Agent\Service\AiConversationProcessor;
use App\Agent\Skill\SkillRegistry;
use ReflectionMethod;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Uso: php bin/console app:agent:prefijo
 *
 * Qué tan cacheable es el prompt del agente. Imprime el hash del PREFIJO —la instrucción de
 * sistema, que es lo que la caché casa byte a byte— para varios canales y perfiles.
 *
 * ⚠️ **La pregunta que contesta es una sola: ¿son iguales?** La caché de prefijo no casa «casi»:
 * un byte distinto en la primera línea tira los ~8 000 tokens que van detrás. Antes de mover la
 * fecha y el formato de canal a la parte volátil, estos hashes salían TODOS distintos y la
 * métrica de producción decía lo mismo por el otro lado: 104 781 tokens de entrada, **0 %**
 * cacheado.
 *
 * Filas con el mismo hash ⇒ comparten prefijo ⇒ comparten caché. Filas distintas se pagan
 * enteras cada vez, así que cada hash nuevo que aparezca aquí hay que poder justificarlo: hoy
 * sólo el dominio de negocio (alojamiento vs. viajes) puede partir el prefijo, porque son
 * textos distintos de verdad.
 *
 * Ver docs/Mensajeria.md §13.5 bis.
 */
#[AsCommand(
    name: 'app:agent:prefijo',
    description: 'Comprueba que el prefijo cacheable del agente es idéntico entre canales.',
)]
final class AgentPrefijoCommand extends Command
{
    public function __construct(
        private readonly AiConversationProcessor $procesador,
        // El prefijo NO es sólo la instrucción de sistema: delante de los mensajes también van
        // las declaraciones de las herramientas, y son la mitad larga. Sin contarlas, el número
        // que sale de aquí no se puede comparar con el mínimo de Gemini.
        private readonly SkillRegistry $skills,
        private readonly GoogleAISkillAdapter $adaptador,
    ) {
        parent::__construct();
    }

    /**
     * Mínimo de tokens que Gemini exige para siquiera INTENTAR la caché implícita.
     *
     * ⚠️ Es un umbral, no una tarifa: por debajo no se cachea **nada**, y no lo dice. Un prompt
     * de 4 000 tokens repetido mil veces se paga mil veces entero.
     */
    private const MINIMO_CACHE_GEMINI = 4096;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Por reflexión y a propósito: `reglas()` es privado porque nadie de fuera debe poder
        // ARMAR el prompt. Aquí sólo se mide, y exponerlo en la API pública del servicio para
        // que un comando de diagnóstico lo lea sería pagar un contrato por un informe.
        $reglas = new ReflectionMethod($this->procesador, 'reglas');

        $actores = [
            'huésped · whatsapp' => AgentActor::huesped('whatsapp', 'pms_reserva', '1'),
            'huésped · booking' => AgentActor::huesped('booking', 'pms_reserva', '1'),
            'huésped · airbnb' => AgentActor::huesped('airbnb', 'pms_reserva', '1'),
            'prospecto · whatsapp' => AgentActor::prospecto('whatsapp'),
            'huésped · cotización' => AgentActor::huesped('whatsapp', 'cotizacion', '1'),
        ];

        $filas = [];
        $porDominio = [];
        /** @var list<int> $minimos */
        $minimos = [];

        foreach ($actores as $nombre => $actor) {
            /** @var string $prompt */
            $prompt = $reglas->invoke($this->procesador, $actor);
            $hash = substr(sha1($prompt), 0, 12);

            $herramientas = json_encode(
                $this->adaptador->declaraciones(
                    $this->skills->paraActor($actor, incluirEscritura: $actor->esDelEquipo())
                )
            );

            $reglasTok = intdiv(mb_strlen($prompt), 4);
            $toolsTok = intdiv(mb_strlen((string) $herramientas), 4);
            $total = $reglasTok + $toolsTok;

            $filas[] = [
                $nombre,
                $hash,
                number_format($reglasTok),
                number_format($toolsTok),
                number_format($total),
                $total >= self::MINIMO_CACHE_GEMINI ? 'sí' : 'NO ⚠️',
            ];
            $porDominio[$actor->contextoTipo() ?? '—'][$hash] = true;
            $minimos[] = $total;
        }

        $io->table(
            ['actor', 'sha1(reglas)', 'reglas', 'herramientas', 'prefijo', '≥ 4096'],
            $filas
        );

        $rotos = [];
        foreach ($porDominio as $dominio => $hashes) {
            if (count($hashes) > 1) {
                $rotos[] = $dominio;
            }
        }

        if ($rotos !== []) {
            $io->error(sprintf(
                'El prefijo NO es único dentro de %s. Algo volátil se coló en reglasComunes(): '
                . 'busca una interpolación en el prompt de sistema y muévela a contexto().',
                implode(', ', $rotos)
            ));

            return Command::FAILURE;
        }

        // La otra mitad: que lo volátil siga estando en alguna parte. Un prefijo idéntico es
        // trivial de conseguir borrando la fecha, y eso ya costó una cotización a tarifas del
        // año pasado.
        /** @var string $prompt */
        $prompt = $reglas->invoke($this->procesador, $actores['huésped · whatsapp']);

        if (str_contains($prompt, 'Hoy es')) {
            $io->error('La fecha volvió al prefijo cacheado: invalida los ~8 000 tokens de detrás.');

            return Command::FAILURE;
        }

        if (min($minimos) < self::MINIMO_CACHE_GEMINI) {
            $io->warning(sprintf(
                'El prefijo es estable, pero el más corto son %s tokens y Gemini no cachea nada '
                . 'por debajo de %s. Ordenarlo era condición necesaria y no suficiente: sin '
                . 'llegar al mínimo, la caché implícita no se activa y no avisa de ello.',
                number_format(min($minimos)),
                number_format(self::MINIMO_CACHE_GEMINI)
            ));

            return Command::SUCCESS;
        }

        $io->success(
            'Un prefijo por dominio de negocio, idéntico entre canales y perfiles, y por encima '
            . 'del mínimo de Gemini. La fecha y el formato de canal viajan en la parte volátil.'
        );

        return Command::SUCCESS;
    }
}
