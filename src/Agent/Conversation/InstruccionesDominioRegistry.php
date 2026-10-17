<?php

declare(strict_types=1);

namespace App\Agent\Conversation;

use App\Agent\Access\ActorInterface;
use App\Contract\MapaDeHitos;
use App\Agent\Contract\InstruccionesDeDominioInterface;
use App\Agent\Contract\PresentacionDeNegocioInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Qué dominio le habla a esta conversación.
 *
 * Mismo patrón que {@see \App\Message\Service\MessageDataResolverRegistry}: se recorre la
 * lista, gana el primero que dice soportar el `context_type`, y si ninguno lo hace se cae al
 * marcado por defecto. Ese último caso no es un remiendo — es el prospecto, que por
 * definición no trae contexto y aun así necesita que alguien le venda algo.
 *
 * ⚠️ Salvo en las REGLAS del prompt ({@see self::para()}): sin contexto reconocido no habla sólo
 * el por defecto, hablan todos los negocios. El contexto volátil sí sigue siendo de uno solo.
 */
final readonly class InstruccionesDominioRegistry
{
    /**
     * @param iterable<InstruccionesDeDominioInterface> $dominios
     */
    public function __construct(
        #[TaggedIterator('app.agent.instrucciones_dominio')]
        private iterable $dominios
    ) {}

    /**
     * El bloque del negocio para este perfil.
     *
     * Con un contexto que algún dominio reconoce, habla sólo ése: un huésped de una casita oye
     * del alojamiento. **Sin contexto reconocido —el prospecto, un hilo `manual`— hablan
     * TODOS**: el por defecto primero y detrás el resto, cada uno con lo suyo o nada. Es la misma
     * idea que las puertas de venta de `EnumeradorDeFrentes` («la venta va siempre, para
     * todos»): quien no es de nadie puede comprar cualquier cosa.
     *
     * 🔥 Hasta el 11/10/2026 aquí también ganaba uno solo, el por defecto (alojamiento), y un
     * desconocido que preguntó por transporte a Machu Picchu recibió «no organizamos transporte»
     * con el tour publicado en la web: del negocio de tours el agente no sabía ni que existía.
     *
     * ⚠️ **Si el dominio propio no tiene NADA que decirle a ese perfil, cede el turno** al camino
     * sin contexto (16/10/2026). Es lo que deja a Turismo reconocer sus expedientes sin que un
     * compañero del equipo que escribe en uno pierda su bloque de siempre: Turismo no le dice
     * nada al personal, y entonces habla el resto.
     */
    public function para(?string $contextType, PerfilConversacion $perfil): string
    {
        $propio = $this->propio($contextType);

        if ($propio !== null) {
            $suyo = $propio->para($perfil);

            if ($suyo !== '') {
                return implode("\n\n", [$suyo, ...$this->presentacionesDeOtros($propio, $perfil)]);
            }
        }

        $bloques = [];

        foreach ($this->ordenados() as $dominio) {
            $bloque = $dominio->para($perfil);

            if ($bloque !== '') {
                $bloques[] = $bloque;
            }
        }

        return implode("\n\n", $bloques);
    }

    /**
     * El contexto volátil del dominio para esta conversación.
     *
     * Se resuelve por el `context_type` de la CONVERSACIÓN y no por el del actor: son el mismo
     * valor en el chat, pero el actor de un prospecto nace sin contexto a propósito y aquí lo
     * que se pregunta es de qué negocio va el hilo, no a qué está atado quien escribe.
     */
    /**
     * Lo que los OTROS negocios dicen de sí mismos a este perfil: ver
     * {@see PresentacionDeNegocioInterface}. Al huésped de una casita, que somos también agencia.
     *
     * @return list<string>
     */
    private function presentacionesDeOtros(InstruccionesDeDominioInterface $propio, PerfilConversacion $perfil): array
    {
        $frases = [];

        foreach ($this->dominios as $dominio) {
            if ($dominio === $propio || !$dominio instanceof PresentacionDeNegocioInterface) {
                continue;
            }

            $frase = $dominio->presentacion($perfil);

            if ($frase !== '') {
                $frases[] = $frase;
            }
        }

        return $frases;
    }

    public function contextoVolatil(?string $contextoTipo, ActorInterface $actor, MapaDeHitos $hitos): string
    {
        return $this->resolver($contextoTipo)?->contextoVolatil($actor, $hitos) ?? '';
    }

    /**
     * Gana el primero que dice soportar el tipo; si ninguno, el marcado por defecto.
     *
     * Ese último caso no es un remiendo: es el prospecto, que por definición no trae contexto
     * y aun así necesita que alguien le venda algo.
     */
    private function resolver(?string $contextType): ?InstruccionesDeDominioInterface
    {
        $primero = $this->ordenados()[0] ?? null;

        return $this->propio($contextType) ?? ($primero?->esPorDefecto() === true ? $primero : null);
    }

    /** El dominio que reconoce este `context_type`, si alguno lo hace. */
    private function propio(?string $contextType): ?InstruccionesDeDominioInterface
    {
        foreach ($this->dominios as $dominio) {
            if ($dominio->supports($contextType)) {
                return $dominio;
            }
        }

        return null;
    }

    /**
     * Todos, con el marcado por defecto delante.
     *
     * El orden importa en el prompt: el por defecto es el negocio con el que se presenta la casa
     * («eres la primera impresión del alojamiento»), y los demás se leen como «además…».
     *
     * @return list<InstruccionesDeDominioInterface>
     */
    private function ordenados(): array
    {
        $primero = [];
        $resto = [];

        foreach ($this->dominios as $dominio) {
            if ($primero === [] && $dominio->esPorDefecto()) {
                $primero[] = $dominio;
            } else {
                $resto[] = $dominio;
            }
        }

        return [...$primero, ...$resto];
    }
}
