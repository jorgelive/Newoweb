<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un componente puede acotarse a VARIOS subgrupos, no a uno.
 *
 * Con uno solo, el vuelo JA7018 —32 personas en 7 PNRs— exigía siete copias del mismo componente:
 * mismo vuelo, misma hora, misma orden al proveedor. Y atarlo a `CotizacionVuelo` habría resuelto
 * el caso aéreo y sólo ése; con una lista de subgrupos el mismo campo sirve para habitaciones,
 * servicios opcionales y cualquier corte arbitrario del eje `GRUPO`.
 *
 * ⚠️ Se cambia sin migrar datos: se comprobó en producción que **ninguna fila tenía `grupo_id`**.
 * El campo se desplegó hoy y nadie llegó a usarlo.
 *
 * ⚠️ Escrita a mano: `make:migration` sigue queriendo borrar `energia_*` y el respaldo
 * `pms_evento_calendario_backup_20260808`.
 */
final class Version20260903151108 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Componente → VARIOS subgrupos, en vez de uno.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE cotizacion_cotcomponente_grupo (
            cotcomponente_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
            grupo_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
            INDEX IDX_D545D65EA9D07FC6 (cotcomponente_id),
            INDEX IDX_D545D65E9C833003 (grupo_id),
            PRIMARY KEY(cotcomponente_id, grupo_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql('ALTER TABLE cotizacion_cotcomponente_grupo ADD CONSTRAINT FK_ccg_componente FOREIGN KEY (cotcomponente_id) REFERENCES cotizacion_cotcomponente (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cotizacion_cotcomponente_grupo ADD CONSTRAINT FK_ccg_grupo FOREIGN KEY (grupo_id) REFERENCES cotizacion_file_grupo (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE cotizacion_cotcomponente DROP FOREIGN KEY FK_30B080E29C833003');
        $this->addSql('DROP INDEX IDX_30B080E29C833003 ON cotizacion_cotcomponente');
        $this->addSql('ALTER TABLE cotizacion_cotcomponente DROP grupo_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE cotizacion_cotcomponente ADD grupo_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)'");
        $this->addSql('ALTER TABLE cotizacion_cotcomponente ADD CONSTRAINT FK_30B080E29C833003 FOREIGN KEY (grupo_id) REFERENCES cotizacion_file_grupo (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_30B080E29C833003 ON cotizacion_cotcomponente (grupo_id)');
        $this->addSql('DROP TABLE cotizacion_cotcomponente_grupo');
    }
}
