<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El veredicto del control, para los documentos que NO son de identidad.
 *
 * El de un DNI o un pasaporte vive en `cotizacion_pasajero_identificacion`, al lado del número que
 * juzga. Un E-Ticket migratorio **no tiene número que identifique a nadie**: no hay fila ahí donde
 * ponerlo —la clave única es `(pasajero, tipo)` sobre los tipos de documento de identidad— y darle
 * un tipo sería declarar documento de identidad algo que no lo es.
 *
 * Lo que se juzga de un E-Ticket es el ARCHIVO: si el trámite cuadra con los vuelos de esa persona.
 * Así que el veredicto es del archivo, y con los mismos tres campos y los mismos nombres que en la
 * identificación, para que la pantalla pueda pintar los dos igual.
 */
final class Version20260916120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'El veredicto del control en cotizacion_file_archivo: estado, discrepancias y notas.';
    }

    public function up(Schema $schema): void
    {
        // ⚠️ **Las dos JSON entran NULABLES y se rellenan después.** Una columna `JSON NOT NULL`
        // añadida a una tabla con filas se rellena con el literal JSON `null`, que satisface el
        // NOT NULL, **no lo caza un `WHERE … IS NULL`**, y `json_decode('null')` devuelve `null` de
        // PHP: Doctrine lo asigna a una propiedad `array` y suelta un TypeError **al leer**, días
        // después, la primera vez que alguien abre una fila vieja. La migración habría dicho [OK].
        //
        // Ya pasó el 09/09/2026 y está escrito en `CLAUDE.md`. Nulable → rellenar → NOT NULL es el
        // camino que no tiene ese agujero.
        $this->addSql("ALTER TABLE cotizacion_file_archivo
            ADD estado_validacion VARCHAR(20) DEFAULT 'no_validado' NOT NULL,
            ADD discrepancias JSON DEFAULT NULL,
            ADD notas_validacion JSON DEFAULT NULL,
            ADD validado_en DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");

        foreach (['discrepancias', 'notas_validacion'] as $columna) {
            $this->addSql("UPDATE cotizacion_file_archivo
                SET $columna = '[]'
                WHERE $columna IS NULL OR JSON_TYPE($columna) = 'NULL'");
        }

        $this->addSql('ALTER TABLE cotizacion_file_archivo
            MODIFY discrepancias JSON NOT NULL,
            MODIFY notas_validacion JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_file_archivo
            DROP estado_validacion, DROP discrepancias, DROP notas_validacion, DROP validado_en');
    }
}
