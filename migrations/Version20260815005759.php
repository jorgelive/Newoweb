<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Módulo Domótica: las tres tablas del parque de aparatos inteligentes.
 *
 * `domotica_dispositivo` (el enchufe), `domotica_suscripcion` (lo que se le imputa a una estancia)
 * y `domotica_lectura` (la bitácora horaria, que es la fuente de verdad).
 *
 * ⚠️ ESCRITA A MANO. `make:migration` generó tres cosas que NO pueden ir a producción:
 *
 * 1. `DROP TABLE energia_*` — el módulo se llamó `Energia` un rato y esas tablas llegaron a
 *    existir en la base de desarrollo. En producción NO existen, así que el DROP reventaría el
 *    despliegue a mitad, dejando las tablas nuevas a medio crear.
 * 2. `DROP TABLE pms_evento_calendario_backup_` — una copia de seguridad manual que alguien dejó
 *    en desarrollo. No tiene nada que ver con esto y borrarla desde aquí sería destruir un
 *    respaldo por efecto colateral de otra cosa.
 * 3. Los `ALTER TABLE energia_*` que sueltan las claves ajenas de esas mismas tablas fantasma.
 *
 * Si en desarrollo quedan tablas `energia_*`, se tiran a mano y con criterio — no desde una
 * migración que también corre en producción.
 *
 * No hay datos que migrar: las tres tablas nacen vacías.
 */
final class Version20260815005759 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Domótica: dispositivos, suscripciones a evento y bitácora de lecturas.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE domotica_dispositivo (id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', unidad_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', tuya_device_id VARCHAR(64) NOT NULL, nombre VARCHAR(120) NOT NULL, ubicacion VARCHAR(120) DEFAULT NULL, mide_consumo TINYINT(1) DEFAULT 1 NOT NULL, conmutable TINYINT(1) DEFAULT 1 NOT NULL, encendido TINYINT(1) DEFAULT NULL, estado_tomado_en DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', activo TINYINT(1) DEFAULT 1 NOT NULL, lectura_total NUMERIC(12, 3) DEFAULT NULL, lectura_tomada_en DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', potencia_vatios INT DEFAULT NULL, fallos_consecutivos INT DEFAULT 0 NOT NULL, ultima_alerta_en DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', notas LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_domotica_dispositivo_unidad (unidad_id), UNIQUE INDEX uniq_domotica_dispositivo_tuya (tuya_device_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE domotica_lectura (id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', suscripcion_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', dispositivo_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', registrada_por_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', leida_en DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', cubo_horario VARCHAR(10) DEFAULT NULL, consumo_total NUMERIC(12, 3) NOT NULL, consumo_cliente NUMERIC(12, 3) NOT NULL, incremento NUMERIC(12, 3) DEFAULT \'0.000\' NOT NULL, motivo VARCHAR(20) NOT NULL, nota LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_AFA447D5189E045D (suscripcion_id), INDEX IDX_AFA447D5FD37F77B (dispositivo_id), INDEX IDX_AFA447D5F9F79B0E (registrada_por_id), INDEX idx_domotica_lectura_suscripcion (suscripcion_id, leida_en), INDEX idx_domotica_lectura_dispositivo (dispositivo_id, leida_en), UNIQUE INDEX uniq_domotica_lectura_hora (dispositivo_id, cubo_horario), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE domotica_suscripcion (id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', dispositivo_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', evento_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', inicia_en DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', termina_en DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', lectura_inicial NUMERIC(12, 3) DEFAULT \'0.000\' NOT NULL, consumo_total NUMERIC(12, 3) DEFAULT \'0.000\' NOT NULL, consumo_cliente NUMERIC(12, 3) DEFAULT \'0.000\' NOT NULL, tarifa_soles_kwh NUMERIC(8, 4) NOT NULL, credito_diario_soles NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL, activa TINYINT(1) DEFAULT 1 NOT NULL, cerrada_en DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_DECBE051FD37F77B (dispositivo_id), INDEX idx_domotica_suscripcion_activa (activa), INDEX idx_domotica_suscripcion_evento (evento_id), UNIQUE INDEX uniq_domotica_suscripcion_dispositivo_evento (dispositivo_id, evento_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE domotica_dispositivo ADD CONSTRAINT FK_7EDDB91F9D01464C FOREIGN KEY (unidad_id) REFERENCES pms_unidad (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE domotica_lectura ADD CONSTRAINT FK_AFA447D5189E045D FOREIGN KEY (suscripcion_id) REFERENCES domotica_suscripcion (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE domotica_lectura ADD CONSTRAINT FK_AFA447D5FD37F77B FOREIGN KEY (dispositivo_id) REFERENCES domotica_dispositivo (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE domotica_lectura ADD CONSTRAINT FK_AFA447D5F9F79B0E FOREIGN KEY (registrada_por_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE domotica_suscripcion ADD CONSTRAINT FK_DECBE051FD37F77B FOREIGN KEY (dispositivo_id) REFERENCES domotica_dispositivo (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE domotica_suscripcion ADD CONSTRAINT FK_DECBE05187A5F842 FOREIGN KEY (evento_id) REFERENCES pms_evento_calendario (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Sin `DROP` de las claves ajenas: al tirar las tablas se van con ellas. El orden sí
        // importa —primero la que apunta a las otras dos—.
        $this->addSql('DROP TABLE domotica_lectura');
        $this->addSql('DROP TABLE domotica_suscripcion');
        $this->addSql('DROP TABLE domotica_dispositivo');
    }
}
