<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Enlaza «Early check in Late Check Out» a las secciones «Ingreso a la casa» que le faltaban.
 *
 * El ítem existía **sólo en la guía de la Casita 1**. En las otras seis no estaba, así que
 * aquellos huéspedes no tenían publicado ni el horario de entrada y salida, ni las condiciones
 * de la entrada temprana, ni —desde hoy— lo del almacén de equipaje. Se descubrió probando el
 * agente: a «llego a las 10, ¿puedo dejar las maletas?» contestaba escalando, porque para su
 * casita ese tema no existía.
 *
 * No es un fallo del código: la sección «Ingreso a la casa» está **duplicada, una por casita**,
 * y al crear el ítem se enganchó a una sola. Este tipo de omisión no la detecta nada — cada
 * guía se ve completa por separado.
 *
 * ### Dónde se inserta
 *
 * En la misma posición relativa que tiene en la Casita 1: **justo después del ítem «Puerta»**
 * de esa sección, empujando un puesto a los que van detrás. Se calcula por sección en vez de
 * fijar `orden = 3` porque el orden es dato, no constante: si mañana alguien reordena una
 * casita, esto sigue poniéndolo donde toca.
 *
 * Si una sección no tuviera «Puerta», se añade al final. No pasa hoy en ninguna.
 *
 * Idempotente: sólo toca las secciones que **no** tienen ya el ítem.
 */
final class Version20260810160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade el ítem de horarios/equipaje a las secciones «Ingreso a la casa» de las 6 casitas que no lo tenían.';
    }

    public function up(Schema $schema): void
    {
        $item = $this->connection->fetchOne(
            "SELECT id FROM pms_guia_item WHERE nombre_interno = 'Early check in Late Check Out'"
        );

        if ($item === false) {
            $this->write('  El ítem no existe en esta base; no hay nada que enlazar.');

            return;
        }

        // Secciones «Ingreso a la casa» que cuelgan de una guía y NO tienen ya el ítem.
        $secciones = $this->connection->fetchAllAssociative(
            "SELECT DISTINCT s.id
               FROM pms_guia_seccion s
               JOIN pms_guia_has_seccion ghs ON ghs.seccion_id = s.id
              WHERE JSON_UNQUOTE(JSON_EXTRACT(s.titulo, '$[0].content')) = 'Ingreso a la casa'
                AND NOT EXISTS (
                      SELECT 1 FROM pms_guia_seccion_has_item x
                       WHERE x.seccion_id = s.id AND x.item_id = :item
                )",
            ['item' => $item]
        );

        $enlazadas = 0;

        foreach ($secciones as $seccion) {
            $posicion = $this->posicionDestino($seccion['id'], $item);

            // Hueco para el nuevo: todo lo que va detrás baja un puesto. Va antes del INSERT
            // porque si no, el propio ítem recién metido entraría en el UPDATE.
            $this->addSql(
                'UPDATE pms_guia_seccion_has_item SET orden = orden + 1
                  WHERE seccion_id = :seccion AND orden >= :desde',
                ['seccion' => $seccion['id'], 'desde' => $posicion]
            );

            $this->addSql(
                'INSERT INTO pms_guia_seccion_has_item (id, seccion_id, item_id, orden, activo, created_at)
                 VALUES (:id, :seccion, :item, :orden, 1, NOW())',
                [
                    'id' => Uuid::v7()->toBinary(),
                    'seccion' => $seccion['id'],
                    'item' => $item,
                    'orden' => $posicion,
                ]
            );

            ++$enlazadas;
        }

        $this->write(sprintf('  Enlazado en %d sección(es) que no lo tenían.', $enlazadas));
    }

    public function down(Schema $schema): void
    {
        // Se quita de todas MENOS de donde ya estaba antes: la sección de la Casita 1, que es
        // la única que tenía el ítem cuando esta migración corrió. Identificarla por el hecho
        // de contener «Puerta (casa 1)» es más honesto que guardar un id: si alguien reorganizó
        // las guías, esto no borra lo que no puso.
        $this->addSql(
            "DELETE shi FROM pms_guia_seccion_has_item shi
               JOIN pms_guia_item i ON i.id = shi.item_id
              WHERE i.nombre_interno = 'Early check in Late Check Out'
                AND shi.seccion_id NOT IN (
                      SELECT seccion_id FROM (
                        SELECT x.seccion_id
                          FROM pms_guia_seccion_has_item x
                          JOIN pms_guia_item xi ON xi.id = x.item_id
                         WHERE xi.nombre_interno = 'Puerta (casa 1)'
                      ) AS casa1
                )"
        );
    }

    /**
     * Justo detrás del ítem «Puerta» de esa sección, que es donde está en la Casita 1.
     *
     * Sin «Puerta», al final. Es el caso que hoy no se da, pero devolver 0 metería el horario
     * por delante de la ubicación, que es lo primero que necesita quien va llegando.
     */
    private function posicionDestino(string $seccionId, string $itemId): int
    {
        $tras = $this->connection->fetchOne(
            "SELECT shi.orden
               FROM pms_guia_seccion_has_item shi
               JOIN pms_guia_item i ON i.id = shi.item_id
              WHERE shi.seccion_id = :seccion AND i.nombre_interno LIKE 'Puerta%'
              ORDER BY shi.orden DESC LIMIT 1",
            ['seccion' => $seccionId]
        );

        if ($tras !== false) {
            return ((int) $tras) + 1;
        }

        $ultimo = $this->connection->fetchOne(
            'SELECT MAX(orden) FROM pms_guia_seccion_has_item WHERE seccion_id = :seccion',
            ['seccion' => $seccionId]
        );

        return $ultimo === false || $ultimo === null ? 0 : ((int) $ultimo) + 1;
    }
}
