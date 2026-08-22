<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `operacion_orden_servicio.token_publico`: la llave del enlace que ve el proveedor.
 *
 * ── Por qué una columna nueva y no el id ────────────────────────────────────
 * El enlace se abre **sin sesión** —el proveedor no tiene cuenta— así que la llave no puede ser
 * adivinable. `numero_os` lo es de cabo a rabo (`OS-20260817-938`, tres dígitos) y el `id` es un
 * UUID **v7**: lleva la marca de tiempo delante, y dos órdenes del mismo día comparten prefijo.
 *
 * ── Nullable, y no se rellena hacia atrás ───────────────────────────────────
 * La llave se sella al EMITIR, porque hasta entonces no hay documento que enseñar. Las órdenes
 * en borrador se quedan sin ella a propósito, y las ya emitidas la ganan la próxima vez que
 * pasen por emisión — o al reemitirse. Rellenarlas aquí crearía enlaces que nadie pidió para
 * documentos que quizá ya no se mandan.
 *
 * ⚠️ `UNIQUE` con `NULL`: MySQL permite varios nulos en un índice único, que es justo lo que
 * hace falta para que todos los borradores convivan sin llave.
 */
final class Version20260822480000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'operacion_orden_servicio: llave del enlace público para el proveedor.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_orden_servicio ADD token_publico VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_OS_TOKEN_PUBLICO ON operacion_orden_servicio (token_publico)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_OS_TOKEN_PUBLICO ON operacion_orden_servicio');
        $this->addSql('ALTER TABLE operacion_orden_servicio DROP token_publico');
    }
}
