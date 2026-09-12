<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Se retira `pms_unidad.token`: el último resto de cuando la portada vivía en esa entidad.
 *
 * El `token` lo aporta `MediaTrait` y sirve para UNA cosa: que `MediaTokenNamer` sepa cómo nombrar
 * el archivo al subirlo. Al mudarse la portada a `pms_unidad_media` (`Version20260912120000`), la
 * unidad se quedó sin ningún `UploadableField` — y por tanto sin nada que nombrar—, pero conservó
 * el trait, el atributo `#[Vich\Uploadable]` y esta columna con sus siete valores.
 *
 * ⚠️ **Un token sin archivo no es inofensivo: es una pista falsa.** Dice que aquí se suben cosas,
 * y quien busque dónde se sube la portada lo encontrará y se irá por el camino equivocado. Los
 * medios de la casita se suben en `PmsUnidadMedia`, que tiene su propio token.
 *
 * El `down()` repone la columna vacía. **Los valores no vuelven, y da igual**: son 16 caracteres
 * aleatorios cuyo único uso era prefijar un nombre de archivo que ya no existe. Si algún día la
 * unidad volviera a tener subidas, `initializeToken()` genera uno nuevo al vuelo.
 */
final class Version20260912160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retira pms_unidad.token, sin uso desde que la portada se mudó a pms_unidad_media.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_unidad DROP token');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_unidad ADD token VARCHAR(16) DEFAULT NULL');
    }
}
