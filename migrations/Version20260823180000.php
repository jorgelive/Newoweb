<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `cotizacion_file_documento` → `cotizacion_file_archivo`.
 *
 * La entidad nunca guardó documentos de identidad: es `Vich\Uploadable` con `MediaTrait` y un POST
 * multipart, y su enum es `ArchivoTipoEnum` (boleto, factura, confirmación de reserva). El nombre
 * hizo leerla al revés más de una vez, incluido al diseñar el padrón de pasajeros — que sí necesita
 * identidad, y va en otra entidad.
 *
 * ⚠️ **No toca `cot_filedocumento`**, la tabla legada que mapea `src/Oweb/`. Son tablas distintas.
 *
 * ⚠️ Se cae `vencimiento`: **0 valores en 7 filas** en producción. Lo que caduca de verdad se ve
 * en la orden de servicio; aquí era un campo que nadie llenó nunca.
 *
 * ⚠️ Y el despliegue necesita mover la carpeta en el servidor, porque el parámetro cambia:
 *     mv public/carga/cotizacion/documentos public/carga/cotizacion/archivos
 * `imageUrl` es virtual —lo inyecta el AssetListener— así que no hay URLs guardadas que reescribir.
 */
final class Version20260823180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Renombra cotizacion_file_documento a _archivo, tipodocumento a tipo_archivo y quita vencimiento';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_file_documento RENAME TO cotizacion_file_archivo');
        $this->addSql('ALTER TABLE cotizacion_file_archivo CHANGE tipodocumento tipo_archivo VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE cotizacion_file_archivo DROP vencimiento');
        // El nombre del índice de la FK lo deriva Doctrine del nombre de la tabla, así que se
        // renombra con ella o `schema:validate` no vuelve a estar limpio.
        $this->addSql('ALTER TABLE cotizacion_file_archivo RENAME INDEX idx_edbf06df93cb796c TO IDX_D33AFC6793CB796C');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_file_archivo RENAME INDEX IDX_D33AFC6793CB796C TO idx_edbf06df93cb796c');
        $this->addSql('ALTER TABLE cotizacion_file_archivo ADD vencimiento DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE cotizacion_file_archivo CHANGE tipo_archivo tipodocumento VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE cotizacion_file_archivo RENAME TO cotizacion_file_documento');
    }
}
