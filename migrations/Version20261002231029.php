<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lo que lee el AGENTE en la guía: cada dato condicionado, dentro de su condición.
 *
 * Revisión de fugas del 02/10/2026 (`docs/Agent.md` §5.7). El agente decía sin que se lo
 * preguntaran lo que sólo debía decir si preguntaban —el «gratis» del equipaje, el costo del
 * horario extra, el depósito, el cerrajero—, porque el texto daba el dato y DESPUÉS la condición,
 * o lo prohibía con el dato al lado. Ahora: «SI PREGUNTA X: dato».
 *
 * ⚠️ **La guía publicada NO se toca** (Jorge): lo que lee el huésped dice la política entera, que
 * es lo que da claridad. Esto es sólo `agente_contenido` / `agente_pasos` de `pms_guia_item` y el
 * `contenido` de un `agent_conocimiento`, que no se traducen: por eso va por migración.
 *
 * Cada sustitución es exacta: si el texto ya no está como se escribió —lo editó alguien desde el
 * panel—, esa pieza se salta y se dice.
 */
final class Version20261002231029 extends AbstractMigration
{
    /** @var list<array{0: string, 1: string, 2: string}> [ítem, antes, después] en agente_contenido */
    private const array CONTENIDO = [
        ['Equipaje y horarios flexibles (general)', 'INGRESO TEMPRANO Y SALIDA TARDE. Es política: sujeto a disponibilidad, con un día de anticipación y con un costo adicional. Lo decide el equipo, que suele flexibilizarlo, así que
no lo confirmes tú: apunta lo que pide con confirmar_hora —deja la petición y avisa al equipo—
y dile que lo consultas.

EL COSTO, SÓLO SI LO PREGUNTA. Si pregunta si tiene costo, la respuesta es sí: hay un costo
adicional y el equipo le confirma el detalle. Mientras no lo pregunte, la conversación es de
horas y disponibilidad.', 'INGRESO TEMPRANO Y SALIDA TARDE. Depende de la disponibilidad y se coordina con un día de
anticipación. Lo decide el equipo, que suele flexibilizarlo, así que no lo confirmes tú: apunta
lo que pide con confirmar_hora —deja la petición y avisa al equipo— y dile que lo consultas. La
conversación es de horas y disponibilidad.

SI PREGUNTA SI TIENE COSTO: sí, hay un costo adicional y el equipo le confirma el detalle.'],
        ['Pago (general)', 'Depósito de garantía: SOLO si pregunta por el. No lo saques tu.
Aplica a Booking.com y reservas directas; a los de Airbnb no. Si pregunta: son S/ 300 que se
dejan al hacer el check-in y se devuelven integros al salir, cuando se comprueba que están las
llaves y no hay daños. No es un cobro, es un depósito en garantía.', 'SI PREGUNTA POR EL DEPÓSITO DE GARANTÍA: en Booking.com y reservas directas son S/ 300 que se
dejan al hacer el check-in y se devuelven íntegros al salir, cuando se comprueba que están las
llaves y no hay daños. No es un cobro, es una garantía. En Airbnb no hay depósito.'],
        ['Llaves (general)', 'Se entrega UN solo juego de llaves. Si pregunta por un segundo juego, la respuesta es que no hay: solo hay uno. No le digas que debería estar sobre la mesa.

Si extravía las llaves, que avise de inmediato para cambiar los pines de la cerradura. El costo del cerrajero corre por cuenta del huésped.', 'Se entrega UN solo juego de llaves.

SI PREGUNTA POR UN SEGUNDO JUEGO: no hay otro juego, pero puede quedarse con la llave de la caja
fuerte durante su estancia en vez de devolverla. Su guía sugiere dejarla allí, y es lo
recomendable, pero no es obligatorio.

SI LAS PIERDE O PREGUNTA QUÉ PASA SI LAS PIERDE: está en el tema «Pérdida de llaves».'],
    ];

    /** @var list<array{0: string, 1: string}> encabezados de las descripciones de las casitas */
    private const array CASITAS = [
        ['ACCESO Y NIVELES. No lo cuentes de entrada: sólo si preguntan por gradas, escaleras, accesibilidad, o si viaja alguien mayor o con movilidad reducida.', 'SI PREGUNTA POR GRADAS, ESCALERAS O ACCESIBILIDAD, O VIAJA ALGUIEN MAYOR O CON MOVILIDAD REDUCIDA:'],
        ['ESPACIO PARA UN GRUPO GRANDE. No lo cuentes de entrada: sólo si preguntan dónde comen todos, dónde sentarse o por la comodidad para 8.', 'SI PREGUNTA DÓNDE COMEN TODOS, DÓNDE SENTARSE O POR LA COMODIDAD PARA 8:'],
        ['ESPACIO PARA UN GRUPO GRANDE. No lo cuentes de entrada: sólo si preguntan dónde comen todos, dónde sentarse o por la comodidad para 10.', 'SI PREGUNTA DÓNDE COMEN TODOS, DÓNDE SENTARSE O POR LA COMODIDAD PARA 10:'],
        ['ESPACIO PARA 4. No lo cuentes de entrada: sólo si preguntan dónde comen o dónde sentarse.', 'SI PREGUNTA DÓNDE COMEN O DÓNDE SENTARSE:'],
        ['NO HAY SALA: si preguntan, dilo así.', 'SI PREGUNTA POR LA SALA: no hay.'],
    ];

    private const string EARLY = 'Early check in Late Check Out';
    private const string EARLY_PASOS_ANTES = '["Si pide entrar antes o salir después, mira si la casita esta libre alrededor de su\\nestancia: con el huésped viene en el contexto; desde el panel, en \\"casita_ese_dia\\" y\\n\\"casita_tras_su_salida\\" de consultar_mi_reserva.\\n\\n- Sale otro huésped ese día: no hay margen. Ofrece almacen y avisa al equipo.\\n- La casita esta libre: cabe pedir hasta unas 3 horas antes de la entrada. Dile que lo\\nves posible y que lo confirma el equipo, y avisale de que la limpieza podria estar\\naun trabajando y de que la llave quiza no este todavía en la caja.\\n- Entra otro huésped el día que se va: la salida es a su hora. Almacen y avisar.\\n- Sin nadie entrando: cabe pedir hasta 1 hora extra a la salida, misma formula.\\n\\nEso es interno: da la conclusion, nunca digas que hay otro huésped ni sus horas.\\n\\nNUNCA lo concedas tu, ni con la casita libre: dices que parece posible y escalas.", "Si lo que pide es más que eso —entrar a media manana, quedarse hasta las 3— ya es\\nentrada temprana o salida tarde de verdad: tiene costo, se coordina el día anterior\\nsegun disponibilidad y lo confirma el equipo.\\n\\nSi quiere asegurarlo pase lo que pase, la unica forma es reservar una noche\\nadicional. Ofrecelo solo si insiste en tener certeza, no antes."]';
    private const string EARLY_PASOS_DESPUES = '["Si pide entrar antes o salir después, mira si la casita está libre alrededor de su\\nestancia: con el huésped viene en el contexto (ANTES / DESPUÉS); desde el panel, en\\n\\"casita_ese_dia\\" y \\"casita_tras_su_salida\\" de consultar_mi_reserva.\\n\\n- La casita no está libre esa mañana: la entrada es a su hora. Ofrece el almacén y avisa al equipo.\\n- La casita está libre: cabe pedir hasta unas 3 horas antes de la entrada. Dile que lo\\nves posible y que lo confirma el equipo, y avísale de que la limpieza podría estar\\naún trabajando y de que la llave quizá no esté todavía en la caja.\\n- No cabe una salida tardía: la salida es a su hora. Almacén y avisar.\\n- Cabe: hasta 1 hora extra a la salida, misma fórmula.\\n\\nDale la conclusión, que es lo que le sirve.\\n\\nNUNCA lo concedas tú, ni con la casita libre: dices que parece posible y escalas.", "Si lo que pide es más que eso —entrar a media mañana, quedarse hasta las 3— ya es\\nentrada temprana o salida tarde de verdad: se coordina el día anterior según\\ndisponibilidad y lo confirma el equipo.\\n\\nSI PREGUNTA SI TIENE COSTO: sí, tiene un costo adicional y el equipo le confirma el detalle.\\n\\nSI INSISTE EN TENER CERTEZA: la única forma segura es reservar una noche adicional."]';

    private const string EQUIPAJE_PUBLICO = 'Guardar equipaje (público)';
    private const string EQUIPAJE_PUBLICO_COSTO = '

SI PREGUNTA EL COSTO: es gratis para nuestros huéspedes.';

    public function getDescription(): string
    {
        return 'Agente: cada dato condicionado dentro de su «SI PREGUNTA» (costo, depósito, llaves, casitas).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::CONTENIDO as [$item, $antes, $despues]) {
            $actual = $this->connection->fetchOne('SELECT agente_contenido FROM pms_guia_item WHERE nombre_interno = ?', [$item]);

            if (!is_string($actual) || !str_contains($actual, $antes)) {
                $this->write(sprintf('«%s»: el texto ya no está como se escribió, no se toca.', $item));
                continue;
            }

            $this->addSql('UPDATE pms_guia_item SET agente_contenido = ? WHERE nombre_interno = ?', [str_replace($antes, $despues, $actual), $item]);
        }

        $descripciones = $this->connection->fetchAllAssociative(
            "SELECT nombre_interno, agente_contenido FROM pms_guia_item WHERE nombre_interno LIKE 'Descripción (casa %'"
        );

        foreach ($descripciones as $fila) {
            $nombre = $fila['nombre_interno'] ?? null;
            $texto = $fila['agente_contenido'] ?? null;

            if (!is_string($nombre) || !is_string($texto)) {
                continue;
            }

            $nuevo = $texto;

            foreach (self::CASITAS as [$antes, $despues]) {
                $nuevo = str_replace($antes, $despues, $nuevo);
            }

            if ($nuevo !== $texto) {
                $this->addSql('UPDATE pms_guia_item SET agente_contenido = ? WHERE nombre_interno = ?', [$nuevo, $nombre]);
            }
        }

        $pasos = $this->connection->fetchOne('SELECT agente_pasos FROM pms_guia_item WHERE nombre_interno = ?', [self::EARLY]);

        if (is_string($pasos) && json_decode($pasos, true) === json_decode(self::EARLY_PASOS_ANTES, true)) {
            $this->addSql('UPDATE pms_guia_item SET agente_pasos = ? WHERE nombre_interno = ?', [self::EARLY_PASOS_DESPUES, self::EARLY]);
        } else {
            $this->write(sprintf('«%s»: los pasos ya no están como se escribieron, no se tocan.', self::EARLY));
        }

        $conocimiento = $this->connection->fetchOne('SELECT contenido FROM agent_conocimiento WHERE nombre_interno = ?', [self::EQUIPAJE_PUBLICO]);

        if (is_string($conocimiento) && !str_contains($conocimiento, 'SI PREGUNTA EL COSTO')) {
            $this->addSql('UPDATE agent_conocimiento SET contenido = ? WHERE nombre_interno = ?', [$conocimiento . self::EQUIPAJE_PUBLICO_COSTO, self::EQUIPAJE_PUBLICO]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('El texto anterior no se guarda: está en git (Version20261002231029).');
    }
}
