<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El aviso a la guardia deja de esconder su origen dentro de un JSON.
 *
 * `EscalarAlEquipoSkill` sabe si a un caso «ya se le hizo sonar el teléfono hace poco» mirando
 * `metadata['escalado_de']`. Como el JSON no se consulta sin atar el código a la versión de
 * MySQL, la búsqueda traía **las 100 filas más recientes de las conversaciones `staff`** y
 * filtraba en PHP. Dos cotas, y las dos fallan:
 *
 *  - **el tope de 100** se llena con el tráfico normal justo el día de más carga, que es cuando
 *    más avisos hay: el enfriamiento falla abierto **bajo volumen**;
 *  - **el filtro por `contextType`** ata esto a que el hilo del operador siga siendo `staff`. Al
 *    fusionar el hilo de alguien del equipo que además es huésped —y no hay forma determinista
 *    de saber si su WhatsApp viene por su reserva o por su trabajo—, sus avisos dejan de
 *    encontrarse y **la guardia vuelve a sonar entera, de noche y sin causa evidente**.
 *
 * Con la columna la consulta es exacta: sin tope, sin filtrar en PHP y sin importar en qué tipo
 * de hilo acabó el mensaje. Es lo que desbloquea unir los 2 hilos `staff` que quedaron fuera del
 * comando de fusión.
 *
 * ⚠️ **No se rellena hacia atrás, y es correcto.** El enfriamiento mira media hora; cualquier
 * aviso anterior a este despliegue ya está fuera de esa ventana, así que rellenarlo no cambiaría
 * ninguna decisión. La metadata sigue ahí para quien inspeccione un mensaje viejo a mano.
 */
final class Version20260820320000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Message: `escalado_de` en columna, para que el enfriamiento del escalado sea exacto.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE msg_message ADD escalado_de VARCHAR(36) DEFAULT NULL');
        // El enfriamiento consulta por (escalado_de, created_at) en cada escalado.
        $this->addSql('CREATE INDEX idx_msg_escalado_de ON msg_message (escalado_de, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_msg_escalado_de ON msg_message');
        $this->addSql('ALTER TABLE msg_message DROP escalado_de');
    }
}
