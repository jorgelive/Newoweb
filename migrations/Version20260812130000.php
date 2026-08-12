<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un componente, una fila de La Biblia. Ahora también en la base de datos.
 *
 * La idempotencia del snapshot lo garantizaba con un `findOneBy()`, que es una
 * comprobación y no una restricción. Dos `aplicar` concurrentes con la misma firma
 * —doble clic con latencia, o dos operadores sobre el mismo plan— pasan los dos la
 * validación y crean la fila los dos, porque entre leer y escribir no hay lock. Con
 * filas duplicadas en el cuadro se le pide y se le paga dos veces al proveedor.
 *
 * Verificado antes de aplicar: 0 duplicados en local y 0 en producción.
 *
 * El índice sustituye al `IDX` simple que Doctrine crea para la FK; la FK sigue
 * funcionando sobre el índice único.
 *
 * Ver docs/Operacion.md §3.5.
 */
final class Version20260812130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'operacion_servicio: índice ÚNICO sobre cotizacion_componente_id (una fila por componente).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_servicio DROP INDEX IDX_E7842D25DE357B66, ADD UNIQUE INDEX uniq_ops_servicio_componente (cotizacion_componente_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_servicio DROP INDEX uniq_ops_servicio_componente, ADD INDEX IDX_E7842D25DE357B66 (cotizacion_componente_id)');
    }
}
