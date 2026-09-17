<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Oweb archivado: `user` deja de enlazar con la dependencia y el área del panel Sonata.
 *
 * `User` tenía dos `ManyToOne` hacia tablas de Oweb (`use_dependencia`, `use_area`) que sólo usaba
 * ese panel. Con el módulo retirado, las dos columnas quedan sin uso y `doctrine:schema:validate`
 * se pondría en rojo pidiendo quitarlas —y un rojo permanente enseña a no mirar la única
 * herramienta que compara las dos mitades de una relación—.
 *
 * ⚠️ **Sólo se tocan estas dos columnas.** Las tablas de Oweb (`use_area`, `use_dependencia` y el
 * resto) **se quedan con sus datos**: no se borra, se marca. Borrarlas sería otra decisión.
 *
 * ⚠️ **Las claves foráneas van PRIMERO, y Doctrine no las propone.** Su `schema:update` sólo sacaba
 * `DROP INDEX` y `DROP COLUMN`, porque ya no conoce las tablas de destino; MySQL no deja borrar un
 * índice que sostiene una clave foránea viva, así que ese SQL habría fallado a mitad. Nombres
 * comprobados en local y en producción el 17/09/2026.
 *
 * Lo que se pierde: la dependencia y el área de 5 usuarios, que sin Oweb no significan nada. Hay
 * volcado completo de producción del mismo día.
 */
final class Version20260917120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Oweb archivado: quita user.dependencia_id y user.area_id (claves foráneas, índices y columnas).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP FOREIGN KEY FK_C560D761DF2432B6');
        $this->addSql('ALTER TABLE user DROP FOREIGN KEY FK_C560D761BD0F409C');
        $this->addSql('DROP INDEX IDX_8D93D649DF2432B6 ON user');
        $this->addSql('DROP INDEX IDX_8D93D649BD0F409C ON user');
        $this->addSql('ALTER TABLE user DROP dependencia_id, DROP area_id');
    }

    /**
     * Devuelve la ESTRUCTURA, no los datos: los valores de esas columnas están en el volcado.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD dependencia_id INT DEFAULT NULL, ADD area_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_8D93D649DF2432B6 ON user (dependencia_id)');
        $this->addSql('CREATE INDEX IDX_8D93D649BD0F409C ON user (area_id)');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_C560D761DF2432B6 FOREIGN KEY (dependencia_id) REFERENCES use_dependencia (id)');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_C560D761BD0F409C FOREIGN KEY (area_id) REFERENCES use_area (id)');
    }
}
