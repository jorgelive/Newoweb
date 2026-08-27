<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Arrastra a las cotizaciones la visibilidad que el catálogo ya concedía.
 *
 * `prestadorVisible` se siembra de `TravelOrganizacion::$visibleParaCliente` **al asignar el
 * prestador, y sólo entonces**. Marcar la empresa en el catálogo después no toca nada: la semilla
 * ya cayó. Eso dejó componentes ocultos cuyo hotel el catálogo ya permite nombrar —el operador
 * marcó los hoteles y no pasó nada, sin ningún aviso de que había que volver a sembrar—.
 *
 * Esto corrige ese desfase una vez. **No lo convierte en herencia viva**: la semilla sigue siendo
 * semilla, y el día que se marque otra empresa habrá que reasignar o repetir esta corrección. Es
 * deliberado — la cotización manda sobre el catálogo, ver `docs/Cotizaciones.md`.
 *
 * ⚠️ **Sólo enciende, nunca apaga.** Un componente que alguien ocultó a mano se queda oculto
 * aunque el maestro diga que sí: esa decisión es de la propuesta y no la revierte una migración.
 *
 * ⚠️ Esto **cambia lo que ve un cliente con el enlace ya enviado**: pasa a leer el nombre del
 * hotel donde antes no había nada. Es lo pedido, y sólo alcanza a empresas que el catálogo ya
 * declara nombrables.
 */
final class Version20260828020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enciende prestadorVisible donde el maestro ya lo permitía y la semilla había caído antes.';
    }

    public function up(Schema $schema): void
    {
        // ⚠️ El JOIN convierte los ids a mano: `prestador_maestro_id` es `varchar(36)` con guiones
        // y `travel_organizacion.id` es `binary(16)`. En crudo no casa ninguno y NO da error: da
        // cero filas. Ver `App\Api\Filter\UuidRelacionFilter`.
        $tocados = $this->connection->executeStatement(
            'UPDATE cotizacion_cotcomponente k
               JOIN travel_organizacion o
                 ON HEX(o.id) = UPPER(REPLACE(k.prestador_maestro_id, "-", ""))
                SET k.prestador_visible = 1
              WHERE k.prestador_visible = 0
                AND o.visible_para_cliente = 1'
        );

        $this->write(sprintf('    <info>%d componentes pasan a nombrar a su prestador</info>', $tocados));
    }

    public function down(Schema $schema): void
    {
        // No se revierte: no queda registro de cuáles estaban apagados por decisión y cuáles por
        // desfase, y apagarlos todos borraría decisiones que nadie tomó aquí.
        $this->throwIrreversibleMigrationException(
            'Corrección de datos puntual: no se guardó qué componentes estaban ocultos a propósito.'
        );
    }
}
