<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Domótica: cuándo se encendió y cuándo se apagó.
 *
 * De un aparato sin contómetro no se sabía NADA del pasado: `encendido` es una casilla que el
 * muestreo siguiente pisa. Si una estufa estuvo encendida seis horas, no quedaba rastro — y son
 * nueve de los doce calefactores, porque sólo dos miden.
 *
 * `domotica_lectura` no valía: sus tres columnas de consumo son NOT NULL y un interruptor no
 * consume nada que contar. Meter ahí ceros habría ensuciado la tabla que prueba lo que se cobra.
 *
 * Se escribe **sólo al cambiar**, no en cada ciclo: la pregunta que se le hace («¿cuánto llevaba
 * encendida?») se contesta con dos filas y no con las 288 diarias que daría un registro del
 * muestreo.
 *
 * ⚠️ `hora_exacta` distingue la hora que dijo el aparato de la del muestreo que notó el cambio. El
 * lote de estado no trae marcas de tiempo, así que al detectar un cambio se le pregunta al aparato
 * —barato, porque los cambios son raros— y si está desconectado se guarda la del ciclo Y SE DICE.
 * Fingir exactitud es el fallo del que este módulo está lleno de cicatrices.
 *
 * ⚠️ Y esto NO factura. Horas × vatios nominales da una cifra plausible que no cuadra con ningún
 * contador. Ver `docs/Domotica.md` §12.1 y §14.10.
 */
final class Version20260909020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Domótica: domotica_cambio_estado — el historial de on/off que no existía.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE domotica_cambio_estado (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                dispositivo_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                suscripcion_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)',
                encendido TINYINT(1) NOT NULL,
                ocurrido_en DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                hora_exacta TINYINT(1) DEFAULT 0 NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_domotica_cambio_dispositivo (dispositivo_id, ocurrido_en),
                INDEX idx_domotica_cambio_suscripcion (suscripcion_id, ocurrido_en),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql('ALTER TABLE domotica_cambio_estado ADD CONSTRAINT FK_domotica_cambio_dispositivo FOREIGN KEY (dispositivo_id) REFERENCES domotica_dispositivo (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE domotica_cambio_estado ADD CONSTRAINT FK_domotica_cambio_suscripcion FOREIGN KEY (suscripcion_id) REFERENCES domotica_suscripcion (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE domotica_cambio_estado');
    }
}
