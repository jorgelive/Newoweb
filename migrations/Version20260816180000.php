<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retira las columnas del proveedor anidado que quedaron congeladas en la tarifa.
 *
 * `Version20260816160000` las copió al componente y las dejó en su sitio como red de
 * seguridad para revertir sin tocar la base. Se retiran ya porque **estos datos son de un
 * piloto**: no hay propuesta viva que dependa de ellos y no alimentan ningún costo ni
 * cálculo financiero —el dinero vive en `montoCosto`, `moneda`, `cantidad`, `esGrupal` y
 * `comisionOverrideSnapshot`, ninguno de los cuales se toca aquí—.
 *
 * Lo que se va es sólo PRESENTACIÓN y su bandera de anonimato:
 *   · título, url e imágenes del proveedor,
 *   · el servicio contratado (tipo de habitación) con su título, url e imágenes,
 *   · `proveedor_oculto`, que estaba en 0 en las 132 filas.
 *
 * Lo que SIGUE en la tarifa, porque es por línea de precio y no se ha movido:
 *   · `proveedor_maestro_id` y `proveedor_nombre_snapshot` → «¿de quién es ESTE precio?».
 *
 * ⚠️ `down()` recrea las columnas pero **no puede devolver el contenido**. No es una
 * pérdida real: el dato equivalente está en `cotizacion_cotcomponente`, que es su sitio
 * desde la migración anterior. Si alguna vez hubiera que rehidratarlas, se copian de allí
 * con el JOIN inverso al que las subió.
 */
final class Version20260816180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cottarifa: elimina la presentación del proveedor, ya migrada al componente.';
    }

    /**
     * Se suelta columna a columna y sólo si existe, en vez de un ALTER con los ocho
     * DROP juntos. Un ALTER múltiple es todo o nada: basta que una columna falte —porque
     * alguien la quitó a mano, o porque un intento anterior se cortó a medias— para que
     * falle entero y deje la base en un estado del que hay que salir manualmente.
     */
    public function up(Schema $schema): void
    {
        $c = $this->connection;

        $columnas = [
            'proveedor_titulo_snapshot',
            'proveedor_url_snapshot',
            'proveedor_imagenes_snapshot',
            'proveedor_servicio_maestro_id',
            'proveedor_servicio_titulo_snapshot',
            'proveedor_servicio_url_snapshot',
            'proveedor_servicio_imagenes_snapshot',
            'proveedor_oculto',
        ];

        $existentes = $c->fetchFirstColumn(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cotizacion_cottarifa'"
        );

        foreach ($columnas as $col) {
            if (!\in_array($col, $existentes, true)) {
                $this->write(sprintf('  %s ya no existía; se salta.', $col));
                continue;
            }

            $c->executeStatement(sprintf('ALTER TABLE cotizacion_cottarifa DROP %s', $col));
        }
    }

    public function down(Schema $schema): void
    {
        // Vuelven vacías: el contenido vive en cotizacion_cotcomponente desde 20260816160000.
        $this->addSql("ALTER TABLE cotizacion_cottarifa
            ADD proveedor_titulo_snapshot JSON NOT NULL,
            ADD proveedor_url_snapshot VARCHAR(255) DEFAULT NULL,
            ADD proveedor_imagenes_snapshot JSON NOT NULL,
            ADD proveedor_servicio_maestro_id VARCHAR(36) DEFAULT NULL,
            ADD proveedor_servicio_titulo_snapshot JSON NOT NULL,
            ADD proveedor_servicio_url_snapshot VARCHAR(255) DEFAULT NULL,
            ADD proveedor_servicio_imagenes_snapshot JSON NOT NULL,
            ADD proveedor_oculto TINYINT(1) DEFAULT 0 NOT NULL");
    }
}
