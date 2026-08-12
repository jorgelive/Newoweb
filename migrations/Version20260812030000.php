<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La limpieza pasa a UN importe y UN interruptor, en vez de dos columnas.
 *
 * La versión anterior (`Version20260812010000`) añadió `porcentaje_limpieza` al lado de
 * `precio_limpieza`, y la regla era «si hay porcentaje, manda». Funcionaba, pero la pregunta
 * «¿cuál se cobra si las dos tienen valor?» sólo estaba respondida en el código: mirando la
 * fila no se sabe. Basta olvidarse de poner una a cero al cambiar de criterio para cobrar lo
 * que no era, y sin que nada chirríe.
 *
 * Ahora es un valor y un flag: `precio_limpieza = 15.00` son 15 dólares o un 15% del
 * alojamiento según `limpieza_es_porcentaje`. El dato dice por sí solo lo que significa, y
 * cambiar de criterio es un clic sin retecleаr el número.
 *
 * ── El traspaso ─────────────────────────────────────────────────────────────
 * Donde hubiera un porcentaje puesto, se mueve al importe y se enciende el flag. Hoy no hay
 * ninguno —la columna nació en 0.00 hace unas horas y nadie ha decidido todavía qué casitas
 * pasan a porcentaje—, pero se escribe igual: una migración que sólo funciona si los datos
 * están como esperabas no es una migración.
 *
 * En 0.00 no se cobra limpieza tenga el flag el valor que tenga, y entonces no aparece en la
 * cotización ni como línea de cero.
 */
final class Version20260812030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'pms_unidad: limpieza_es_porcentaje sustituye a porcentaje_limpieza';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE pms_unidad ADD limpieza_es_porcentaje TINYINT(1) DEFAULT 0 NOT NULL'
        );

        // El porcentaje que hubiera pasa a ser EL importe, con el flag encendido.
        $this->addSql(<<<'SQL'
            UPDATE pms_unidad
               SET precio_limpieza = porcentaje_limpieza,
                   limpieza_es_porcentaje = 1
             WHERE porcentaje_limpieza > 0
        SQL);

        $this->addSql('ALTER TABLE pms_unidad DROP porcentaje_limpieza');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE pms_unidad ADD porcentaje_limpieza NUMERIC(5, 2) DEFAULT '0.00' NOT NULL"
        );

        // La vuelta deja el importe a 0: era un porcentaje, y dejarlo como dinero cobraría
        // 15.00 USD donde había un 15%.
        $this->addSql(<<<'SQL'
            UPDATE pms_unidad
               SET porcentaje_limpieza = precio_limpieza,
                   precio_limpieza = '0.00'
             WHERE limpieza_es_porcentaje = 1
        SQL);

        $this->addSql('ALTER TABLE pms_unidad DROP limpieza_es_porcentaje');
    }
}
