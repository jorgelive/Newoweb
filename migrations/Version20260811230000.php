<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cada estancia sabe quién la limpia, y puede ser más de una.
 *
 * Nace por privacidad, no por reparto de trabajo. `listar_entradas_salidas` devuelve el nombre
 * del huésped, su teléfono y el enlace a su chat, y hasta ahora se la daba ENTERA a cualquiera
 * con `ROLE_LIMPIEZA`: quien iba a la Casita 3 veía también el contacto del huésped de la 6. No
 * hay razón para eso, y la forma de cerrarlo es que cada estancia diga de quién es.
 *
 * ── Por evento, y varias ────────────────────────────────────────────────────
 * Por EVENTO y no por casita porque quien limpia cambia por día: quien tenga la Casita 3 esta
 * semana puede no tenerla la siguiente. Y una TABLA y no una columna porque una casita grande
 * se limpia entre dos, y un turno se cubre entre varias. Con un FK simple, el día que hicieran
 * falta dos habría que migrar los datos además del esquema.
 *
 * ── El defecto es un dato, no una constante ─────────────────────────────────
 * `user.es_limpieza_por_defecto` marca a quién se asigna sola cada estancia nueva; lo aplica
 * `PmsLimpiezaAsignacionListener` en `prePersist`. Mismo patrón que `es_cobrador_principal`,
 * que ya existía, y por el mismo motivo: **no hay ningún nombre escrito en el código**. El día
 * que quien limpia hoy tome otro camino, se marca a otra persona en el panel y las estancias
 * nuevas la cogen sin desplegar nada.
 *
 * ── La siembra ─────────────────────────────────────────────────────────────
 * María queda marcada como la de por defecto, y se le asignan las estancias **que todavía no
 * han terminado** (`fin >= CURDATE()`). De las pasadas no consta quién las limpió, y escribir
 * su nombre en meses de historial sería inventar un dato que nadie verificó: se quedan sin
 * asignar, que aquí significa «no consta».
 *
 * Fuera quedan los bloqueos (`reserva_id IS NULL`), las extensiones (`evento_origen_id`) y las
 * canceladas: ninguna da trabajo a nadie.
 *
 * Reejecutable: los `INSERT` van con `NOT EXISTS` y el `UPDATE` sólo toca lo que sigue en 0.
 *
 * ⚠️ María está hoy DESHABILITADA y sin móvil registrado. Lo primero dejó de importar: el
 * listener EXIGÍA `enabled = true` cuando se escribió esto y se le quitó poco después —
 * `enabled` dice si se entra al panel, y quien limpia no tiene login—, así que las estancias
 * nuevas sí la cogen. Lo segundo sigue pendiente: el agente reconoce a la gente por
 * `user.telefono`, y sin móvil registrado no verá nada por el chat. Es un dato que falta, no
 * código.
 */
final class Version20260811230000 extends AbstractMigration
{
    /** Se busca por `username`: los UUID no coinciden entre entornos. */
    private const string LIMPIEZA_POR_DEFECTO = 'maria.apaza';

    public function getDescription(): string
    {
        return 'pms_evento_limpieza (varias personas por estancia) + user.es_limpieza_por_defecto';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE user ADD es_limpieza_por_defecto TINYINT(1) DEFAULT 0 NOT NULL"
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE pms_evento_limpieza (
                evento_id BINARY(16) NOT NULL,
                user_id BINARY(16) NOT NULL,
                INDEX IDX_evento_limpieza_evento (evento_id),
                INDEX IDX_evento_limpieza_user (user_id),
                PRIMARY KEY (evento_id, user_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // CASCADE en los dos lados: son filas de asignación, no datos con valor propio. Si se
        // borra el evento o la persona, la asignación deja de significar nada. Lo que NUNCA
        // debe pasar es lo contrario —que borrar a alguien se lleve por delante una reserva—,
        // y por eso la relación vive en su tabla y no como columna del evento.
        $this->addSql(<<<'SQL'
            ALTER TABLE pms_evento_limpieza
                ADD CONSTRAINT FK_evento_limpieza_evento
                    FOREIGN KEY (evento_id) REFERENCES pms_evento_calendario (id) ON DELETE CASCADE,
                ADD CONSTRAINT FK_evento_limpieza_user
                    FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE
        SQL);

        // ── Siembra ─────────────────────────────────────────────────────────
        $this->addSql(
            'UPDATE user SET es_limpieza_por_defecto = 1 WHERE username = :username',
            ['username' => self::LIMPIEZA_POR_DEFECTO]
        );

        $this->addSql(
            <<<'SQL'
                INSERT INTO pms_evento_limpieza (evento_id, user_id)
                SELECT e.id, u.id
                  FROM pms_evento_calendario e
                  JOIN user u ON u.username = :username
                 WHERE e.reserva_id IS NOT NULL
                   AND e.evento_origen_id IS NULL
                   AND e.estado_id <> 'cancelada'
                   AND e.fin >= CURDATE()
                   AND NOT EXISTS (
                       SELECT 1 FROM pms_evento_limpieza x WHERE x.evento_id = e.id
                   )
            SQL,
            ['username' => self::LIMPIEZA_POR_DEFECTO]
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pms_evento_limpieza');
        $this->addSql('ALTER TABLE user DROP es_limpieza_por_defecto');
    }
}
