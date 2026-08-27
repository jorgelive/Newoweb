<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Habilita a las ferroviarias para que el cliente las vea, y arrastra lo ya cotizado.
 *
 * El tren no es un proveedor que se pueda perder: PeruRail e IncaRail son las dos únicas opciones
 * a Machu Picchu y el pasajero ya sabe en cuál va. Ocultarlas no protegía nada y le quitaba
 * concreción al itinerario — al contrario que con el operador, el guía o el transportista, que sí
 * son a quienes podría contratar directo la próxima vez.
 *
 * Dos pasos, y el segundo hace falta porque el primero no basta: `prestadorVisible` se siembra del
 * maestro **al asignar el prestador**, así que marcar la empresa hoy no toca lo ya cotizado. Es la
 * misma corrección que `Version20260828020000`, aquí acotada a estas dos.
 *
 * ⚠️ **Sólo enciende, nunca apaga**: un componente ocultado a mano se queda oculto.
 *
 * ⚠️ La tarjeta saldrá con el NOMBRE y poco más: ninguna de las dos tiene `url` ni imágenes, al
 * contrario que los hoteles. Se ve el nombre del tren, no una ficha. Completarlo es trabajo de
 * catálogo y va aparte.
 *
 * ⚠️ Y el título público de IncaRail dice «incaRail», en minúscula. **No se corrige aquí**:
 * `TravelOrganizacion::$titulo` lleva `#[AutoTranslate]` y un `UPDATE` se salta el listener, así
 * que dejaría la ficha con la errata arreglada en español y las otras seis lenguas diciendo lo
 * anterior. Se arregla en el panel o por comando. Ver la tabla «migración vs. comando» de
 * `CLAUDE.md`.
 */
final class Version20260828030000 extends AbstractMigration
{
    private const FERROVIARIAS = ['PeruRail', 'IncaRail'];

    public function getDescription(): string
    {
        return 'Habilita PeruRail e IncaRail para la vista del cliente y arrastra los componentes ya cotizados.';
    }

    public function up(Schema $schema): void
    {
        $marcas = implode(',', array_fill(0, \count(self::FERROVIARIAS), '?'));

        $enMaestro = $this->connection->executeStatement(
            "UPDATE travel_organizacion SET visible_para_cliente = 1
              WHERE nombre_comercial IN ($marcas) AND visible_para_cliente = 0",
            self::FERROVIARIAS
        );

        // El JOIN convierte los ids: `prestador_maestro_id` es varchar(36) con guiones y
        // `travel_organizacion.id` es binary(16). En crudo da cero filas sin error.
        $enCotizaciones = $this->connection->executeStatement(
            "UPDATE cotizacion_cotcomponente k
               JOIN travel_organizacion o
                 ON HEX(o.id) = UPPER(REPLACE(k.prestador_maestro_id, '-', ''))
                SET k.prestador_visible = 1
              WHERE k.prestador_visible = 0
                AND o.nombre_comercial IN ($marcas)",
            self::FERROVIARIAS
        );

        $this->write(sprintf(
            '    <info>%d empresas habilitadas · %d componentes ya cotizados pasan a nombrarlas</info>',
            $enMaestro,
            $enCotizaciones
        ));
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'No se guardó qué componentes estaban ocultos por decisión y cuáles por desfase.'
        );
    }
}
