<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Devuelve `context_type = 'staff'` a la plantilla del aviso de escalado.
 *
 * ### Qué protege
 *
 * `ValidTemplateScopeValidator` compara el `contextType` de la plantilla con el de la
 * conversación y **rechaza el envío si no coinciden**. Pero sólo si la plantilla lo tiene
 * puesto: con `null` la trata como GLOBAL y la deja mandar a cualquier hilo — incluido el de un
 * huésped.
 *
 * Y esta plantilla dice literalmente *«🔔 {{huesped}} está esperando respuesta … Abre el chat
 * para contestarle»*. Mandársela al propio huésped no es un formato raro: es contarle que
 * alguien está esperando por él, con un botón al panel interno.
 *
 * ### Por qué hacía falta la migración
 *
 * `Version20260808161207` ya la insertó con `'staff'`. Alguien lo vació después —el campo es
 * editable desde el panel—, así que el candado llevaba tiempo abierto sin que nada fallara,
 * que es la forma en que este proyecto se hace daño.
 *
 * Se restaura sólo si está en NULL: si alguien lo puso a otro valor a propósito, esa decisión
 * se respeta y se dice en la salida.
 *
 * Va por SQL directo con conciencia: `context_type` es una columna plana, sin `#[AutoTranslate]`
 * ni listeners de coherencia detrás.
 */
final class Version20260826220000 extends AbstractMigration
{
    private const string CODIGO = 'aviso_escalado_interno';
    private const string CONTEXTO = 'staff';

    public function getDescription(): string
    {
        return 'Restaura context_type=staff en aviso_escalado_interno (impide mandársela a un huésped).';
    }

    public function up(Schema $schema): void
    {
        $actual = $this->connection->fetchOne(
            'SELECT context_type FROM msg_template WHERE code = ?',
            [self::CODIGO]
        );

        if ($actual === false) {
            $this->write(sprintf('  -> No existe la plantilla "%s"; nada que restaurar.', self::CODIGO));

            return;
        }

        if ($actual !== null && $actual !== '') {
            $this->write(sprintf(
                '  -> "%s" ya tiene context_type="%s"; se respeta y no se toca.',
                self::CODIGO,
                (string) $actual
            ));

            return;
        }

        $this->addSql(
            'UPDATE msg_template SET context_type = :contexto WHERE code = :code AND context_type IS NULL',
            ['contexto' => self::CONTEXTO, 'code' => self::CODIGO]
        );

        $this->write(sprintf('  -> "%s" vuelve a estar acotada a "%s".', self::CODIGO, self::CONTEXTO));
    }

    public function down(Schema $schema): void
    {
        // No se revierte: dejarla global era el fallo, no el estado deseado.
        $this->write('  -> No se revierte: sin contexto, la plantilla se podría mandar a un huésped.');
    }
}
