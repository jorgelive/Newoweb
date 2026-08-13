<?php

declare(strict_types=1);

namespace App\Agent\Skill;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Entity\AgentConocimiento;
use App\Agent\Service\ConocimientoGenerico;
use App\Security\Roles;

/**
 * Lo que hay escrito sobre lo que preguntan todo el rato.
 *
 * ── Es la ÚLTIMA red, no la primera ─────────────────────────────────────────
 * No sustituye a ninguna skill: lo que se calcula o se consulta en la base —la disponibilidad,
 * la cuenta, el wifi de una casita concreta— sigue siendo cosa de ellas, y esas respuestas son
 * de verdad. Esto es para lo que no tiene skill y no cambia nunca: si hay estacionamiento, a qué
 * hora abre la puerta, si se aceptan mascotas. Sin esto, cada una de ésas acaba interrumpiendo a
 * una persona para decir lo mismo otra vez.
 *
 * ── Vive en `Skill/` y no en `Skill/Pms/` ───────────────────────────────────
 * Es transversal de verdad: no declara dominio, así que la ve cualquier actor. Un pasajero de
 * tours pregunta por el horario de la oficina igual que un huésped, y la respuesta es la misma.
 *
 * ── Dos llamadas, y por qué ─────────────────────────────────────────────────
 * El bloque de CATEGORÍAS viaja en el contexto del turno, así que el modelo llega aquí sabiendo
 * ya de qué va la pregunta. Con `categoria` a secas se le devuelven las etiquetas de esa
 * categoría —sin contenido—; con `item_id` se le devuelve el contenido. Mandarlo todo junto
 * sería mandar la tabla entera, y está pensada para crecer mucho.
 */
final readonly class ConsultarConocimientoSkill implements SkillInterface
{
    public function __construct(
        private ConocimientoGenerico $conocimiento,
    ) {}

    public function nombre(): string
    {
        return 'consultar_conocimiento';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Respuestas ya escritas a preguntas frecuentes que no dependen de la '
                . 'reserva ni del día: horarios, normas, formas de pago, servicios, cómo llegar. '
                . '⚠️ ÚSALA ANTES DE ESCALAR AL EQUIPO cuando ninguna otra herramienta encaje: '
                . 'la mayoría de lo que se escala es algo que ya está contestado aquí, y escalarlo '
                . 'interrumpe a una persona para repetir lo de siempre. '
                . 'En el contexto de este turno tienes la lista de TEMAS disponibles: elige el que '
                . 'encaje y llámame con «categoria». Te devuelvo las etiquetas de ese tema; '
                . 'cuando veas la que responde, vuelve a llamarme con su «item_id» y te doy el '
                . 'texto. Si el tema que elegiste no tenía nada, te devuelvo la lista de los que '
                . 'sí existen: mírala antes de darlo por perdido. Cuando ninguno encaje, entonces '
                . 'sí toca escalar. '
                . 'El texto que devuelvo está redactado para decirse: adáptalo al idioma y al tono '
                . 'de quien pregunta, pero no le añadas datos que no estén ahí.',
            parametros: [
                SkillParameter::texto('categoria', 'El id del tema, tal cual aparece en la lista '
                    . 'de TEMAS del contexto (por ejemplo «pagos»). Si no lo tienes delante, '
                    . 'llámame SIN esto y te doy la lista: NUNCA te lo inventes.',
                    requerido: false),
                SkillParameter::texto('item_id', 'El id de la respuesta concreta, de la lista que '
                    . 'te devolví en la llamada anterior. Sin esto te doy sólo las etiquetas.',
                    requerido: false),
            ],
        );
    }

    /**
     * Abierta a todos los perfiles, incluido el prospecto.
     *
     * Es lo que la hace útil: un desconocido que pregunta si hay estacionamiento merece la misma
     * respuesta que un huésped, y esa respuesta no revela nada de nadie. Lo que sí es sensible se
     * acota con los `perfiles` de cada ítem, en la fila, no cerrando la puerta entera.
     */
    public function rolesRequeridos(): array
    {
        return [Roles::PROSPECTO, Roles::HUESPED, Roles::MENSAJES_SHOW];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Lectura;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        $categoria = trim((string) ($entrada['categoria'] ?? ''));
        $itemId = trim((string) ($entrada['item_id'] ?? ''));

        // SEGUNDA fase: ya eligió, se le da el texto.
        if ($itemId !== '') {
            $item = $this->conocimiento->item($itemId, $actor);

            if ($item === null) {
                // No se dice «no existe»: puede existir y no tocarle. Lo que importa es que el
                // modelo no insista ni deduzca nada de la diferencia.
                return SkillResult::ok([
                    'encontrado' => false,
                    'aviso' => 'No hay respuesta escrita para eso. Si hace falta, escala al equipo.',
                ]);
            }

            return SkillResult::ok([
                'encontrado' => true,
                'contenido' => $item->getContenido(),
                // El modelo tiene que contestar Y avisar de que alguien lo verá: son las dos
                // mitades de lo mismo, no una alternativa.
                'requiere_humano' => $item->isRequiereHumano(),
                'aviso' => $item->isRequiereHumano()
                    ? 'Contesta esto y ADEMÁS llama a escalar_al_equipo: es de lo que se explica '
                        . 'pero lo resuelve una persona.'
                    : null,
            ]);
        }

        if ($categoria === '') {
            return SkillResult::ok([
                'temas' => array_map(
                    static fn ($c): array => ['id' => $c->getId(), 'nombre' => $c->getNombre()],
                    $this->conocimiento->categoriasPara($actor)
                ),
                'aviso' => 'Elige un tema y vuelve a llamarme con «categoria».',
            ]);
        }

        // PRIMERA fase resuelta: las etiquetas de esa categoría, sin el contenido.
        $items = $this->conocimiento->itemsDe($categoria, $actor);

        if ($items === []) {
            // ⚠️ AQUÍ SE DEVUELVE EL CATÁLOGO, NO UNA ORDEN DE ESCALAR.
            //
            // Antes esto respondía «no hay nada escrito, no insistas con otro tema: escala», y
            // era la peor salida posible en el caso más probable: el modelo llega con una
            // categoría que se inventó —o que ya no existe porque alguien la renombró en el
            // panel—, no encuentra nada, y una pregunta CON respuesta escrita acababa
            // interrumpiendo a una persona. Con la lista delante, corrige y acierta.
            //
            // `ConsultarGuiaSkill` ya aprendió esto: cuando el `tema_id` no está, devuelve el
            // catálogo. Esta skill nació sin la lección.
            $temas = $this->conocimiento->categoriasPara($actor);

            return SkillResult::ok([
                'encontrado' => false,
                'temas' => array_map(
                    static fn ($c): array => ['id' => $c->getId(), 'nombre' => $c->getNombre()],
                    $temas
                ),
                'aviso' => $temas === []
                    ? 'No hay nada escrito todavía. Si no puedes responder tú, escala al equipo.'
                    : 'En ese tema no hay nada para quien pregunta. Estos son los temas que SÍ '
                        . 'existen: si alguno encaja con lo que te preguntaron, vuelve a llamarme '
                        . 'con su id. Si ninguno encaja, entonces sí escala.',
            ]);
        }

        return SkillResult::ok([
            'respuestas' => array_map(
                static fn (AgentConocimiento $i): array => [
                    'item_id' => (string) $i->getId(),
                    'reconoce' => $i->getEtiquetas(),
                ],
                $items
            ),
            'aviso' => 'Elige la que responda a la pregunta y vuelve a llamarme con su «item_id». '
                . 'Si ninguna encaja, no está escrito: escala.',
        ]);
    }
}
