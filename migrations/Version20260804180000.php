<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `pms_reserva.telefono2_es_principal`: cuál de los dos números usa el sistema
 * para contactar al huésped.
 *
 * Hoy los dos números son móviles, así que la pareja `telefono`/`telefono2` ya
 * no dice cuál es el bueno — solo en qué orden llegaron. Sin este flag todo
 * (WhatsApp, plantillas, vCard) llamaba siempre al primero aunque el operador
 * supiera que había que llamar al segundo.
 *
 * Arranca en `false` para TODAS las reservas: es exactamente el comportamiento
 * anterior (`telefono ?? telefono2`), así que la migración no cambia a quién se
 * llama en ninguna reserva existente. Marcar el segundo pasa a ser una decisión
 * explícita del operador.
 *
 * La resolución vive en PmsReserva::getTelefonoContacto(); ver §14 del doc.
 */
final class Version20260804180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Anade pms_reserva.telefono2_es_principal (numero de contacto elegido por el operador).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE pms_reserva
            ADD telefono2_es_principal TINYINT(1) DEFAULT 0 NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_reserva DROP telefono2_es_principal');
    }
}
