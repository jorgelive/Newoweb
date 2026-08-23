<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Se caen `tipodocumento` y `numerodocumento` del pasajero.
 *
 * Los sustituye `cotizacion_pasajero_identificacion`, una fila por documento y con vencimiento.
 *
 * ⚠️ **Este paso va DESPUÉS de correr `app:cotizacion:pasajeros-a-identificaciones` en
 * producción**, no en el mismo despliegue que lo creó: el comando lee de estas columnas. Se corrió
 * el 23/08/2026 — 2 pasaportes copiados, con su país emisor.
 *
 * La guarda de abajo lo comprueba en vez de confiar: si quedara algún pasajero con documento en la
 * columna vieja y sin su fila, la migración **aborta** en lugar de tirar el dato.
 */
final class Version20260823230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quita tipodocumento y numerodocumento de cotizacion_file_pasajero';
    }

    public function preUp(Schema $schema): void
    {
        $huerfanos = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*)
            FROM cotizacion_file_pasajero p
            LEFT JOIN cotizacion_pasajero_identificacion i
                   ON i.pasajero_id = p.id AND i.tipo = p.tipodocumento
            WHERE p.tipodocumento IS NOT NULL
              AND TRIM(COALESCE(p.numerodocumento, '')) <> ''
              AND i.id IS NULL
        SQL);

        $this->abortIf(
            $huerfanos > 0,
            sprintf(
                'Hay %d pasajeros con documento en la columna vieja y sin su identificación. '
                .'Corre primero: php bin/console app:cotizacion:pasajeros-a-identificaciones',
                $huerfanos,
            ),
        );
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_file_pasajero DROP tipodocumento, DROP numerodocumento');
    }

    public function down(Schema $schema): void
    {
        // El dato no vuelve: vive en las identificaciones. Se recrean vacías para poder rodar
        // hacia atrás, y quien lo necesite lo saca de la tabla nueva.
        $this->addSql('ALTER TABLE cotizacion_file_pasajero ADD tipodocumento VARCHAR(20) DEFAULT NULL, ADD numerodocumento VARCHAR(100) DEFAULT NULL');
    }
}
