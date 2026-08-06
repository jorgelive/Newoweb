<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Etiqueta las plantillas para el agente de IA.
 *
 * - `agente_uso`: cuándo usarla, escrito para el modelo. `name` no valía: es una etiqueta de
 *   panel y algunas son referencias internas («Autorespuesta ER130497»).
 * - `agente_habilitada`: si el agente puede mandarla a petición de un operador. Por defecto
 *   NO, porque 6 de las 11 las dispara solo el motor de reglas en su hito y mandarlas a mano
 *   duplicaría lo que el huésped ya recibió.
 *
 * Se rellenan de salida las que tienen sentido manual, y se dejan apagadas las automáticas.
 */
final class Version20260805190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Plantillas: etiquetas agente_uso y agente_habilitada para el agente de IA';
    }

    public function up(Schema $schema): void
    {
        // Por objeto, no por migración entera: si una de las dos columnas ya existe —porque
        // alguien corrió doctrine:schema:update— el resto tiene que aplicarse igual.
        if (!$this->columnaExiste('msg_template', 'agente_uso')) {
            $this->addSql('ALTER TABLE msg_template ADD agente_uso LONGTEXT DEFAULT NULL');
        }

        if (!$this->columnaExiste('msg_template', 'agente_habilitada')) {
            $this->addSql('ALTER TABLE msg_template ADD agente_habilitada TINYINT(1) DEFAULT 0 NOT NULL');
        }

        // Las cinco que NO dispara ninguna regla: mandarlas a mano es justo su caso de uso.
        $this->seed('enviar_guia',
            'El huésped pide su guía, o hay que reenviársela porque no la encuentra. Manda el '
            . 'enlace a su guía personal con la llegada, el wifi y las instrucciones.',
            true);

        $this->seed('solicitar_numero_whatsapp',
            'La reserva vino de una OTA y no tenemos su teléfono. Le pide el número de WhatsApp '
            . 'para poder seguir por ahí. Sólo tiene sentido en reservas de Booking o Airbnb.',
            true);

        $this->seed('menu_tours',
            'El huésped pregunta qué hacer, o pide tours. Manda el menú de tours con sus '
            . 'opciones numeradas.',
            true);

        // Apagadas, con el motivo escrito para que quien lo lea en el panel no las encienda
        // sin querer. La frase se rellena igual: el día que se habiliten, ya está.
        $this->seed('recordatorio_llegada',
            'Instrucciones de llegada. LA MANDA SOLA el motor de reglas al acercarse la '
            . 'entrada: sólo reenviar si el operador confirma que el huésped no la recibió.',
            false);

        $this->seed('check_out',
            'Aviso de salida con la hora y qué hacer con las llaves. LA MANDA SOLA el motor de '
            . 'reglas al acercarse la salida.',
            false);

        $this->seed('welcome_booking',
            'Bienvenida a un huésped de Booking. LA MANDA SOLA el motor de reglas al crearse la '
            . 'reserva.',
            false);

        $this->seed('welcome_airbnb',
            'Bienvenida a un huésped de Airbnb. LA MANDA SOLA el motor de reglas al crearse la '
            . 'reserva.',
            false);

        $this->seed('despedida_booking',
            'Despedida y petición de reseña, para huéspedes de Booking. LA MANDA SOLA el motor '
            . 'de reglas al terminar la estancia.',
            false);

        $this->seed('despedida_airbnb',
            'Despedida y petición de reseña, para huéspedes de Airbnb. LA MANDA SOLA el motor '
            . 'de reglas al terminar la estancia.',
            false);

        $this->seed('solicitar_mensaje_whatsapp',
            'Autorespuesta interna del flujo de WhatsApp. No es para mandar a mano.',
            false);
    }

    public function down(Schema $schema): void
    {
        if ($this->columnaExiste('msg_template', 'agente_uso')) {
            $this->addSql('ALTER TABLE msg_template DROP agente_uso');
        }

        if ($this->columnaExiste('msg_template', 'agente_habilitada')) {
            $this->addSql('ALTER TABLE msg_template DROP agente_habilitada');
        }
    }

    /**
     * No pisa lo que ya haya escrito una persona: sólo rellena si está vacío.
     */
    private function seed(string $code, string $uso, bool $habilitada): void
    {
        $this->addSql(
            'UPDATE msg_template SET agente_uso = :uso, agente_habilitada = :hab '
            . 'WHERE code = :code AND (agente_uso IS NULL OR agente_uso = \'\')',
            ['uso' => $uso, 'hab' => $habilitada ? 1 : 0, 'code' => $code]
        );
    }

    private function columnaExiste(string $tabla, string $columna): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$tabla, $columna]
        );
    }
}
