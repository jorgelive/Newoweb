<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `pago_recibido` deja de estar acotada a alojamiento: la usan también los cobros de turismo
 * (`CotizacionAvisosDeCobro::pagoRecibido()`). Su texto no habla de reservas —«Hemos recibido tu
 * pago de {{detalle_pago}}… tu estado de cuenta»— y su botón de Meta (`https://pax.openperu.pe/{{1}}`)
 * admite la ruta del expediente. Lo aprobado por Meta no cambia: sólo el `context_type`.
 *
 * SQL y no el ORM: `context_type` no lleva `#[AutoTranslate]`, y no hay listener que se salte.
 */
final class Version20261016220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'pago_recibido sin acotar a pms_reserva: también avisa los cobros de turismo';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE msg_template SET context_type = NULL WHERE code = 'pago_recibido'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE msg_template SET context_type = 'pms_reserva' WHERE code = 'pago_recibido'");
    }
}
