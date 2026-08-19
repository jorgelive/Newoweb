<?php

declare(strict_types=1);

namespace App\Agent\Triage;

use App\Agent\Access\ActorInterface;
use App\Agent\Skill\SkillInterface;

/**
 * Las dos listas blancas del triaje: qué puede EMPATAR y qué puede enrutarse DIRECTO.
 *
 * Vive fuera de {@see Triaje} por el mismo motivo que {@see \App\Agent\Conversation\AclaracionDeEmpate}
 * vive fuera del procesador: son decisiones puras —entra una lista de skills, sale una lista de
 * nombres— y dentro del clasificador, con su motor y su prompt, no se pueden probar sin montar
 * nada. Y es justo la clase de código que ya se rompió en silencio una vez: la guarda del empate
 * llevaba desde que se escribió sin poder dispararse, y sus ocho tests pasaban todos porque
 * probaban la guarda y no si algo podía LLEGAR a ella.
 */
final readonly class CatalogoDelTriaje
{
    /**
     * ¿El catálogo del triaje lleva las skills de escritura de este actor?
     *
     * 🔥 **Aquí estuvo el fallo, y por eso es una función y no un literal.** Esto era un
     * `incluirEscritura: false` fijo dentro de {@see Triaje::clasificar()}: los candidatos sólo
     * podían ser de lectura, así que {@see \App\Agent\Conversation\AclaracionDeEmpate::obliga()}
     * no podía devolver `true` en producción y el mecanismo del empate era decorado. Un literal
     * no se puede probar; esto sí, y el test falla si alguien lo vuelve a fijar.
     *
     * El criterio es el mismo del camino largo
     * ({@see \App\Agent\Service\AiConversationProcessor::candidatasResueltas()}): contar sobre
     * una lista distinta de la que el modelo va a ver es contar otra cosa.
     */
    public static function veEscrituras(ActorInterface $actor): bool
    {
        return $actor->esDelEquipo();
    }

    /**
     * Todo lo que el actor puede usar. Es la lista contra la que se validan los CANDIDATOS.
     *
     * Lleva las de escritura cuando las tiene: sin ellas dentro, un empate nunca puede contener
     * una escritura y {@see \App\Agent\Conversation\AclaracionDeEmpate::obliga()} no puede
     * devolver `true` jamás — el mecanismo entero sería decorado.
     *
     * @param list<SkillInterface> $skills
     * @return list<string>
     */
    public static function permitidas(array $skills): array
    {
        return array_map(static fn (SkillInterface $s): string => $s->nombre(), $skills);
    }

    /**
     * Lo que además puede proponerse como skill ÚNICA, sin pasar por el empate: sólo lectura.
     *
     * ⚠️ La asimetría con {@see self::permitidas()} es deliberada. Dejar que el triaje señale
     * directamente una escritura no aportaría nada —para alguien del equipo el tramo de potencia
     * ya está decidido antes de mirar este campo, y la pista va al prompt anunciada como
     * «sugerencia, no orden»—, pero sí empujaría al modelo hacia una escritura desde una línea
     * suelta. Una escritura se elige con el catálogo entero delante, en el camino largo, no con
     * una corazonada del clasificador.
     *
     * `NivelRiesgo::Interna` cuenta como lectura, igual que en el resto del sistema: escribe
     * hacia dentro.
     *
     * @param list<SkillInterface> $skills
     * @return list<string>
     */
    public static function enrutablesDirectas(array $skills): array
    {
        $nombres = [];

        foreach ($skills as $skill) {
            if (!$skill->nivelRiesgo()->exigePermisoDeEscritura()) {
                $nombres[] = $skill->nombre();
            }
        }

        return $nombres;
    }

    /**
     * Los candidatos que propuso el modelo, validados y sin repetidos, en el orden en que llegaron.
     *
     * Un nombre inventado que se colara convertiría una pregunta clara en una aclaración absurda
     * —«¿te refieres a X o a algo que no existe?»—, y el modelo se inventa identificadores con
     * toda la seguridad del mundo.
     *
     * @param list<mixed> $propuestos
     * @param list<string> $permitidas
     * @return list<string>
     */
    public static function candidatos(array $propuestos, array $permitidas): array
    {
        $vistos = [];

        foreach ($propuestos as $nombre) {
            $nombre = trim((string) $nombre);

            if ($nombre !== '' && in_array($nombre, $permitidas, true)) {
                $vistos[$nombre] = true;
            }
        }

        return array_keys($vistos);
    }
}
