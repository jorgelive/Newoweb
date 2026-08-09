<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pone a jorge.gomez y susan.acuna de guardia de atención (`ROLE_CUSTOMER_SUPPORT`).
 *
 * Es el rol que `EscalarAlEquipoSkill::guardia()` consulta para saber a quién avisar cuando
 * el agente deja a un huésped esperando. Hasta ahora no lo tenía nadie, así que el escalado
 * dejaba la marca de no leído pero no mandaba ningún WhatsApp.
 *
 * ⚠️ **El rol por sí solo no basta.** `guardia()` descarta a quien no tenga `telefono`
 * relleno, porque el aviso sale por WhatsApp. Los dos usuarios están hoy sin móvil: hay que
 * cargárselo desde el panel para que el escalado llegue a alguien.
 *
 * El rol NO se cuelga de ROLE_ADMIN en `security.yaml` a propósito (ver el docblock de
 * `Roles::CUSTOMER_SUPPORT`): ser admin no implica estar de guardia. Por eso se asigna
 * explícitamente aquí en vez de heredarse.
 */
final class Version20260809213434 extends AbstractMigration
{
    /** Los que entran de guardia. */
    private const array USUARIOS = ['jorge.gomez', 'susan.acuna'];

    private const string ROL = 'ROLE_CUSTOMER_SUPPORT';

    public function getDescription(): string
    {
        return 'Asigna ROLE_CUSTOMER_SUPPORT a jorge.gomez y susan.acuna (guardia de atención).';
    }

    public function up(Schema $schema): void
    {
        // `roles` es una columna JSON: se AÑADE al array existente en vez de sobrescribirlo,
        // o estos usuarios perderían sus permisos de reservas y mensajería.
        //
        // El `NOT JSON_CONTAINS` hace la migración idempotente: si alguien ya tiene el rol
        // —puesto a mano desde el panel antes de que esto corriera— no se duplica. Un rol
        // repetido no rompe Symfony, pero ensucia el dato y confunde al leerlo.
        $this->addSql(
            'UPDATE `user`
             SET roles = JSON_ARRAY_APPEND(roles, \'$\', :rol)
             WHERE username IN (:usuarios)
               AND NOT JSON_CONTAINS(roles, JSON_QUOTE(:rol))',
            ['rol' => self::ROL, 'usuarios' => self::USUARIOS],
            ['usuarios' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
    }

    public function down(Schema $schema): void
    {
        // JSON_REMOVE necesita la RUTA del elemento, no su valor: JSON_SEARCH la localiza.
        // Se hace con el mismo guardia que arriba para no tocar a quien no lo tenga.
        $this->addSql(
            'UPDATE `user`
             SET roles = JSON_REMOVE(roles, JSON_UNQUOTE(JSON_SEARCH(roles, \'one\', :rol)))
             WHERE username IN (:usuarios)
               AND JSON_CONTAINS(roles, JSON_QUOTE(:rol))',
            ['rol' => self::ROL, 'usuarios' => self::USUARIOS],
            ['usuarios' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
    }
}
