<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Quién confirmó a mano una identificación copiada del escaneo, y cuándo.
 *
 * Sin esto, una ficha creada a partir del propio documento no tenía salida: su número no se puede
 * cotejar contra el escaneo del que salió, así que quedaba «observado» pidiendo «que alguien la
 * confirme» sin que existiera ningún sitio donde confirmar.
 *
 * ⚠️ **Escrita a mano, NO con `migrations:diff`.** El diff automático en este proyecto arrastra
 * todo el desfase entre la base local y la de producción: la generada el 12/09/2026 para estas dos
 * columnas venía además con `DROP TABLE energia_dispositivo`, `energia_lectura`, `energia_suscripcion`
 * y cambios en `pms_establecimiento` y `pms_unidad`. Se descartó entera.
 */
final class Version20260913210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Confirmación humana de una identificación: quién y cuándo';
    }

    public function up(Schema $schema): void
    {
        // Nulables las dos: `NULL` significa «nadie la ha confirmado», que es lo que pasa con todas
        // las filas que ya existen. Sin valor por defecto que rellenar ni columna JSON de por medio.
        $this->addSql('ALTER TABLE cotizacion_pasajero_identificacion '
            . "ADD confirmada_en DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', "
            . 'ADD confirmada_por VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_pasajero_identificacion '
            . 'DROP confirmada_en, DROP confirmada_por');
    }
}
