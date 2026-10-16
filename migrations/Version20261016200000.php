<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `maestro_moneda.titulo`: el nombre de la moneda para el cliente, en cada idioma. Ver
 * `MaestroMoneda::$titulo`.
 *
 * Se añade NULABLE, se rellena con el español de `nombre` y sólo entonces pasa a `NOT NULL`: una
 * columna JSON NOT NULL añadida a una tabla con filas nace con el literal JSON `null`, que no es
 * SQL NULL y revienta al leer (CLAUDE.md). Los demás idiomas los pone `app:maestro:monedas:titulos`
 * por el ORM, a mano: una palabra suelta la traduce mal el traductor automático.
 *
 * Y borra `gui_moneda_pen` / `gui_moneda_usd` de los textos de pax: ver el comentario abajo.
 */
final class Version20261016200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Maestro de monedas: título i18n para el cliente';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE maestro_moneda ADD titulo JSON DEFAULT NULL, ADD sobreescribir_traduccion TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql("UPDATE maestro_moneda SET titulo = JSON_ARRAY(JSON_OBJECT('language', 'es', 'content', nombre)) WHERE titulo IS NULL OR JSON_TYPE(titulo) = 'NULL'");
        $this->addSql('ALTER TABLE maestro_moneda MODIFY titulo JSON NOT NULL');

        // Las dos cadenas sueltas que hicieron este papel unas horas (`pax:textos:guia`): el nombre
        // de la moneda es del maestro, y una copia en los textos de la interfaz acabaría diciendo
        // otra cosa. Nadie las lee ya.
        $this->addSql("DELETE FROM pax_ui_i18n WHERE id IN ('gui_moneda_pen', 'gui_moneda_usd')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE maestro_moneda DROP titulo, DROP sobreescribir_traduccion');
    }
}
