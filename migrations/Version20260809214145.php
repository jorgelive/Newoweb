<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Registra el móvil de jorge.gomez para que el sistema lo identifique como equipo.
 *
 * Sirve para dos cosas distintas, y conviene no confundirlas:
 *
 * 1. **Guardia de atención.** `EscalarAlEquipoSkill::guardia()` descarta a quien no tenga
 *    teléfono, porque el aviso de escalado sale por WhatsApp. Sin esto, el rol
 *    `ROLE_CUSTOMER_SUPPORT` no sirve de nada.
 * 2. **Identificación en el chat.** `AiConversationProcessor` busca el número entrante en
 *    `user.telefono`: si lo encuentra, atiende con los roles de esa persona en vez de como
 *    huésped. Es lo que hace que preguntar «¿quién sale mañana?» por WhatsApp devuelva la
 *    lista en vez de «no tengo acceso a esa información».
 *
 * ⚠️ **El formato importa.** `UserRepository::findByTelefono()` compara contra el número ya
 * normalizado por `PhoneSanitizer::cleanPhoneNumber($n, 'PE')`, que devuelve código de país
 * + número sin `+` ni espacios. Guardar «+51 921 166 466» aquí haría que la comparación no
 * casara NUNCA y el operador seguiría siendo tratado como huésped, sin ningún error visible.
 * De ahí que el valor se escriba ya normalizado.
 */
final class Version20260809214145 extends AbstractMigration
{
    /** Ya normalizado: es lo que devuelve PhoneSanitizer para cualquier forma de escribirlo. */
    private const string TELEFONO_JORGE = '51921166466';

    public function getDescription(): string
    {
        return 'Registra el móvil de jorge.gomez (guardia de atención e identificación en el chat).';
    }

    public function up(Schema $schema): void
    {
        // Solo se rellena si está vacío: si alguien ya cargó un número desde el panel, manda
        // el suyo. Una migración no debería pisar un dato que un humano puso después.
        $this->addSql(
            'UPDATE `user` SET telefono = :telefono WHERE username = :usuario AND (telefono IS NULL OR telefono = \'\')',
            ['telefono' => self::TELEFONO_JORGE, 'usuario' => 'jorge.gomez']
        );
    }

    public function down(Schema $schema): void
    {
        // Se limpia solo si sigue siendo el que puso esta migración.
        $this->addSql(
            'UPDATE `user` SET telefono = NULL WHERE username = :usuario AND telefono = :telefono',
            ['telefono' => self::TELEFONO_JORGE, 'usuario' => 'jorge.gomez']
        );
    }
}
