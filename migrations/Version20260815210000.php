<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Quién cobró: los 47 pagos existentes pasan a Susan, y tres personas estrenan el perfil.
 *
 * ── Por qué había que rellenarlo a mano ─────────────────────────────────────
 * `PmsPagoFinanciero::$cobrador` se añadió después de que ya hubiera pagos registrados, así
 * que los 47 que existen están **todos** con `cobrador_id` nulo. Mientras siga así, cualquier
 * informe por cobrador cuenta cero para todo el histórico — no porque nadie cobrara, sino
 * porque el campo no existía cuando se cobró. Susan es quien los llevó.
 *
 * ── Por qué el rol se asigna uno a uno ──────────────────────────────────────
 * `ROLE_COBRADOR` **no se hereda a propósito** (ver `src/Security/Roles.php`): si colgara de
 * la jerarquía de administración, cualquier admin aparecería en el desplegable de cobradores
 * y la lista dejaría de significar «quién puede cobrarle al huésped». Por eso se añade
 * explícitamente a los tres, y por eso esto es una migración y no un cambio de configuración.
 *
 * ── Va por SQL directo, y aquí sí toca ──────────────────────────────────────
 * Es exactamente el caso que describe CLAUDE.md: datos que ninguna entidad recalcula al
 * guardarse. `cobrador_id` no dispara ningún listener de coherencia financiera —no toca
 * montos, ni monedas, ni tipos de cambio— y los roles no pasan por `AutoTranslate`. Meterlo
 * por el ORM sólo añadiría 47 flushes sin ganar nada.
 *
 * ── Idempotente ─────────────────────────────────────────────────────────────
 * El rol se añade sólo si no está (`JSON_CONTAINS`), y los pagos se rellenan sólo donde está
 * vacío. Volver a ejecutarla no duplica roles ni pisa un cobrador ya asignado a mano.
 */
final class Version20260815210000 extends AbstractMigration
{
    /** Susan: quien llevó los cobros hasta hoy. */
    private const SUSAN = 'susan@live.com.pe';

    /** Los tres que pasan a poder cobrar. */
    private const COBRADORES = [
        'jorge@live.com.pe',
        'susan@live.com.pe',
        'maria@dummie.com',
    ];

    public function getDescription(): string
    {
        return 'Asigna a Susan los pagos históricos sin cobrador y da ROLE_COBRADOR a Jorge, Susan y María.';
    }

    public function up(Schema $schema): void
    {
        // 1. El histórico. Sólo donde está vacío: si alguien ya asignó un cobrador a mano,
        //    esa decisión manda sobre la nuestra.
        $this->addSql(<<<'SQL'
            UPDATE pms_pago_financiero
            SET cobrador_id = (SELECT id FROM user WHERE email = :susan)
            WHERE cobrador_id IS NULL
              AND EXISTS (SELECT 1 FROM user WHERE email = :susan)
        SQL, ['susan' => self::SUSAN]);

        // 2. El perfil, a los tres. `JSON_ARRAY_APPEND` sobre `$` añade al final del array;
        //    el `NOT JSON_CONTAINS` evita duplicar si ya lo tuviera.
        foreach (self::COBRADORES as $i => $email) {
            $this->addSql(<<<'SQL'
                UPDATE user
                SET roles = JSON_ARRAY_APPEND(roles, '$', 'ROLE_COBRADOR')
                WHERE email = :email
                  AND NOT JSON_CONTAINS(roles, '"ROLE_COBRADOR"')
            SQL, ['email' => $email]);
        }
    }

    /**
     * ⚠️ Revertir **no distingue** los cobradores que puso esta migración de los que se hayan
     * asignado a mano después: vacía todos los que apunten a Susan. Es información de negocio
     * —quién cobró qué— y recuperarla es preguntárselo a alguien, así que conviene no bajar
     * esta migración a la ligera.
     */
    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE pms_pago_financiero
            SET cobrador_id = NULL
            WHERE cobrador_id = (SELECT id FROM user WHERE email = :susan)
        SQL, ['susan' => self::SUSAN]);

        foreach (self::COBRADORES as $email) {
            $this->addSql(<<<'SQL'
                UPDATE user
                SET roles = JSON_REMOVE(
                    roles,
                    JSON_UNQUOTE(JSON_SEARCH(roles, 'one', 'ROLE_COBRADOR'))
                )
                WHERE email = :email
                  AND JSON_CONTAINS(roles, '"ROLE_COBRADOR"')
            SQL, ['email' => $email]);
        }
    }
}
