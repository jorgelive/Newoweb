<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Las FK de La Biblia dejan de impedir que se edite una cotización confirmada.
 *
 * Las tres referencias al árbol de la cotización eran `RESTRICT`, y eso hacía imposible
 * el flujo que el módulo entero promete. El editor guarda con un PUT del árbol completo:
 * lo que ya no viene en el payload se orfaniza y Doctrine lo borra. Así que quitar un
 * día, borrar un componente o —lo más común de todo— **reemplazar una tarifa** en una
 * cotización ya confirmada terminaba en violación de clave ajena: un 500 en mitad del
 * guardado, sin ningún mensaje que explicara qué pasaba.
 *
 * No se notaba porque en producción La Biblia está vacía. El día que se confirmara la
 * primera cotización, empezaba.
 *
 *  · file, cotservicio y componente → **CASCADE**. Si desaparecen de la cotización, la
 *    fila de tráfico se queda sin origen y no significa nada. Se pierde lo que el
 *    operador hubiera escrito en ella, y es aceptable: alguien decidió que ese servicio
 *    ya no va. Lo que no es aceptable es un 500.
 *
 *  · tarifa → **SET NULL**. Cambiar de tarifa es rutina de negociación y la fila tiene
 *    que sobrevivir para que la reconciliación le ponga la nueva. Con CASCADE, cambiar
 *    de proveedor habría borrado el servicio del cuadro de tráfico.
 *
 * Ver docs/Operacion.md §3.6.
 */
final class Version20260812110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'operacion_servicio: FK del árbol de cotización de RESTRICT a CASCADE/SET NULL.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_servicio DROP FOREIGN KEY FK_E7842D2527A36708');
        $this->addSql('ALTER TABLE operacion_servicio DROP FOREIGN KEY FK_E7842D2593CB796C');
        $this->addSql('ALTER TABLE operacion_servicio DROP FOREIGN KEY FK_E7842D25B9C96117');
        $this->addSql('ALTER TABLE operacion_servicio DROP FOREIGN KEY FK_E7842D25DE357B66');

        $this->addSql('ALTER TABLE operacion_servicio ADD CONSTRAINT FK_E7842D2527A36708 FOREIGN KEY (cotizacion_tarifa_id) REFERENCES cotizacion_cottarifa (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE operacion_servicio ADD CONSTRAINT FK_E7842D2593CB796C FOREIGN KEY (file_id) REFERENCES cotizacion_file (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE operacion_servicio ADD CONSTRAINT FK_E7842D25B9C96117 FOREIGN KEY (cotizacion_servicio_id) REFERENCES cotizacion_cotservicio (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE operacion_servicio ADD CONSTRAINT FK_E7842D25DE357B66 FOREIGN KEY (cotizacion_componente_id) REFERENCES cotizacion_cotcomponente (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_servicio DROP FOREIGN KEY FK_E7842D2527A36708');
        $this->addSql('ALTER TABLE operacion_servicio DROP FOREIGN KEY FK_E7842D2593CB796C');
        $this->addSql('ALTER TABLE operacion_servicio DROP FOREIGN KEY FK_E7842D25B9C96117');
        $this->addSql('ALTER TABLE operacion_servicio DROP FOREIGN KEY FK_E7842D25DE357B66');

        $this->addSql('ALTER TABLE operacion_servicio ADD CONSTRAINT FK_E7842D2527A36708 FOREIGN KEY (cotizacion_tarifa_id) REFERENCES cotizacion_cottarifa (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE operacion_servicio ADD CONSTRAINT FK_E7842D2593CB796C FOREIGN KEY (file_id) REFERENCES cotizacion_file (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE operacion_servicio ADD CONSTRAINT FK_E7842D25B9C96117 FOREIGN KEY (cotizacion_servicio_id) REFERENCES cotizacion_cotservicio (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE operacion_servicio ADD CONSTRAINT FK_E7842D25DE357B66 FOREIGN KEY (cotizacion_componente_id) REFERENCES cotizacion_cotcomponente (id) ON DELETE RESTRICT');
    }
}
