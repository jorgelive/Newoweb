<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La unidad sabe decir cuántas habitaciones y qué camas tiene.
 *
 * `capacidad` dice cuántos CABEN, y con eso no se puede contestar «quiero estar más cómodo»:
 * dos grupos de 8 caben igual en la Casita 3 (2 habitaciones) que en la 6 (3, con dos baños
 * privados), y no es lo mismo. Comodidad es privacidad, no aforo.
 *
 * El dato existía, en prosa, dentro del ítem «Descripción» de cada guía —el bloque
 * «Distribución»—. Pero leerlo obliga a una llamada por casita y a comparar dos textos largos,
 * así que no servía para PROPONER: sólo para describir una casita ya elegida.
 *
 * ── Espejo, y dicho en voz alta ─────────────────────────────────────────────
 * Estas tres columnas son un RESUMEN de lo que ya dice la guía, no una segunda fuente. La guía
 * sigue siendo lo que el cliente lee; esto es lo que viaja en cada cotización para poder
 * comparar. Al cambiar uno hay que mirar el otro, y así está anotado en `PmsUnidad::$camas`.
 *
 * ── La siembra sale de la guía, no de la nada ───────────────────────────────
 * Los valores de abajo están transcritos del bloque «Distribución» de cada casita, y cuadran
 * con la capacidad declarada en seis de las siete: la Casita 6 suma 12 plazas en camas (tres
 * habitaciones con dos dobles) y tiene `capacidad = 10`, que es un tope de política y no un
 * error de este dato.
 *
 * Sólo escribe donde la columna está en NULL, así que es reejecutable y no pisa nada ajustado
 * a mano desde el panel.
 */
final class Version20260811210000 extends AbstractMigration
{
    /**
     * nombre de la unidad => [habitaciones, camas, baños privados].
     *
     * Se siembra por NOMBRE y no por id porque los ids son UUID por entorno: en local y en
     * producción no coinciden, y una migración que sólo funciona en una base es media
     * migración. El `WHERE ... IS NULL` protege de que un nombre repetido pise algo.
     */
    private const array ACOMODACION = [
        'Casita 1' => [2, 'Hab 1: 2 individuales + 1 doble · Hab 2: 2 dobles', 2],
        'Casita 2' => [2, 'Hab 1: 2 dobles + 1 individual · Hab 2: 1 doble', 0],
        'Casita 3' => [2, 'Hab 1: 2 dobles · Hab 2: 2 dobles', 0],
        'Casita 4' => [1, '1 doble + 2 individuales', 0],
        'Casita 5' => [1, '1 doble', 0],
        'Casita 6' => [3, 'Hab 1, 2 y 3: 2 dobles cada una', 2],
        'Casita 7' => [2, 'Hab 1: 3 dobles · Hab 2: 2 dobles', 1],
    ];

    public function getDescription(): string
    {
        return 'pms_unidad: habitaciones, camas y banos_privados, sembrados desde la guía';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE pms_unidad
                ADD habitaciones SMALLINT DEFAULT NULL,
                ADD camas VARCHAR(255) DEFAULT NULL,
                ADD banos_privados SMALLINT DEFAULT NULL
        SQL);

        foreach (self::ACOMODACION as $nombre => [$habitaciones, $camas, $banos]) {
            $this->addSql(
                <<<'SQL'
                    UPDATE pms_unidad
                       SET habitaciones = :habitaciones,
                           camas = :camas,
                           banos_privados = :banos
                     WHERE nombre = :nombre
                       AND habitaciones IS NULL
                SQL,
                [
                    'habitaciones' => $habitaciones,
                    'camas' => $camas,
                    'banos' => $banos,
                    'nombre' => $nombre,
                ]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_unidad DROP habitaciones, DROP camas, DROP banos_privados');
    }
}
