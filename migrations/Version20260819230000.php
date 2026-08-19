<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Travel: desaparece «proveedor» de las columnas. Cada una pasa al nombre de lo que ES.
 *
 * ### Dos nombres distintos, no uno
 *
 * El mismo `proveedor_id` significaba dos cosas según la tabla, y por eso no es un renombre
 * sino dos:
 *
 * | Tabla | Antes | Ahora | Porque ahí es… |
 * |---|---|---|---|
 * | `travel_componente` | `proveedor_id` | `prestador_id` | **el papel**: quién presta el componente |
 * | `travel_componente` | `proveedor_servicio_id` | `prestador_servicio_id` | idem |
 * | `travel_organizacion_servicio` | `proveedor_id` | `organizacion_id` | **el padre** |
 * | `travel_organizacion_imagen` | `proveedor_id` | `organizacion_id` | el padre |
 * | `travel_organizacion_servicio_imagen` | `proveedor_servicio_id` | `organizacion_servicio_id` | el padre |
 * | `travel_organizacion_lugar_pool` | `proveedor_id` | `organizacion_id` | el padre |
 * | `travel_tarifa` | `nombre_para_proveedor` | `nombre_para_prestador` | cómo la llama quien presta |
 *
 * ### ⚠️ `CHANGE`, nunca `ADD` + `DROP`
 *
 * `doctrine:schema:update` proponía para `travel_componente` un `ADD prestador_id … DROP
 * proveedor_id`: eso crea una columna VACÍA y tira la que tenía los datos. Aquí va `CHANGE`,
 * que renombra conservando el contenido — igual que Doctrine sí hizo para las demás tablas.
 * Si algún día se regenera esta migración desde el diff, hay que volver a corregirlo.
 *
 * Las claves foráneas y los índices se rehacen con los nombres que Doctrine espera, para que
 * `doctrine:schema:validate` quede en verde y no se acostumbre nadie a verlo rojo.
 */
final class Version20260819230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'travel: `proveedor_id` pasa a `prestador_id` (papel) o `organizacion_id` (padre), según la tabla.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnaExiste('travel_componente', 'proveedor_id')) {
            $this->write('  · travel_componente.proveedor_id ya no existe; se omite el lote.');

            return;
        }

        // ── travel_componente: el PAPEL ──────────────────────────────────────────
        $this->addSql('ALTER TABLE travel_componente DROP FOREIGN KEY FK_componente_proveedor');
        $this->addSql('ALTER TABLE travel_componente DROP FOREIGN KEY FK_componente_proveedor_servicio');
        $this->addSql('DROP INDEX IDX_4329A17FCB305D73 ON travel_componente');
        $this->addSql('DROP INDEX IDX_4329A17FDB905830 ON travel_componente');
        $this->addSql(<<<'SQL'
            ALTER TABLE travel_componente
                CHANGE proveedor_id prestador_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)',
                CHANGE proveedor_servicio_id prestador_servicio_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)'
            SQL);
        $this->addSql('ALTER TABLE travel_componente ADD CONSTRAINT FK_4329A17FD2DB9D53 FOREIGN KEY (prestador_id) REFERENCES travel_organizacion (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE travel_componente ADD CONSTRAINT FK_4329A17F6B93C1A FOREIGN KEY (prestador_servicio_id) REFERENCES travel_organizacion_servicio (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_4329A17FD2DB9D53 ON travel_componente (prestador_id)');
        $this->addSql('CREATE INDEX IDX_4329A17F6B93C1A ON travel_componente (prestador_servicio_id)');

        // ── las hijas: el PADRE ──────────────────────────────────────────────────
        $this->addSql('ALTER TABLE travel_organizacion_servicio DROP FOREIGN KEY FK_DD9E59B3CB305D73');
        $this->addSql('DROP INDEX IDX_EBED15C0CB305D73 ON travel_organizacion_servicio');
        $this->addSql("ALTER TABLE travel_organizacion_servicio CHANGE proveedor_id organizacion_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)'");
        $this->addSql('ALTER TABLE travel_organizacion_servicio ADD CONSTRAINT FK_EBED15C090B1019E FOREIGN KEY (organizacion_id) REFERENCES travel_organizacion (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_EBED15C090B1019E ON travel_organizacion_servicio (organizacion_id)');

        $this->addSql('ALTER TABLE travel_organizacion_imagen DROP FOREIGN KEY FK_56179730CB305D73');
        $this->addSql('DROP INDEX IDX_3A66B235CB305D73 ON travel_organizacion_imagen');
        $this->addSql("ALTER TABLE travel_organizacion_imagen CHANGE proveedor_id organizacion_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)'");
        $this->addSql('ALTER TABLE travel_organizacion_imagen ADD CONSTRAINT FK_3A66B23590B1019E FOREIGN KEY (organizacion_id) REFERENCES travel_organizacion (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_3A66B23590B1019E ON travel_organizacion_imagen (organizacion_id)');

        $this->addSql('ALTER TABLE travel_organizacion_servicio_imagen DROP FOREIGN KEY FK_FA3EC107DB905830');
        $this->addSql('DROP INDEX IDX_3416FCC4DB905830 ON travel_organizacion_servicio_imagen');
        $this->addSql("ALTER TABLE travel_organizacion_servicio_imagen CHANGE proveedor_servicio_id organizacion_servicio_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)'");
        $this->addSql('ALTER TABLE travel_organizacion_servicio_imagen ADD CONSTRAINT FK_3416FCC45A48C177 FOREIGN KEY (organizacion_servicio_id) REFERENCES travel_organizacion_servicio (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_3416FCC45A48C177 ON travel_organizacion_servicio_imagen (organizacion_servicio_id)');

        // El join lleva la columna DENTRO de su clave primaria: hay que rehacerla.
        $this->addSql('ALTER TABLE travel_organizacion_lugar_pool DROP FOREIGN KEY FK_F6713B80CB305D73');
        $this->addSql('DROP INDEX IDX_8D092A7DCB305D73 ON travel_organizacion_lugar_pool');
        $this->addSql('ALTER TABLE travel_organizacion_lugar_pool DROP PRIMARY KEY');
        $this->addSql("ALTER TABLE travel_organizacion_lugar_pool CHANGE proveedor_id organizacion_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)'");
        $this->addSql('ALTER TABLE travel_organizacion_lugar_pool ADD CONSTRAINT FK_8D092A7D90B1019E FOREIGN KEY (organizacion_id) REFERENCES travel_organizacion (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_8D092A7D90B1019E ON travel_organizacion_lugar_pool (organizacion_id)');
        $this->addSql('ALTER TABLE travel_organizacion_lugar_pool ADD PRIMARY KEY (organizacion_id, lugar_id)');

        // ── y el nombre operativo de la tarifa ───────────────────────────────────
        $this->addSql('ALTER TABLE travel_tarifa CHANGE nombre_para_proveedor nombre_para_prestador VARCHAR(150) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$this->columnaExiste('travel_componente', 'prestador_id')) {
            return;
        }

        $this->addSql('ALTER TABLE travel_tarifa CHANGE nombre_para_prestador nombre_para_proveedor VARCHAR(150) DEFAULT NULL');

        $this->addSql('ALTER TABLE travel_organizacion_lugar_pool DROP FOREIGN KEY FK_8D092A7D90B1019E');
        $this->addSql('DROP INDEX IDX_8D092A7D90B1019E ON travel_organizacion_lugar_pool');
        $this->addSql('ALTER TABLE travel_organizacion_lugar_pool DROP PRIMARY KEY');
        $this->addSql("ALTER TABLE travel_organizacion_lugar_pool CHANGE organizacion_id proveedor_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)'");
        $this->addSql('ALTER TABLE travel_organizacion_lugar_pool ADD CONSTRAINT FK_F6713B80CB305D73 FOREIGN KEY (proveedor_id) REFERENCES travel_organizacion (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_8D092A7DCB305D73 ON travel_organizacion_lugar_pool (proveedor_id)');
        $this->addSql('ALTER TABLE travel_organizacion_lugar_pool ADD PRIMARY KEY (proveedor_id, lugar_id)');

        $this->addSql('ALTER TABLE travel_organizacion_servicio_imagen DROP FOREIGN KEY FK_3416FCC45A48C177');
        $this->addSql('DROP INDEX IDX_3416FCC45A48C177 ON travel_organizacion_servicio_imagen');
        $this->addSql("ALTER TABLE travel_organizacion_servicio_imagen CHANGE organizacion_servicio_id proveedor_servicio_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)'");
        $this->addSql('ALTER TABLE travel_organizacion_servicio_imagen ADD CONSTRAINT FK_FA3EC107DB905830 FOREIGN KEY (proveedor_servicio_id) REFERENCES travel_organizacion_servicio (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_3416FCC4DB905830 ON travel_organizacion_servicio_imagen (proveedor_servicio_id)');

        $this->addSql('ALTER TABLE travel_organizacion_imagen DROP FOREIGN KEY FK_3A66B23590B1019E');
        $this->addSql('DROP INDEX IDX_3A66B23590B1019E ON travel_organizacion_imagen');
        $this->addSql("ALTER TABLE travel_organizacion_imagen CHANGE organizacion_id proveedor_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)'");
        $this->addSql('ALTER TABLE travel_organizacion_imagen ADD CONSTRAINT FK_56179730CB305D73 FOREIGN KEY (proveedor_id) REFERENCES travel_organizacion (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_3A66B235CB305D73 ON travel_organizacion_imagen (proveedor_id)');

        $this->addSql('ALTER TABLE travel_organizacion_servicio DROP FOREIGN KEY FK_EBED15C090B1019E');
        $this->addSql('DROP INDEX IDX_EBED15C090B1019E ON travel_organizacion_servicio');
        $this->addSql("ALTER TABLE travel_organizacion_servicio CHANGE organizacion_id proveedor_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)'");
        $this->addSql('ALTER TABLE travel_organizacion_servicio ADD CONSTRAINT FK_DD9E59B3CB305D73 FOREIGN KEY (proveedor_id) REFERENCES travel_organizacion (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_EBED15C0CB305D73 ON travel_organizacion_servicio (proveedor_id)');

        $this->addSql('ALTER TABLE travel_componente DROP FOREIGN KEY FK_4329A17FD2DB9D53');
        $this->addSql('ALTER TABLE travel_componente DROP FOREIGN KEY FK_4329A17F6B93C1A');
        $this->addSql('DROP INDEX IDX_4329A17FD2DB9D53 ON travel_componente');
        $this->addSql('DROP INDEX IDX_4329A17F6B93C1A ON travel_componente');
        $this->addSql(<<<'SQL'
            ALTER TABLE travel_componente
                CHANGE prestador_id proveedor_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)',
                CHANGE prestador_servicio_id proveedor_servicio_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)'
            SQL);
        $this->addSql('ALTER TABLE travel_componente ADD CONSTRAINT FK_componente_proveedor FOREIGN KEY (proveedor_id) REFERENCES travel_organizacion (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE travel_componente ADD CONSTRAINT FK_componente_proveedor_servicio FOREIGN KEY (proveedor_servicio_id) REFERENCES travel_organizacion_servicio (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_4329A17FCB305D73 ON travel_componente (proveedor_id)');
        $this->addSql('CREATE INDEX IDX_4329A17FDB905830 ON travel_componente (proveedor_servicio_id)');
    }

    private function columnaExiste(string $tabla, string $columna): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$tabla, $columna]
        ) > 0;
    }
}
