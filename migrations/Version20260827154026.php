<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Unifica el vocabulario de los nombres: `tituloSnapshot` (público) y `nombreInternoSnapshot`.
 *
 * El mismo campo `nombreSnapshot` guardaba el nombre PÚBLICO en `cotcomponente` y `segmento`, y el
 * INTERNO en `cotservicio` —donde el público vivía aparte, en `nombre_publico_snapshot`—. Leer
 * `->getNombreSnapshot()` daba una cosa u otra según la entidad, y en los dos casos un texto que se
 * lee bien: no fallaba, devolvía otra cosa. `CotizacionCottarifa` ya tenía el par bien puesto, así
 * que el molde no se inventa aquí.
 *
 * Y `travel_componente.nombre` pasa a `nombre_interno`, que es como lo llaman los otros tres
 * maestros (segmento, tarifa, servicio).
 *
 * ⚠️ **Escrita a mano, y no es un capricho.** `migrations:diff` propuso para `cotizacion_cotservicio`
 * un **`ADD` + `DROP`** en vez de un `CHANGE`: al cambiar dos columnas de la misma tabla a la vez no
 * infiere la correspondencia, y ese SQL **habría borrado el nombre de todos los servicios de todas
 * las cotizaciones** —creando dos columnas vacías y tirando las llenas— sin un solo error. Con
 * `CHANGE` la columna se renombra con sus datos dentro.
 *
 * (El diff además arrastraba un DROP de las tablas `energia_*`, deriva ajena a este cambio.)
 */
final class Version20260827154026 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Unifica los nombres: tituloSnapshot (público) y nombreInternoSnapshot; travel_componente.nombre → nombre_interno.';
    }

    public function up(Schema $schema): void
    {
        // El público, que en estas dos ya vivía en `nombre_snapshot`.
        $this->addSql('ALTER TABLE cotizacion_cotcomponente CHANGE nombre_snapshot titulo_snapshot JSON NOT NULL');
        $this->addSql('ALTER TABLE cotizacion_segmento CHANGE nombre_snapshot titulo_snapshot JSON NOT NULL');

        // Aquí los dos intercambian papel de nombre, no de contenido: lo que se llamaba
        // `nombre_snapshot` era el interno, y `nombre_publico_snapshot` el título.
        $this->addSql('ALTER TABLE cotizacion_cotservicio CHANGE nombre_snapshot nombre_interno_snapshot JSON NOT NULL');
        $this->addSql('ALTER TABLE cotizacion_cotservicio CHANGE nombre_publico_snapshot titulo_snapshot JSON NOT NULL');

        // El maestro de componentes era el único de los cuatro que no decía `nombre_interno`.
        $this->addSql('ALTER TABLE travel_componente CHANGE nombre nombre_interno VARCHAR(150) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE travel_componente CHANGE nombre_interno nombre VARCHAR(150) NOT NULL');
        $this->addSql('ALTER TABLE cotizacion_cotservicio CHANGE titulo_snapshot nombre_publico_snapshot JSON NOT NULL');
        $this->addSql('ALTER TABLE cotizacion_cotservicio CHANGE nombre_interno_snapshot nombre_snapshot JSON NOT NULL');
        $this->addSql('ALTER TABLE cotizacion_segmento CHANGE titulo_snapshot nombre_snapshot JSON NOT NULL');
        $this->addSql('ALTER TABLE cotizacion_cotcomponente CHANGE titulo_snapshot nombre_snapshot JSON NOT NULL');
    }
}
