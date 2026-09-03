<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El duplicado deja de ser un sí/no y pasa a decir DE CUÁL es copia.
 *
 * Un booleano bastaba para la marca y para desbloquear el borrado, y se quedaba corto en cuanto
 * hay dos copias en el mismo servicio: decía que las dos eran copias, no de cuál. Con el id, el
 * reparto es un conjunto identificable y **se puede sumar**.
 *
 * ⚠️ Se cambia sin migrar datos porque se comprobó que **no había ninguna fila con
 * `es_duplicado = 1`** en producción: el campo se desplegó hoy y nadie llegó a usarlo.
 *
 * ⚠️ Escrita a mano: `make:migration` sigue queriendo borrar `energia_*` y el respaldo
 * `pms_evento_calendario_backup_20260808`.
 */
final class Version20260903143639 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Componente: de qué componente es copia, en vez de un sí/no.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotcomponente ADD duplicado_de VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE cotizacion_cotcomponente DROP es_duplicado');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotcomponente ADD es_duplicado TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE cotizacion_cotcomponente DROP duplicado_de');
    }
}
