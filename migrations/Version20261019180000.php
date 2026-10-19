<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El rótulo del asunto en los hilos que ya existen (`context_data.rotulo`).
 *
 * Desde el 19/10/2026 lo escribe cada dominio al sincronizar el hilo
 * (`MessageContextInterface::getRotulo()`); los hilos viejos lo recibirían en su siguiente
 * sincronización, que en un proveedor puede no llegar nunca. Se rellena aquí con el mismo valor que
 * daría su dominio, para que la bandeja deje de suponer «Reserva PMS» desde hoy.
 *
 * Por SQL y no por comando: `context_data` no lleva traducción ni listeners.
 */
final class Version20261019180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'msg_conversation: context_data.rotulo en los hilos existentes';
    }

    public function up(Schema $schema): void
    {
        foreach (['pms_reserva' => 'Reserva', 'cotizacion_file' => 'Viaje', 'travel_organizacion' => 'Proveedor'] as $tipo => $rotulo) {
            $this->addSql(
                "UPDATE msg_conversation SET context_data = JSON_SET(COALESCE(context_data, JSON_OBJECT()), '$.rotulo', ?) WHERE context_type = ?",
                [$rotulo, $tipo]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE msg_conversation SET context_data = JSON_REMOVE(context_data, '$.rotulo') WHERE JSON_CONTAINS_PATH(context_data, 'one', '$.rotulo')");
    }
}
