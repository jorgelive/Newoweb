<?php

declare(strict_types=1);

namespace App\Agent\Service;

use App\Agent\Conversation\PerfilConversacion;
use App\Agent\Entity\AgentConocimiento;
use App\Agent\Contract\IndiceDeTemasInterface;
use App\Contract\TemaQueCubre;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * ¿Esto que vas a escribir en el conocimiento no lo contesta ya la guía?
 *
 * ── Por qué hace falta ──────────────────────────────────────────────────────
 * El conocimiento genérico es **la última red antes de escalar**. Si nace repitiendo lo que la
 * guía ya responde —y mejor, porque la guía está anclada a la casita y resuelve códigos y
 * horas—, aparecen dos fuentes con el mismo tema que se separan el día que alguien corrige una
 * sola. El error se comete al dar de alta, así que es ahí donde hay que avisar.
 *
 * ── Avisa, NO prohíbe ───────────────────────────────────────────────────────
 * ⚠️ **Duplicar puede ser lo correcto**, y por eso esto no bloquea nada. La guía exige una
 * casita: quien pregunta *antes* de reservar —«¿hay estacionamiento?», «¿siempre hay agua
 * caliente?»— se lleva hoy una repregunta en vez de una respuesta que existe desde hace meses.
 * Para ese público la copia genérica es la respuesta buena.
 *
 * Lo que hay que hacer entonces es **declararla pública**: quitar al huésped de «A quién se le
 * puede contar» y dejar el resto. Esa es la declaración, no hace falta un campo aparte — y el
 * único que sobra es el huésped, porque para él existe la ficha de su casita.
 *
 * Lo que sí es un error es la copia **sin acotar**: entonces un huésped recibiría la versión
 * genérica en lugar de la de su casita, y perdería las horas y los códigos resueltos. Ése es el
 * único caso que este validador señala como problema.
 *
 * ── Agnóstico por contrato ──────────────────────────────────────────────────
 * No sabe qué es una casita ni una guía: pregunta a **todos** los índices de temas registrados
 * ({@see IndiceDeTemasInterface}) y junta lo que digan. Cuando Turismo tenga el suyo, valida
 * contra sus temas sin tocar una línea de aquí. Ver CLAUDE.md §Dominios y contratos.
 */
final readonly class ValidadorDeConocimiento
{
    /**
     * El único perfil que NO debe recibir una copia genérica de un tema de guía.
     *
     * Para el huésped existe la ficha de su casita, con sus horas y sus códigos: dársela genérica
     * es darle la peor de las dos. Los demás —incluido el EQUIPO— la necesitan; acotar a
     * «prospecto e interesado» dejaba al equipo viendo menos que un desconocido.
     */
    private const string PERFIL_QUE_SOBRA = PerfilConversacion::Huesped->value;

    /**
     * @param iterable<IndiceDeTemasInterface> $indices
     */
    public function __construct(
        #[TaggedIterator('app.agent.indice_temas')]
        private iterable $indices,
    ) {}

    /**
     * El aviso que se le enseña a quien está creando o editando la entrada, o `null` si no hay
     * nada que decir.
     */
    public function avisoPara(AgentConocimiento $item): ?string
    {
        $temas = $this->temasQueYaLoCubren($item);

        if ($temas === []) {
            return null;
        }

        $lista = implode('», «', array_map(static fn (TemaQueCubre $t): string => $t->etiqueta, $temas));
        $mejores = array_values(array_filter($temas, static fn (TemaQueCubre $t): bool => $t->masEspecifica));
        $excluida = $this->esPublica($item);

        // ── La guía tiene algo MEJOR para quien llega a ella ──────────────────
        // Aquí sí importa que el huésped quede fuera: la genérica le quitaría el grifo de su
        // casita o las horas de su estancia.
        if ($mejores !== []) {
            $porQue = $mejores[0]->porQue;

            return $excluida
                ? sprintf(
                    'También lo contesta la guía en «%s», y allí es mejor: %s. Como esta ficha '
                    . 'excluye al huésped, él recibirá la suya y los demás ésta. Correcto.',
                    $lista,
                    $porQue
                )
                : sprintf(
                    '⚠️ Esto ya lo contesta la guía en «%s», y allí es MEJOR: %s. Tal como está, '
                    . 'un huésped podría llevarse esta versión genérica en lugar de la suya.<br>'
                    . 'En «A quién se le puede contar» marca todos <strong>menos Huésped</strong>: '
                    . 'así él sigue recibiendo la de su casita y los demás —incluido el equipo— '
                    . 'reciben ésta.',
                    $lista,
                    $porQue
                );
        }

        // ── La guía dice LO MISMO ─────────────────────────────────────────────
        // Excluir al huésped aquí no le protege de nada, porque la mejor y la peor son la misma —
        // y en cambio le quita esta red si la guía no llega a servirle (sin reserva resuelta,
        // fuera de ventana). Es el consejo contrario al de arriba, y por eso se distinguen.
        return $excluida
            ? sprintf(
                '💡 La guía contesta esto en «%s», pero **lo mismo**: es un tema general, sin '
                . 'nada por casita ni datos de la estancia.<br>'
                . 'Excluir al huésped aquí no le protege —la versión buena y ésta son iguales— y '
                . 'sí le quita esta red si la guía no llega a servirle. Puedes marcarle '
                . '<strong>Huésped</strong> también.',
                $lista
            )
            : sprintf(
                '✅ La guía contesta esto en «%s», pero lo mismo: es un tema general. Dejarlo para '
                . 'todos está bien — hace de red por si la guía no llega.<br>'
                . '⚠️ Eso sí: ahora hay dos textos que dicen lo mismo y nadie obliga a que sigan '
                . 'diciéndolo. Si cambias uno, cambia el otro.',
                $lista
            );
    }

    /**
     * ¿Duplica un tema de guía sin estar acotada al público que no llega a ella?
     *
     * Es lo que busca la auditoría: los avisos ya atendidos no vuelven a salir.
     */
    public function duplicaSinDeclarar(AgentConocimiento $item): bool
    {
        return !$this->esPublica($item) && $this->temasQueYaLoCubren($item) !== [];
    }

    /** @return list<TemaQueCubre> */
    public function temasQueYaLoCubren(AgentConocimiento $item): array
    {
        // Se pregunta por las etiquetas y no por el contenido: las etiquetas son «con qué
        // palabras se pregunta por esto», que es exactamente lo que hay que cruzar contra los
        // términos de la guía. El contenido menciona de pasada cosas que el tema no responde.
        $texto = trim($item->getEtiquetas() . ' ' . $item->getNombreInterno());

        if ($texto === '') {
            return [];
        }

        $temas = [];

        foreach ($this->indices as $indice) {
            foreach ($indice->temasQueCubren($texto) as $tema) {
                $temas[$tema->etiqueta] = $tema;
            }
        }

        return array_values($temas);
    }

    /**
     * ¿Está declarada como pública?
     *
     * Basta con que el huésped NO esté en la lista. Una lista vacía significa «para todos» —la
     * convención del resto del agente— y por tanto **no** es una declaración: ahí el huésped
     * también la recibiría.
     */
    private function esPublica(AgentConocimiento $item): bool
    {
        $perfiles = $item->getPerfiles();

        // Lista vacía = «para todos», incluido el huésped. No es una declaración.
        return $perfiles !== [] && !in_array(self::PERFIL_QUE_SOBRA, $perfiles, true);
    }
}
