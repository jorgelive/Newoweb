<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `orden` en el servicio de una cotización: dónde va dentro de su día.
 *
 * `0` = automático, y es lo que arranca todo el mundo: el comportamiento no cambia hasta que
 * alguien mueva algo a mano. Por eso no hace falta sembrar nada.
 *
 * Existe porque el desempate entre dos servicios sin hora era el `orden` mínimo de sus segmentos
 * —un número de ordenar DENTRO comparando ENTRE— que vale 1 para todas las plantillas.
 */
final class Version20260829220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cotizacion_cotservicio.orden: posición manual dentro del día, 0 = automático';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotservicio ADD orden INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotservicio DROP orden');
    }
}
