<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Etiquetas de lugar / centro de operación para los componentes del catálogo.
 *
 * `travel_lugar` es un vocabulario PLANO (Lima, Ica, Cusco, Valle Sagrado) y
 * `travel_componente_lugar_pool` lo engancha a `travel_componente` en N:N. Multivaluado a
 * propósito: un servicio de Ica despachado desde Lima lleva las dos etiquetas. Se descartó la
 * jerarquía porque Valle Sagrado no está dentro de la ciudad de Cusco y el árbol obligaría a
 * afirmar algo falso.
 *
 * ⚠️ ESCRITA A MANO, y aquí importa más que de costumbre. `doctrine:migrations:diff` sobre este
 * proyecto arrastra medio esquema de Travel: `grep "CREATE TABLE travel_" migrations/*.php` no
 * devuelve nada porque esas tablas nacieron con `schema:update`, fuera del sistema de
 * migraciones. El diff intenta "arreglarlas" y ese SQL colado aquí rompe producción. De su
 * salida se copiaron sólo las cuatro sentencias de las dos tablas nuevas, con los nombres de
 * índice y clave ajena que genera Doctrine — cambiarlos por unos legibles dejaría
 * `doctrine:schema:validate` en rojo para siempre.
 *
 * El tercer bloque NO viene del diff y no es opcional: `cotizacion_cotcomponente.componente_maestro_id`
 * es el soft-link contra el que el filtro de lugares del cuadro de tráfico lanza su `IN (...)`,
 * y hoy no tiene índice (sólo existen los de `cotsegmento_id`, `cotservicio_id` y la PK). Sin
 * él, cada clic en un chip provoca un full scan de la tabla.
 *
 * Las tablas nacen vacías: el vocabulario se crea a mano desde EasyAdmin.
 */
final class Version20260815080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Travel: maestro de lugares, pool N:N con componentes e índice del soft-link de cotización.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE travel_lugar (id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', nombre VARCHAR(80) NOT NULL, orden SMALLINT DEFAULT 0 NOT NULL, activo TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_travel_lugar_nombre (nombre), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE travel_componente_lugar_pool (componente_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', lugar_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', INDEX IDX_D6BDA0FFBFE3F144 (componente_id), INDEX IDX_D6BDA0FFB5A3803B (lugar_id), PRIMARY KEY(componente_id, lugar_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE travel_componente_lugar_pool ADD CONSTRAINT FK_D6BDA0FFBFE3F144 FOREIGN KEY (componente_id) REFERENCES travel_componente (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE travel_componente_lugar_pool ADD CONSTRAINT FK_D6BDA0FFB5A3803B FOREIGN KEY (lugar_id) REFERENCES travel_lugar (id) ON DELETE CASCADE');

        // Sin FK a propósito: el soft-link existe justamente para que borrar del catálogo no
        // arrastre historial de cotizaciones. Sólo se indexa para que el filtro sea usable.
        $this->addSql('CREATE INDEX idx_cotcomponente_maestro ON cotizacion_cotcomponente (componente_maestro_id)');
    }

    /**
     * ⚠️ Revertir DESTRUYE todo el etiquetado manual de lugares y no hay forma de recuperarlo:
     * el pool es la única copia, no se replica en cotizaciones ni en operaciones.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_cotcomponente_maestro ON cotizacion_cotcomponente');
        $this->addSql('ALTER TABLE travel_componente_lugar_pool DROP FOREIGN KEY FK_D6BDA0FFBFE3F144');
        $this->addSql('ALTER TABLE travel_componente_lugar_pool DROP FOREIGN KEY FK_D6BDA0FFB5A3803B');
        $this->addSql('DROP TABLE travel_componente_lugar_pool');
        $this->addSql('DROP TABLE travel_lugar');
    }
}
