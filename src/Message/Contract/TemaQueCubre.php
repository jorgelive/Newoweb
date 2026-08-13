<?php

declare(strict_types=1);

namespace App\Message\Contract;

/**
 * Un tema de un dominio que ya responde a lo que alguien quiere escribir en el conocimiento.
 *
 * ── Por qué no basta con el nombre ──────────────────────────────────────────
 * Que la guía cubra un tema no dice qué hay que hacer con el duplicado, y las dos situaciones
 * posibles piden decisiones **opuestas**:
 *
 * - «Estacionamiento» es un ítem general y su texto es palabra por palabra el mismo que el de la
 *   ficha genérica. Excluir al huésped ahí no le protege de nada —la mejor y la peor son la
 *   misma— y encima le quita la red por si la guía no llega a servirle.
 * - «Ducha» tiene una ficha por casita, con el grifo de la suya. Ahí la genérica sí es peor, y el
 *   huésped tiene que quedarse fuera.
 *
 * ── Lo decide el dominio, no el núcleo ──────────────────────────────────────
 * Qué hace que una ficha sea «más específica» es conocimiento de dominio: en alojamiento son las
 * fichas por casita y los datos de la estancia; en Turismo será otra cosa. El núcleo recibe el
 * veredicto y el porqué ya redactados, y compone el consejo sin entender ninguno de los dos.
 * Ver CLAUDE.md §Dominios y contratos.
 */
final readonly class TemaQueCubre
{
    /**
     * @param string $etiqueta Cómo se llama el tema para quien administra: «Ducha», «Pago».
     * @param bool   $masEspecifica ¿Su respuesta es mejor que una genérica para quien SÍ llega a
     *                              ella? `false` cuando dicen lo mismo.
     * @param string $porQue Media línea explicando el veredicto, para enseñársela al operador.
     *                       Vacía cuando no aporta.
     */
    public function __construct(
        public string $etiqueta,
        public bool $masEspecifica = false,
        public string $porQue = '',
    ) {}
}
