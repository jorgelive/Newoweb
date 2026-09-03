<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F5 · A quién del grupo aplica cada componente, y con qué código va cada persona.
 *
 * ⚠️ **Escrita a mano a propósito.** `make:migration` generó además cuatro `DROP TABLE`:
 * `energia_dispositivo`, `energia_lectura` y `energia_suscripcion` —restos vacíos del
 * renombrado a `Domotica*`— y `pms_evento_calendario_backup_20260808`, que es un **respaldo
 * manual con 19 filas**. Ninguno tiene que ver con este cambio, y el último habría borrado datos
 * que alguien guardó a conciencia.
 *
 * La limpieza de esos restos es una decisión aparte, con su propia migración y su propio momento.
 * Una migración hace lo que dice su nombre y nada más.
 */
final class Version20260903055207 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Componente → subgrupo al que aplica, y código de cada persona en su subgrupo.';
    }

    public function up(Schema $schema): void
    {
        // NULL = aplica a todos. Ver el docblock de CotizacionCotcomponente::$grupo: es la misma
        // convención que en skills y guía —sin acotar en vez de invisible—, y es lo que evita
        // tener que etiquetar los veinte componentes que sí son de todo el grupo.
        $this->addSql("ALTER TABLE cotizacion_cotcomponente ADD grupo_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)'");
        $this->addSql('ALTER TABLE cotizacion_cotcomponente ADD CONSTRAINT FK_30B080E29C833003 FOREIGN KEY (grupo_id) REFERENCES cotizacion_file_grupo (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_30B080E29C833003 ON cotizacion_cotcomponente (grupo_id)');

        // El localizador de vuelo, el número de habitación. Va en la PERTENENCIA porque no es de
        // la persona: es suyo en ese grupo, y cambia de un eje a otro.
        $this->addSql('ALTER TABLE cotizacion_pasajero_grupo ADD codigo VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotcomponente DROP FOREIGN KEY FK_30B080E29C833003');
        $this->addSql('DROP INDEX IDX_30B080E29C833003 ON cotizacion_cotcomponente');
        $this->addSql('ALTER TABLE cotizacion_cotcomponente DROP grupo_id');
        $this->addSql('ALTER TABLE cotizacion_pasajero_grupo DROP codigo');
    }
}
