<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Domótica: atar un aparato a una casita no es publicárselo al huésped.
 *
 * Los 23 aparatos atados a sus siete casitas incluyen detectores de gas, la lámpara de la cocina y
 * el switch del corredor. Están ahí porque físicamente están ahí — pero la pantalla del cliente no
 * es el inventario de la casa: es lo que a él le sirve, que hoy son los calefactores.
 *
 * Es la TERCERA pregunta independiente sobre un aparato, y las tres hacen falta:
 *
 *   mideConsumo         lo que el aparato PUEDE hacer      ← lo dice el aparato
 *   activo              si lo QUEREMOS muestrear           ← lo decide operaciones
 *   visibleParaHuesped  si el CLIENTE lo ve                ← lo decide quien atiende
 *
 * Nace en `false` por la misma asimetría que las capacidades: uno de menos hace que alguien
 * pregunte por qué no lo ve; uno de más le enseña a un huésped un detector de gas o el consumo de
 * una zona común. Lo segundo no se descubre preguntando.
 */
final class Version20260909012000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Domótica: visible_para_huesped — atar a la casita no es publicar al cliente.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE domotica_dispositivo ADD visible_para_huesped TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE domotica_dispositivo DROP visible_para_huesped');
    }
}
