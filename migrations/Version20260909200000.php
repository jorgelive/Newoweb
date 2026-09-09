<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mueve el control de validación del ARCHIVO al MANIFIESTO.
 *
 * 🔑 **El giro conceptual, en columnas.** El documento escaneado no es lo que se pone en duda —es
 * el documento oficial de una persona—: lo que se valida es **lo que alguien tecleó**. Así que el
 * veredicto se va a `cotizacion_pasajero_identificacion`, al lado del número que juzga, y el
 * archivo se queda sólo con **la lectura cacheada**.
 *
 * Ese reparto es lo que abarata el proceso: el documento no cambia nunca y el manifiesto cambia
 * todo el rato, así que cada documento cuesta **una llamada a la IA en toda su vida** y la
 * conciliación se recalcula al vuelo. Guardando el veredicto en el archivo habría que invalidarlo
 * a mano cada vez que alguien edita el manifiesto — y nadie se acuerda de eso.
 *
 * ⚠️ Las tres columnas del archivo se DESCARTAN sin migrar nada. Las 270 filas estaban todas en
 * `no_validado` porque el proceso nunca llegó a correr con `--aplicar`: no hay dato que perder.
 * Comprobado antes de escribir esto.
 */
final class Version20260909200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'El veredicto pasa a la identificación; el archivo guarda la lectura cacheada.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_archivo_validacion ON cotizacion_file_archivo');
        $this->addSql('ALTER TABLE cotizacion_file_archivo
            DROP estado_validacion, DROP observaciones_validacion, DROP validado_en');

        $this->addSql("ALTER TABLE cotizacion_file_archivo
            ADD datos_leidos JSON DEFAULT NULL,
            ADD leido_en DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            ADD lectura_error VARCHAR(255) DEFAULT NULL");

        $this->addSql("ALTER TABLE cotizacion_pasajero_identificacion
            ADD validado_con_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)',
            ADD estado_validacion VARCHAR(20) DEFAULT 'no_validado' NOT NULL,
            ADD discrepancias JSON NOT NULL,
            ADD notas_validacion JSON NOT NULL,
            ADD validado_en DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");

        // 🔥 **Las dos columnas JSON se rellenan a mano, y no es por gusto.** `ADD … JSON NOT NULL`
        // sobre una tabla con filas les mete el **literal JSON `null`**: satisface el NOT NULL, NO
        // lo caza un `WHERE … IS NULL`, y `json_decode('null')` devuelve `null` de PHP, que
        // Doctrine asigna a una propiedad `array` y revienta **al leer**, no al migrar. Ya pasó el
        // 09/09/2026 con `observaciones_validacion`: la migración dijo [OK] y el fallo salió
        // después. `JSON_TYPE(...) = 'NULL'` es la condición que distingue las dos cosas.
        foreach (['discrepancias', 'notas_validacion'] as $columna) {
            $this->addSql("UPDATE cotizacion_pasajero_identificacion
                SET $columna = '[]'
                WHERE $columna IS NULL OR JSON_TYPE($columna) = 'NULL'");
        }

        $this->addSql('ALTER TABLE cotizacion_pasajero_identificacion
            ADD CONSTRAINT FK_F0DCEF5A171F26B5 FOREIGN KEY (validado_con_id)
            REFERENCES cotizacion_file_archivo (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_F0DCEF5A171F26B5 ON cotizacion_pasajero_identificacion (validado_con_id)');

        // La cola se consulta por estado, y siempre por persona.
        $this->addSql('CREATE INDEX idx_identificacion_validacion ON cotizacion_pasajero_identificacion (pasajero_id, estado_validacion)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_identificacion_validacion ON cotizacion_pasajero_identificacion');
        $this->addSql('ALTER TABLE cotizacion_pasajero_identificacion DROP FOREIGN KEY FK_F0DCEF5A171F26B5');
        $this->addSql('DROP INDEX IDX_F0DCEF5A171F26B5 ON cotizacion_pasajero_identificacion');
        $this->addSql('ALTER TABLE cotizacion_pasajero_identificacion
            DROP validado_con_id, DROP estado_validacion, DROP discrepancias, DROP notas_validacion, DROP validado_en');

        $this->addSql('ALTER TABLE cotizacion_file_archivo DROP datos_leidos, DROP leido_en, DROP lectura_error');
        $this->addSql("ALTER TABLE cotizacion_file_archivo
            ADD estado_validacion VARCHAR(20) DEFAULT 'no_validado' NOT NULL,
            ADD observaciones_validacion JSON NOT NULL,
            ADD validado_en DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql("UPDATE cotizacion_file_archivo SET observaciones_validacion = '[]' WHERE JSON_TYPE(observaciones_validacion) = 'NULL'");
        $this->addSql('CREATE INDEX idx_archivo_validacion ON cotizacion_file_archivo (file_id, estado_validacion)');
    }
}
