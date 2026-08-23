<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lo que hay que decirle al proveedor: override en la Biblia y copia congelada en la orden.
 *
 * Dos columnas con semánticas distintas a propósito, la misma pareja que ya forman
 * `punto_recojo` (nullable: vacío = «usa lo del catálogo») y `punto_recojo_confirmado`
 * (congelado al emitir).
 */
final class Version20260822220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notas al prestador: override editable en operacion_servicio y snapshot en el ítem de orden';
    }

    public function up(Schema $schema): void
    {
        // NULL es «no lo he redactado, usa lo del componente». Por eso nullable y sin default.
        $this->addSql('ALTER TABLE operacion_servicio ADD notas_prestador JSON DEFAULT NULL');

        // El ítem sí tiene siempre valor: un documento emitido dice algo, aunque sea nada.
        // Se añade nullable, se rellena y se cierra — MySQL no admite un DEFAULT literal en JSON.
        $this->addSql('ALTER TABLE operacion_orden_servicio_item ADD notas_prestador JSON DEFAULT NULL');
        $this->addSql("UPDATE operacion_orden_servicio_item SET notas_prestador = '[]' WHERE notas_prestador IS NULL");
        $this->addSql('ALTER TABLE operacion_orden_servicio_item MODIFY notas_prestador JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_servicio DROP notas_prestador');
        $this->addSql('ALTER TABLE operacion_orden_servicio_item DROP notas_prestador');
    }
}
