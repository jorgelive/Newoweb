<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260731042857 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade channel_id y referencia_canal a pms_evento_beds24_link. Normaliza datos históricos copiando desde el evento solo para links principales.';
    }

    public function up(Schema $schema): void
    {
        // 1. DDL: nuevas columnas en el link
        $this->addSql('ALTER TABLE pms_evento_beds24_link ADD channel_id VARCHAR(50) DEFAULT NULL, ADD referencia_canal VARCHAR(150) DEFAULT NULL');
        $this->addSql('ALTER TABLE pms_evento_beds24_link ADD CONSTRAINT FK_5C98996372F5A1AA FOREIGN KEY (channel_id) REFERENCES pms_channel (id)');
        $this->addSql('CREATE INDEX IDX_5C98996372F5A1AA ON pms_evento_beds24_link (channel_id)');

        // 2. Data migration: copiar channel_id y referencia_canal desde el evento
        //    Solo para links principales (es_principal = 1) que tengan un evento asociado
        //    con datos OTA. Los espejos (mirrors) quedan con NULL intencionalmente.
        $this->addSql('
            UPDATE pms_evento_beds24_link l
            INNER JOIN pms_evento_calendario e ON e.id = l.evento_id
            SET
                l.channel_id       = e.channel_id,
                l.referencia_canal = e.referencia_canal
            WHERE
                l.es_principal = 1
                AND (e.channel_id IS NOT NULL OR e.referencia_canal IS NOT NULL)
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_evento_beds24_link DROP FOREIGN KEY FK_5C98996372F5A1AA');
        $this->addSql('DROP INDEX IDX_5C98996372F5A1AA ON pms_evento_beds24_link');
        $this->addSql('ALTER TABLE pms_evento_beds24_link DROP channel_id, DROP referencia_canal');
    }
}
