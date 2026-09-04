<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * Retira ocho componentes de transporte unidireccionales que ya no tienen función.
 *
 * Seis son la mitad sobrante de una fusión bidireccional que ya se hizo: el sentido vive
 * entero en su componente `↔` y esta copia se quedó atrás con cero tarifas y cero enlaces a
 * segmento. Los otros dos —`Htl Cusco - Term Cusco` y `Hotel Lima - Aeropuerto de Lima`— no
 * tienen bidireccional al que volver: el primero nunca tuvo tarifas, y el segundo duplicaba en
 * sus dos tarifas los importes Noche de `Aeropuerto Lima ↔ Miraflores` sin que nadie lo
 * enlazara.
 *
 * ⚠️ **Va por migración y no por comando a propósito.** Aquí no se escribe ni un campo con
 * `#[AutoTranslate]`: sólo se reapunta un `VARCHAR` y se borran filas. La regla de
 * `docs/TravelCargaDeCatalogo.md` §6 —contenido por comando— no aplica a un borrado.
 *
 * ## Por qué el UPDATE va ANTES del DELETE
 *
 * `cotizacion_cotcomponente.componente_maestro_id` es un `VARCHAR(36)` **sin clave ajena**, así
 * que borrar el maestro no da error: deja la referencia apuntando al vacío en silencio. El
 * desacople es deliberado (ver `OperacionServicioLugarExtension`) y el historial de la cotización
 * no se pierde —lleva su propio snapshot—, pero el filtro por lugar del cuadro de tráfico es
 * **vivo**: resuelve el lugar preguntándole al catálogo en cada clic. Sin maestro, esas líneas
 * dejan de salir al filtrar.
 *
 * Medido en producción antes de escribir esto: `Ollanta - Cusco` tiene 7 líneas de cotización
 * apuntándole y `Aeropuerto Cusco - Hotel Cusco` otras 5. Las doce se reapuntan al componente
 * bidireccional superviviente, que es lo que ya hacen `ConsolidarTransporteAeropuertoLimaCommand`,
 * `FusionarRutasPorPuntosCommand` y `NormalizarTransporteUrbanoCommand` antes de borrar.
 *
 * ## Por qué se identifica por nombre y no por UUID
 *
 * Los UUID de estas filas no coinciden necesariamente entre la copia local y producción, y una
 * migración escrita con literales binarios no se puede leer ni revisar. `nombre_interno` es la
 * clave natural del componente según `docs/TravelCargaDeCatalogo.md` §4bis. Como los JOIN son por
 * nombre, un componente que ya no exista simplemente no produce filas: la migración es
 * reentrante y no falla en un entorno donde alguien ya lo borró a mano.
 *
 * Lo que cuelga se lo lleva la base: `travel_tarifa`, `travel_componente_lugar_pool`,
 * `travel_servicio_componentes_pool` y `travel_componente_item.componente_id` son `ON DELETE
 * CASCADE`. `travel_segmento_componente.componente_id` es `RESTRICT`, y por eso se comprobó que
 * los ocho tienen cero enlaces: si alguno tuviera, esta migración fallaría en vez de romper nada.
 */
final class Version20260904121500 extends AbstractMigration
{
    /**
     * Componente que se retira => componente bidireccional que recoge sus referencias.
     *
     * `null` significa que no hay a quién reapuntar: nadie lo estaba usando en cotizaciones.
     */
    private const RETIRADOS = [
        'Transporte Ollanta - Cusco'                 => 'Transporte Cusco ↔ Ollanta (ida o vuelta)',
        'Transporte Aeropuerto Cusco - Hotel Cusco'  => 'Transporte Aeropuerto Cusco ↔ Cusco (ida o vuelta)',
        'Transporte Poroy - Cusco'                   => 'Transporte Cusco ↔ Poroy (ida o vuelta)',
        'Transporte Cusco - San Pedro'               => 'Transporte San Pedro ↔ Cusco (ida o vuelta)',
        'Transporte Cusco - Juliaca'                 => 'Transporte Juliaca ↔ Cusco (ida o vuelta)',
        'Transporte Paradero Cusco - Htl Cusco'      => 'Transporte Htl Cusco ↔ Paradero Cusco (ida o vuelta)',
        'Transporte Htl Cusco - Term Cusco'          => null,
        'Transporte Hotel Lima - Aeropuerto de Lima' => null,
    ];

    public function getDescription(): string
    {
        return 'Retira 8 transportes unidireccionales sobrantes, reapuntando antes sus cotizaciones.';
    }

    public function up(Schema $schema): void
    {
        // 1. Reapuntar. Primero, porque después del DELETE ya no hay de dónde leer el uuid viejo.
        foreach (self::RETIRADOS as $viejo => $nuevo) {
            if ($nuevo === null) {
                continue;
            }

            $this->addSql(
                'UPDATE cotizacion_cotcomponente cc
                    JOIN travel_componente viejo ON viejo.nombre_interno = :viejo
                    JOIN travel_componente nuevo ON nuevo.nombre_interno = :nuevo
                     SET cc.componente_maestro_id = BIN_TO_UUID(nuevo.id)
                   WHERE cc.componente_maestro_id = BIN_TO_UUID(viejo.id)',
                ['viejo' => $viejo, 'nuevo' => $nuevo]
            );
        }

        // 2. Borrar. Lo que cuelga se va por CASCADE; los enlaces a segmento son RESTRICT y están a cero.
        $this->addSql(
            'DELETE FROM travel_componente WHERE nombre_interno IN (:nombres)',
            ['nombres' => array_keys(self::RETIRADOS)],
            ['nombres' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
    }

    public function down(Schema $schema): void
    {
        // Un componente borrado no se reconstruye: se fue con sus tarifas, sus lugares y su
        // pertenencia a los pools. Y el reapuntado tampoco se deshace, porque no queda registro
        // de qué líneas apuntaban al viejo. Volver atrás es recargar el catálogo, no bajar esto.
        throw new IrreversibleMigration(
            'Borra filas de catálogo con sus tarifas y reapunta referencias sin dejar rastro del origen.'
        );
    }
}
