<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cuánto se le pide por adelantado al huésped, configurable por establecimiento virtual.
 *
 * Era una regla comercial no escrita —«prepago de la primera noche»— y cambia por temporada,
 * así que pasa a ser dato. Arranca en `sin_prepago` para TODOS: una migración no debe empezar
 * a pedirle dinero por adelantado a nadie por su cuenta. Se activa establecimiento a
 * establecimiento desde el panel.
 */
final class Version20260809235106 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Política de prepago por establecimiento virtual (primera noche / 50 %, sobre total o alojamiento).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_establecimiento_virtual ADD politica_prepago VARCHAR(30) DEFAULT \'sin_prepago\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_establecimiento_virtual DROP politica_prepago');
    }
}
