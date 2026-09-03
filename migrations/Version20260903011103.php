<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `publicado`: la visibilidad deja de depender del estado.
 *
 * Hasta ahora el cliente veía una propuesta si su ESTADO era enviado o confirmado, y eso obligaba
 * a mentir sobre un acto comercial para conseguir una visibilidad: «para ver la cotización antes
 * de mandarla tengo que ponerle enviada». Son dos preguntas distintas.
 *
 * ⚠️ **La traducción del dato existente es exacta**: se publica lo que hoy es visible, ni más ni
 * menos. El día del despliegue ningún cliente ve nada distinto — que es la única forma aceptable
 * de mover una regla de visibilidad sobre enlaces que ya están en manos de gente.
 *
 * Ver `docs/PlanPropuestaOperativa.md` §2 y F1.
 */
final class Version20260903011103 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade cotizacion_cotizacion.publicado y traduce los estados visibles de hoy.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotizacion ADD publicado TINYINT(1) DEFAULT 0 NOT NULL');

        // Exactamente los estados que `CotizacionEstadoEnum::esPublico()` devolvía true.
        $this->addSql("UPDATE cotizacion_cotizacion SET publicado = 1 WHERE estado IN ('enviado', 'confirmado')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotizacion DROP publicado');
    }
}
