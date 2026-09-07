<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Quién viaja, en el documento del proveedor.
 *
 * Al conductor le llegaban horas, servicios y pax — y ni el nombre del grupo, ni el pasajero
 * principal, ni un teléfono al que llamar. Con una orden de un solo expediente se sobreentendía de
 * quién era; con dos, no hay forma de saber qué línea es de quién.
 *
 *   operacion_orden_servicio.grupos_snapshot   un bloque por expediente, congelado al emitir
 *   operacion_orden_servicio_item.nombre_grupo la etiqueta de la línea, sólo si hay varios grupos
 *
 * ⚠️ `grupos_snapshot` nace vacío —`[]`— en las órdenes que ya existían, y por eso `emitir()` lo
 * recalcula siempre: dejarlo así obligaría a reemitir una orden confirmada sólo para ponerle el
 * nombre del cliente. Las que ya están emitidas las rellena `app:operacion:backfill-grupos`.
 */
final class Version20260907175621 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quién viaja: bloque de grupos en la orden y etiqueta de grupo en cada línea.';
    }

    public function up(Schema $schema): void
    {
        // ⚠️ En dos pasos: con `DEFAULT` para que las órdenes que ya existen tengan un valor, y
        // sin él después, que es como lo declara el mapeo. En uno solo, `schema:validate` se queda
        // desincronizado para siempre y el aviso deja de significar nada.
        $this->addSql('ALTER TABLE operacion_orden_servicio ADD grupos_snapshot JSON NOT NULL DEFAULT (JSON_ARRAY())');
        $this->addSql('ALTER TABLE operacion_orden_servicio ALTER COLUMN grupos_snapshot DROP DEFAULT');
        $this->addSql('ALTER TABLE operacion_orden_servicio_item ADD nombre_grupo VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_orden_servicio DROP grupos_snapshot');
        $this->addSql('ALTER TABLE operacion_orden_servicio_item DROP nombre_grupo');
    }
}
