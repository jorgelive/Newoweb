<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El componente que una propuesta destaca en su cabecera.
 *
 * Guarda un **puntero**, no contenido: el id del componente cuyo hotel —o isla— es lo que de
 * verdad se vende. La descripción y las fotos siguen viviendo en el catálogo, así que se escriben
 * una vez y las heredan todas las propuestas que usen ese prestador. El detalle y el porqué, en el
 * docblock de `Cotizacion::$destacadoComponenteId`.
 *
 * ⚠️ **Enlace blando: texto, sin clave ajena.** Mismo patrón que
 * `operacion_orden_servicio_item.operacion_servicio_id`. Si el componente se borra al editar, el
 * puntero queda colgando y la cabecera no destaca nada — la degradación buscada. Una FK obligaría
 * a elegir entre bloquear el borrado o perder el dato sin avisar.
 *
 * Nace nulo en las 15 cotizaciones que ya existen: ninguna destaca nada hasta que alguien lo
 * decida, que es lo correcto — no hay regla que deduzca cuál es el protagonista de un viaje.
 */
final class Version20261006060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cotizacion_cotizacion.destacado_componente_id: el bloque que la propuesta destaca';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotizacion ADD destacado_componente_id VARCHAR(36) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotizacion DROP destacado_componente_id');
    }
}
