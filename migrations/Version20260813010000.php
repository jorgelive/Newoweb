<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El conocimiento generico del agente: respuestas cortas a lo que preguntan todo el rato.
 *
 * Hoy, cuando ninguna skill encaja, el agente escala al equipo. Y buena parte de lo que escala
 * son preguntas repetidas cuya respuesta no cambia nunca -si hay estacionamiento, a que hora
 * abre la puerta, si aceptan Yape-. Cada una es una persona interrumpida para decir lo mismo
 * otra vez. Estas dos tablas son la ULTIMA RED antes de ese escalado.
 *
 * ── Dos tablas, y la de categorias no es un enum ────────────────────────────
 * El conocimiento se consulta en dos fases: primero el modelo elige CATEGORIA (unas lineas) y
 * despues se le ensenan solo las ETIQUETAS de esa categoria. Sin ese corte habria que mandarle
 * la tabla entera en cada turno, y esta pensada para crecer mucho: se alimenta con el tiempo,
 * cada vez que alguien nota que una pregunta se repite.
 *
 * La categoria es tabla y no enum porque se alimenta DESDE EL PANEL. Con un enum, anadir
 * «reclamos» a las nueve de la noche significa no anadirla.
 *
 * ── Nace vacia ──────────────────────────────────────────────────────────────
 * Sin categorias no hay primera fase, asi que el agente se comporta exactamente como hoy. Lo
 * que se despliega es la posibilidad, no un cambio de conducta.
 */
final class Version20260813010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'agent_conocimiento y su categoria: la ultima red antes de escalar por desconocimiento';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE agent_conocimiento (id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', categoria_id VARCHAR(40) NOT NULL, nombre_interno VARCHAR(160) NOT NULL, etiquetas LONGTEXT NOT NULL, contenido LONGTEXT NOT NULL, dominios JSON DEFAULT NULL, perfiles JSON DEFAULT NULL, requiere_humano TINYINT(1) DEFAULT 0 NOT NULL, activo TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX idx_conocimiento_categoria (categoria_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE agent_conocimiento_categoria (id VARCHAR(40) NOT NULL, nombre VARCHAR(120) NOT NULL, pista VARCHAR(255) DEFAULT NULL, activa TINYINT(1) DEFAULT 1 NOT NULL, orden INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE agent_conocimiento ADD CONSTRAINT FK_656069E23397707A FOREIGN KEY (categoria_id) REFERENCES agent_conocimiento_categoria (id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agent_conocimiento DROP FOREIGN KEY FK_656069E23397707A');
        $this->addSql('DROP TABLE agent_conocimiento');
        $this->addSql('DROP TABLE agent_conocimiento_categoria');
    }
}
