<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El TRAMO deja de ser un tipo y pasa a ser una etiqueta del grupo.
 *
 * `RESERVA_AEREA_NACIONAL` e `_INTERNACIONAL` eran dos casos de enum para lo que es **el mismo
 * eje con una etiqueta distinta**. Con eso, un multitramo Lima→Cusco→Puno→Lima habría pedido un
 * `case` por vuelo: un despliegue para apuntar un billete.
 *
 * Los datos ya cargados se convierten aquí, así que no hay que volver a subir ningún padrón.
 *
 * ⚠️ El índice único pasa a incluir `subeje`: `#Vuelo Ida` y `#Vuelo Retorno` con el mismo
 * localizador son dos grupos distintos, y sin la columna en la clave el segundo se fundiría con
 * el primero en silencio.
 */
final class Version20260824060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cotizacion_file_grupo.subeje: el tramo del vuelo como etiqueta libre, no como tipo.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_file_grupo ADD subeje VARCHAR(60) DEFAULT NULL');

        // Primero la etiqueta, después el tipo: al revés se perdería de cuál venía cada fila.
        $this->addSql("UPDATE cotizacion_file_grupo SET subeje = 'Nacional' WHERE tipo = 'reserva_aerea_nacional'");
        $this->addSql("UPDATE cotizacion_file_grupo SET subeje = 'Internacional' WHERE tipo = 'reserva_aerea_internacional'");
        $this->addSql("UPDATE cotizacion_file_grupo SET tipo = 'reserva_aerea' WHERE tipo IN ('reserva_aerea_nacional', 'reserva_aerea_internacional')");

        $this->addSql('DROP INDEX uniq_file_grupo_tipo_clave ON cotizacion_file_grupo');
        $this->addSql('CREATE UNIQUE INDEX uniq_file_grupo_tipo_clave ON cotizacion_file_grupo (file_id, tipo, subeje, clave)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE cotizacion_file_grupo SET tipo = 'reserva_aerea_nacional' WHERE tipo = 'reserva_aerea' AND subeje = 'Nacional'");
        $this->addSql("UPDATE cotizacion_file_grupo SET tipo = 'reserva_aerea_internacional' WHERE tipo = 'reserva_aerea' AND subeje = 'Internacional'");
        $this->addSql('DROP INDEX uniq_file_grupo_tipo_clave ON cotizacion_file_grupo');
        $this->addSql('CREATE UNIQUE INDEX uniq_file_grupo_tipo_clave ON cotizacion_file_grupo (file_id, tipo, clave)');
        $this->addSql('ALTER TABLE cotizacion_file_grupo DROP subeje');
    }
}
