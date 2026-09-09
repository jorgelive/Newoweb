<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Arregla las 270 filas que quedaron con el literal JSON `null` en `observaciones_validacion`.
 *
 * 🔥 **`ADD observaciones_validacion JSON NOT NULL` sobre una tabla con filas NO las deja vacías:
 * les mete el literal JSON `null`.** Y ese literal:
 *
 * - **satisface** `NOT NULL` —es un valor JSON válido, cuatro caracteres—,
 * - **no lo caza** `WHERE ... IS NULL`, que es la comprobación que uno escribe,
 * - y `json_decode('null')` devuelve **PHP null**, así que Doctrine intenta asignar `null` a una
 *   propiedad `array` y revienta con un `TypeError` al hidratar.
 *
 * La migración anterior YA traía el guarda —`UPDATE … WHERE observaciones_validacion IS NULL`— y
 * saltó las 270 filas sin tocar ninguna, porque preguntaba por SQL NULL y lo que hay es JSON null.
 * Dos cosas distintas con el mismo nombre.
 *
 * ⚠️ **Y no falló al migrar: falló al LEER**, la primera vez que alguien abrió uno de esos
 * archivos. La migración dijo `[OK]`.
 *
 * La condición correcta es `JSON_TYPE(...) = 'NULL'`, que es la que distingue las dos.
 */
final class Version20260909180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'observaciones_validacion: el literal JSON null pasa a [].';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE cotizacion_file_archivo
            SET observaciones_validacion = '[]'
            WHERE observaciones_validacion IS NULL OR JSON_TYPE(observaciones_validacion) = 'NULL'");
    }

    public function down(Schema $schema): void
    {
        // No se deshace: volver a poner `null` reintroduciría el TypeError a propósito.
        $this->throwIrreversibleMigrationException('Devolver el literal null volvería a romper la lectura.');
    }
}
