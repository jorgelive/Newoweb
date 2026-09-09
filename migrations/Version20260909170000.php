<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El control de validación de los documentos escaneados.
 *
 * Tres columnas en el ARCHIVO, no en la identificación del pasajero: lo que se valida es esta
 * imagen contra el manifiesto, y la misma persona puede tener el pasaporte comprobado y el DNI
 * observado.
 *
 * `validado_en` es nulable a propósito y hace falta ADEMÁS del estado: un `no_validado` con fecha
 * es «se intentó y no se pudo», y uno sin fecha es «nunca le ha tocado». Son dos colas distintas.
 */
final class Version20260909170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Estado de validación, observaciones y fecha en cotizacion_file_archivo.';
    }

    public function up(Schema $schema): void
    {
        // Sólo esquema: no hay dato que recalcular y ningún listener que deba correr. Las 260
        // filas existentes arrancan en `no_validado`, que es literalmente su estado.
        $this->addSql("ALTER TABLE cotizacion_file_archivo
            ADD estado_validacion VARCHAR(20) DEFAULT 'no_validado' NOT NULL,
            ADD observaciones_validacion JSON NOT NULL,
            ADD validado_en DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");

        // ⚠️ El DEFAULT de la columna JSON no lo pone MySQL 5.7 —no admite default en JSON—, así
        // que las filas existentes quedarían con NULL y `getObservacionesValidacion()` promete
        // `list<string>`. Se rellenan aquí: un NULL ahí sería un TypeError al hidratar, y saldría
        // días después al abrir un expediente viejo.
        $this->addSql("UPDATE cotizacion_file_archivo SET observaciones_validacion = '[]' WHERE observaciones_validacion IS NULL");

        // La cola de trabajo se consulta por estado y siempre dentro de un expediente.
        $this->addSql('CREATE INDEX idx_archivo_validacion ON cotizacion_file_archivo (file_id, estado_validacion)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_archivo_validacion ON cotizacion_file_archivo');
        $this->addSql('ALTER TABLE cotizacion_file_archivo DROP estado_validacion, DROP observaciones_validacion, DROP validado_en');
    }
}
