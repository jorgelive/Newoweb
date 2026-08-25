<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ubicaciones propias de los componentes MANUALES de una cotización.
 *
 * Los de catálogo las resuelven en vivo contra `travel_componente_lugar_pool` a través de
 * `componente_maestro_id`; los manuales no tienen a quién preguntarle y salían sin etiqueta en
 * el cuadro de tráfico. Uuids de `travel_lugar` en RFC 4122, sin clave ajena: es el mismo
 * desacople deliberado que ya tiene `componente_maestro_id`.
 *
 * ⚠️ Escrita a mano. `doctrine:migrations:diff` arrastraba además el borrado de las tablas
 * `energia_*` y `pms_evento_calendario_backup_20260808`, que están en la base y no en el
 * mapeo — eso es deriva que se limpia aparte y a conciencia, no de rebote en esta migración.
 *
 * ⚠️ En TRES pasos y no en uno: MySQL 5.7 no admite `DEFAULT` en una columna JSON, así que la
 * columna entra nullable, se rellenan las filas que ya existen y sólo entonces se cierra a NOT
 * NULL. Crearla NOT NULL de golpe no tiene con qué rellenar lo que ya está en la tabla.
 */
final class Version20260825145154 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cotizacion_cotcomponente: ubicaciones propias de los componentes manuales';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotcomponente ADD lugares_manuales JSON DEFAULT NULL');
        $this->addSql("UPDATE cotizacion_cotcomponente SET lugares_manuales = '[]' WHERE lugares_manuales IS NULL");
        $this->addSql('ALTER TABLE cotizacion_cotcomponente MODIFY lugares_manuales JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotcomponente DROP lugares_manuales');
    }
}
