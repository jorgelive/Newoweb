<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La unidad aprende a cobrar la limpieza y a saber qué servicio le añade cada OTA.
 *
 * Hasta ahora una cotización era `alojamiento + suplemento de pax` y nada más. Faltaban dos
 * conceptos que sí existen en la realidad, y su ausencia se pagaba en dinero:
 *
 * 1. **La limpieza.** Se cobraba —«suplemento de limpieza», 15.00 USD— pero como texto libre
 *    en `pms_cargo_financiero`, tecleado a mano cada vez. Se le encontraron tres grafías
 *    distintas conviviendo, que es la huella de que no era un concepto sino una costumbre.
 *    Ninguna cotización automática lo incluía: el precio salía siempre corto.
 * 2. **El servicio de la OTA.** No lo cobra el PMS —lo aplica Booking por su cuenta— pero sin
 *    tenerlo escrito no hay forma de cuadrar lo que el huésped acaba pagando allí con lo que
 *    se cotiza aquí, ni de saber si una diferencia es la comisión o un error.
 *
 * ── Por qué una TABLA de canales y no un booleano ───────────────────────────
 * El porcentaje no es del alojamiento, es de cada canal: Booking y Airbnb aplican el suyo y
 * un directo no aplica ninguno. Un `aplica_servicio` de sí/no obligaría a preguntar aparte
 * «¿en cuáles?», y esa pregunta acabaría resuelta con una constante en PHP — que es justo lo
 * que este proyecto evita en los maestros. Con `pms_unidad_servicio_canal` se responde con un
 * JOIN y se edita desde el panel.
 *
 * ── La base del porcentaje ──────────────────────────────────────────────────
 * Alojamiento + suplemento de pax. **La limpieza queda fuera.** No se decide aquí sino en
 * `PmsUnidad::servicioSobre()`, que es la fuente única; esto se anota para que quien lea la
 * migración sepa que el 16% no se aplica sobre el total.
 *
 * ── Siembra ─────────────────────────────────────────────────────────────────
 * 15.00 de limpieza y 16.00 % de servicio en TODAS las unidades activas, con el servicio
 * marcado en `booking` y `airbnb`. Es el valor que se venía cobrando a mano; se siembra para
 * no dejar el campo en 0.00 y que la primera cotización siga saliendo corta.
 *
 * Los `UPDATE` sólo escriben donde el valor sigue en `0.00`, y los `INSERT` de canales van
 * con `NOT EXISTS`: la migración es reejecutable y no pisa un ajuste hecho desde el panel.
 */
final class Version20260811180000 extends AbstractMigration
{
    /** Canales donde se aplica el servicio al sembrar. Los directos quedan fuera. */
    private const array CANALES_CON_SERVICIO = ['booking', 'airbnb'];

    private const string LIMPIEZA_INICIAL = '15.00';
    private const string SERVICIO_INICIAL = '16.00';

    public function getDescription(): string
    {
        return 'pms_unidad: precio_limpieza y porcentaje_servicio + tabla de canales donde aplica el servicio';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE pms_unidad
                ADD precio_limpieza NUMERIC(10, 2) DEFAULT '0.00' NOT NULL,
                ADD porcentaje_servicio NUMERIC(5, 2) DEFAULT '0.00' NOT NULL
        SQL);

        // `id` de `pms_channel` es un string corto (`booking`, `airbnb`, `directo`), no un
        // UUID: la FK lo sigue tal cual. El ON DELETE CASCADE de los dos lados es lo que evita
        // que borrar un canal o una unidad deje filas huérfanas apuntando a la nada.
        $this->addSql(<<<'SQL'
            CREATE TABLE pms_unidad_servicio_canal (
                unidad_id BINARY(16) NOT NULL,
                channel_id VARCHAR(50) NOT NULL,
                INDEX IDX_unidad_servicio_canal_unidad (unidad_id),
                INDEX IDX_unidad_servicio_canal_channel (channel_id),
                PRIMARY KEY (unidad_id, channel_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE pms_unidad_servicio_canal
                ADD CONSTRAINT FK_unidad_servicio_canal_unidad
                    FOREIGN KEY (unidad_id) REFERENCES pms_unidad (id) ON DELETE CASCADE,
                ADD CONSTRAINT FK_unidad_servicio_canal_channel
                    FOREIGN KEY (channel_id) REFERENCES pms_channel (id) ON DELETE CASCADE
        SQL);

        // ── Siembra ─────────────────────────────────────────────────────────
        $this->addSql(sprintf(
            "UPDATE pms_unidad SET precio_limpieza = '%s' WHERE precio_limpieza = '0.00'",
            self::LIMPIEZA_INICIAL
        ));

        $this->addSql(sprintf(
            "UPDATE pms_unidad SET porcentaje_servicio = '%s' WHERE porcentaje_servicio = '0.00'",
            self::SERVICIO_INICIAL
        ));

        foreach (self::CANALES_CON_SERVICIO as $canal) {
            // El SELECT sobre `pms_channel` y no un valor literal: si el canal no está dado de
            // alta en este entorno, no se inserta nada en vez de reventar por la FK.
            $this->addSql(sprintf(
                <<<'SQL'
                    INSERT INTO pms_unidad_servicio_canal (unidad_id, channel_id)
                    SELECT u.id, c.id
                      FROM pms_unidad u
                      JOIN pms_channel c ON c.id = '%s'
                     WHERE NOT EXISTS (
                           SELECT 1 FROM pms_unidad_servicio_canal x
                            WHERE x.unidad_id = u.id AND x.channel_id = c.id
                       )
                SQL,
                $canal
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pms_unidad_servicio_canal');
        $this->addSql('ALTER TABLE pms_unidad DROP precio_limpieza, DROP porcentaje_servicio');
    }
}
