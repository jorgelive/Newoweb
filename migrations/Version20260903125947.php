<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Marca de duplicado en un componente: lo que permite partir un servicio y deshacerlo.
 *
 * ⚠️ **Escrita a mano, como la anterior.** `make:migration` sigue queriendo hacer `DROP TABLE`
 * de `energia_dispositivo`, `energia_lectura`, `energia_suscripcion` —restos vacíos del
 * renombrado a `Domotica*`— y de `pms_evento_calendario_backup_20260808`, que es un respaldo
 * manual con 19 filas. Limpiar eso es una decisión aparte, con su propia migración.
 */
final class Version20260903125947 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Componente duplicado: marca visible y, sobre todo, borrable.';
    }

    public function up(Schema $schema): void
    {
        // Sin `NOT NULL` inmediato con default: las filas existentes son todas originales, y el
        // default 0 las deja correctas sin tocar ninguna.
        $this->addSql("ALTER TABLE cotizacion_cotcomponente ADD es_duplicado TINYINT(1) DEFAULT 0 NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotcomponente DROP es_duplicado');
    }
}
