<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retira `cotizacion_cotizacion.proveedor_oculto`: dos interruptores para lo mismo, uno muerto.
 *
 * La visibilidad del prestador se decidía con un OR entre un flag GLOBAL de la cotización y la
 * marca por componente. **Apuntaban en direcciones opuestas**: el global por defecto decía «no
 * ocultes» y el del componente «no muestres», y como el restrictivo gana, el global no podía
 * usarse nunca en la dirección útil — sólo servía para ocultar lo que ya estaba oculto.
 *
 * Los datos lo confirman: `proveedor_oculto = true` en **0 de 11** cotizaciones. Nunca se activó.
 *
 * Queda un único mecanismo, `CotizacionCotcomponente::$prestadorVisible`: se siembra de
 * `TravelOrganizacion::$visibleParaCliente` al asignar el prestador y se edita por línea con el
 * checkbox «Nombrarlo al cliente».
 *
 * ⚠️ Sin pérdida de información: la columna está a `false` en todas las filas, que es justo el
 * valor que el código asumía al retirarla. Se comprobó antes de escribir esto.
 */
final class Version20260828010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retira el flag global proveedor_oculto; la visibilidad la decide sólo el componente.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotizacion DROP proveedor_oculto');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotizacion ADD proveedor_oculto TINYINT(1) DEFAULT 0 NOT NULL');
    }
}
