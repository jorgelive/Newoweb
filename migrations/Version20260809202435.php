<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Resumen IA de lo que el huésped pidió y aún no se ha contestado.
 *
 * `resumen_ia` guarda la frase que generan los workers; `resumen_ia_hasta` la fecha del
 * mensaje más reciente ya reflejado en ella, que es lo que evita pagar una llamada al
 * modelo por cada trozo de una ráfaga. Ambas nullable: sin resumen, quien lo pinta cae al
 * texto del último mensaje. Ver docs/Mensajeria.md.
 */
final class Version20260809202435 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade resumen_ia y resumen_ia_hasta a msg_conversation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE msg_conversation ADD resumen_ia LONGTEXT DEFAULT NULL, ADD resumen_ia_hasta DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE msg_conversation DROP resumen_ia, DROP resumen_ia_hasta');
    }
}
