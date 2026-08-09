<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Registra el móvil de susan.acuna, igual que {@see Version20260809214145} hizo con el de
 * jorge.gomez: guardia de atención e identificación como equipo en el chat.
 *
 * ⚠️ **Susan tiene además una reserva de prueba con este MISMO número.** Eso tiene una
 * consecuencia que conviene tener presente al leer la bandeja:
 *
 * - Al escribir por WhatsApp, `AiConversationProcessor` la reconocerá como EQUIPO —el
 *   número está aquí— y le contestará con sus roles, no como huésped. Su conversación de
 *   huésped de prueba deja de comportarse como tal.
 * - Los avisos internos de escalado van a una conversación aparte (`contextType='staff'`),
 *   así que el sistema no los mezcla. **WhatsApp sí**: en su teléfono todo cae en el mismo
 *   hilo, porque para WhatsApp hay un solo número.
 *
 * Si se quiere volver a probar el flujo de huésped con ella, hay que vaciar este campo o
 * usar otro número para la reserva de prueba.
 *
 * El valor va ya normalizado como lo deja `PhoneSanitizer::cleanPhoneNumber($n, 'PE')`:
 * guardarlo con `+` o espacios haría que `findByTelefono()` no casara nunca, sin error
 * visible. Ver el docblock de la migración de jorge.gomez.
 */
final class Version20260809214437 extends AbstractMigration
{
    private const string TELEFONO_SUSAN = '51958191965';

    public function getDescription(): string
    {
        return 'Registra el móvil de susan.acuna (guardia de atención e identificación en el chat).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE `user` SET telefono = :telefono WHERE username = :usuario AND (telefono IS NULL OR telefono = \'\')',
            ['telefono' => self::TELEFONO_SUSAN, 'usuario' => 'susan.acuna']
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'UPDATE `user` SET telefono = NULL WHERE username = :usuario AND telefono = :telefono',
            ['telefono' => self::TELEFONO_SUSAN, 'usuario' => 'susan.acuna']
        );
    }
}
