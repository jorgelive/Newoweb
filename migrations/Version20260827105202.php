<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El nombre del COMPONENTE, congelado en el cuadro y en la orden.
 *
 * Vivía sólo en el catálogo maestro y se resolvía en vivo desde el navegador, en el mismo lote
 * que las etiquetas de lugar — un lote cuyo `catch` lo vacía entero porque «los badges son
 * decoración». Cuando esa petición fallaba, la ficha caía al respaldo y enseñaba el nombre del
 * ITINERARIO en su sitio: un traslado de Ollantaytambo a Cusco se anunciaba como «Full Day
 * HUAYNA: MAPI OLLA CUZ (bimodal)». Y en la Orden no había ni eso — al proveedor le llegaba la
 * variante de tarifa como encargo: «Auto», «Hotel 4 estrellas por grupo».
 *
 * ⚠️ Escrita a mano. `migrations:diff` añadía además un DROP de las tablas `energia_*` y de
 * `pms_evento_calendario_backup_20260808`, que son deriva del esquema ajena a este cambio: una
 * migración que borra tablas que nadie mencionó es la forma más fácil de perder datos en un
 * despliegue rutinario.
 */
final class Version20260827105202 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Nombre del componente en el snapshot de La Biblia y en las líneas congeladas de la Orden.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_servicio ADD nombre_componente VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE operacion_orden_servicio_item ADD nombre_componente VARCHAR(255) DEFAULT NULL, ADD contexto_servicio VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_servicio DROP nombre_componente');
        $this->addSql('ALTER TABLE operacion_orden_servicio_item DROP nombre_componente, DROP contexto_servicio');
    }
}
