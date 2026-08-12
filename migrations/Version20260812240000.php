<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fuera la plantilla `ventana_cerrada`: la idea era mala y duró unas horas.
 *
 * `Version20260812230000` la creó para mandarla sola cuando un mensaje del operador no sale por
 * tener la ventana de 24 h cerrada: le pedía al huésped que contestara para reabrirla. Se
 * descarta sin llegar a subirse a Meta, por decisión del dueño del producto y con buen
 * argumento:
 *
 * **Si hay que gastar una plantilla, que sirva para algo.** Fuera de la ventana Meta las cobra,
 * y ya existen `enviar_guia`, `menu_tours` o `recordatorio_llegada`, que reabren la conversación
 * exactamente igual —cualquier respuesta del huésped vale— y encima le dan algo que agradecer.
 * Un «alguien quiere hablarte» gasta lo mismo y no aporta nada.
 *
 * Y cuál de ellas toca no lo puede decidir el sistema: depende de para qué le estabas
 * escribiendo. Así que la decisión se queda en la persona, y lo que el sistema hace es avisar
 * con la salida delante — ver `NotificadorPushConversacion::avisarEnvioFallido()`.
 *
 * No se borra la migración que la creó: reescribir una migración ya aplicada deja las bases de
 * datos existentes en un estado que nadie puede reproducir. Se crea y se borra, y la historia
 * queda escrita.
 */
final class Version20260812240000 extends AbstractMigration
{
    private const string CODE = 'ventana_cerrada';

    public function getDescription(): string
    {
        return 'Borra la plantilla «ventana_cerrada»: es mejor reabrir la conversación con una plantilla útil';
    }

    public function up(Schema $schema): void
    {
        $usos = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM msg_message m JOIN msg_template t ON t.id = m.template_id WHERE t.code = :code',
            ['code' => self::CODE]
        );

        if ($usos > 0) {
            // No debería pasar —nunca se aprobó en Meta, así que no pudo enviarse— pero borrar
            // una plantilla usada deja mensajes huérfanos o revienta por la clave foránea.
            $this->write(sprintf('  ⚠️ «%s» se usó en %d mensaje(s): NO se borra.', self::CODE, $usos));

            return;
        }

        $filas = $this->connection->executeStatement('DELETE FROM msg_template WHERE code = :code', ['code' => self::CODE]);

        $this->write($filas === 0
            ? sprintf('  «%s» ya no estaba.', self::CODE)
            : sprintf('  «%s» borrada.', self::CODE));
    }

    /**
     * No se recrea: para eso está `Version20260812230000`, con el texto y el porqué.
     */
    public function down(Schema $schema): void
    {
        $this->write('  Sin vuelta atrás: si hiciera falta, el texto está en Version20260812230000.');
    }
}
