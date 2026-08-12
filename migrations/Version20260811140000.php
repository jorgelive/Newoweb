<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `snapshot_origen`: la foto de lo que escribió el snapshot, para poder reconciliar.
 *
 * Sin ella, al comparar una fila de La Biblia con la cotización sólo se sabe QUE
 * difieren, no quién de los dos se movió — y entonces la única salida es borrar y
 * recrear, que se lleva por delante lo que el operador ajustó a mano.
 *
 * Se añade en dos pasos porque MySQL no tiene default implícito para JSON: un
 * ADD COLUMN JSON NOT NULL sobre una tabla con filas revienta en modo estricto.
 *
 * Las filas existentes quedan con `{}`. Es correcto y además conservador: sin foto de
 * referencia, cualquier diferencia se trata como conflicto y la decide una persona.
 * Ver BibliaReconciliacionService y docs/Operacion.md §3.5.
 */
final class Version20260811140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'operacion_servicio.snapshot_origen: valores de referencia para reconciliar sin borrar.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_servicio ADD snapshot_origen JSON DEFAULT NULL');
        $this->addSql("UPDATE operacion_servicio SET snapshot_origen = '{}' WHERE snapshot_origen IS NULL");
        $this->addSql('ALTER TABLE operacion_servicio MODIFY snapshot_origen JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_servicio DROP snapshot_origen');
    }
}
