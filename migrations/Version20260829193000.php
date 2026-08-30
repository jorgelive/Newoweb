<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `banos_privados` pasa a ser `banos`, y se recuentan las siete casitas.
 *
 * ── Por qué ─────────────────────────────────────────────────────────────────
 * La columna significaba «cuántas HABITACIONES tienen baño propio», no cuántos baños hay. El
 * nombre invitaba a la lectura contraria y el 29/08/2026 costó una venta: a «¿alguna cuenta con
 * 2 baños?» el agente contestó que no, teniendo la Casita 7 dos baños. Leyó el `1` de «una
 * habitación con baño en suite» como «un baño en total».
 *
 * Peor todavía, las cuatro casitas pequeñas tenían `0` —ninguna habitación en suite—, que leído
 * como total dice que no tienen baño.
 *
 * Todos los baños de las casitas son privados: el apartamento es independiente y no se comparte,
 * así que la distinción privado/compartido no aportaba nada y sólo daba pie a confundirlos.
 *
 * ── Por qué va por migración y no por comando ───────────────────────────────
 * Es esquema (un `CHANGE`) más un número entero que ninguna entidad recalcula al guardarse y que
 * no dispara ningún listener: `PmsUnidad` sólo tiene los de slug y de medios, y ninguno mira este
 * campo. Ver la tabla de CLAUDE.md.
 */
final class Version20260829193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'pms_unidad.banos_privados → banos, con los valores reales de cada casita.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_unidad CHANGE banos_privados banos SMALLINT DEFAULT NULL');

        // Los valores reales, confirmados por Jorge el 29/08/2026: las tres grandes tienen dos
        // baños y las cuatro pequeñas uno. Se escriben por nombre y no por id porque el id es
        // binario y el nombre es lo que se puede leer y verificar de un vistazo.
        $this->addSql('UPDATE pms_unidad SET banos = 2 WHERE nombre IN ("Casita 1", "Casita 6", "Casita 7")');
        $this->addSql('UPDATE pms_unidad SET banos = 1 WHERE nombre IN ("Casita 2", "Casita 3", "Casita 4", "Casita 5")');
    }

    public function down(Schema $schema): void
    {
        // Se devuelve el nombre, no los valores: los de antes eran los que causaron el fallo y
        // restaurarlos sería reintroducirlo. Quien haga rollback quiere el esquema viejo, no
        // volver a decirle a un huésped que la Casita 3 no tiene baño.
        $this->addSql('ALTER TABLE pms_unidad CHANGE banos banos_privados SMALLINT DEFAULT NULL');
    }
}
