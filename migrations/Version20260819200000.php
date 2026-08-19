<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `Proveedor` pasa a ser `TravelOrganizacion`: la empresa es agnóstica, el papel no.
 *
 * ### Por qué
 *
 * En este dominio una misma empresa juega TRES papeles distintos, y así está documentado en
 * {@see \App\Cotizacion\Entity\CotizacionCotcomponente}: el **proveedor** dice de quién es el
 * precio, el **prestador** presta el servicio y el **comprador** es a quién se le encarga la
 * compra. Los tres apuntan a la misma ficha: Futurismo es prestador en su propia excursión y
 * comprador cuando le encargas el Hotel Estelar. El boleto de Machu Picchu lo presta el
 * Ministerio de Cultura y lo compra «Tickets Openperu», que es una división nuestra.
 *
 * Llamar `Proveedor` a esa ficha nombraba uno de los papeles como si fuera la clase de empresa.
 * `TravelOrganizacion` no nombra ninguno: cubre al ministerio —que no es una empresa—, a la
 * agencia, al hotel y a nuestra propia división.
 *
 * ### Qué se toca y qué NO
 *
 * Sólo los nombres de tabla. **Ninguna columna cambia**, incluidas las `proveedor_id`: los
 * papeles llegan en una segunda fase, cuando se decida qué baja a la tarifa.
 *
 * ⚠️ **Fuera de esta migración a propósito:**
 * - `shortName` y `uriTemplate` de API Platform. La API sigue diciendo `/proveedores` y
 *   `ProveedorServicio`, así que `util/` y `pax/` no se enteran y `api.d.ts` no se regenera.
 * - Los mapeos de VichUploader (`travel_proveedor_galeria`, …). No son nombres de dominio:
 *   resuelven a **carpetas en disco** donde ya viven las imágenes subidas.
 * - Los nombres de ruta (`api_travel_proveedor_imagen_upload`) y las URLs.
 *
 * `RENAME TABLE` conserva claves foráneas, índices y datos.
 */
final class Version20260819200000 extends AbstractMigration
{
    /** viejo => nuevo */
    private const array TABLAS = [
        'travel_proveedor'                 => 'travel_organizacion',
        'travel_proveedor_servicio'        => 'travel_organizacion_servicio',
        'travel_proveedor_imagen'          => 'travel_organizacion_imagen',
        'travel_proveedor_servicio_imagen' => 'travel_organizacion_servicio_imagen',
        'travel_proveedor_lugar_pool'      => 'travel_organizacion_lugar_pool',
    ];

    public function getDescription(): string
    {
        return 'travel: las cinco tablas de `proveedor` pasan a `organizacion` (la empresa es agnóstica del papel).';
    }

    /**
     * Los índices, que `RENAME TABLE` NO renombra.
     *
     * Doctrine deriva el nombre de un índice del hash de su tabla, así que al renombrar la
     * tabla espera otro nombre y `doctrine:schema:validate` se queda en rojo para siempre.
     * Funcionar, funcionan igual — pero una validación siempre roja es una que nadie vuelve a
     * mirar, y ahí se esconde el siguiente desajuste de verdad.
     *
     * tabla => [viejo => nuevo]
     */
    private const array INDICES = [
        'travel_proveedor_servicio'        => ['idx_dd9e59b3cb305d73' => 'IDX_EBED15C0CB305D73'],
        'travel_proveedor_imagen'          => ['idx_56179730cb305d73' => 'IDX_3A66B235CB305D73'],
        'travel_proveedor_lugar_pool'      => [
            'idx_f6713b80cb305d73' => 'IDX_8D092A7DCB305D73',
            'idx_f6713b80b5a3803b' => 'IDX_8D092A7DB5A3803B',
        ],
        'travel_proveedor_servicio_imagen' => ['idx_fa3ec107db905830' => 'IDX_3416FCC4DB905830'],
    ];

    public function up(Schema $schema): void
    {
        foreach (self::TABLAS as $viejo => $nuevo) {
            $this->renombrar($viejo, $nuevo);
        }

        foreach (self::INDICES as $tablaVieja => $renombres) {
            foreach ($renombres as $viejo => $nuevo) {
                // Se COMPRUEBA sobre la tabla vieja —la que existe ahora— y se ALTERA sobre la
                // nueva, que existirá cuando corra este SQL.
                $this->renombrarIndice($tablaVieja, self::TABLAS[$tablaVieja], $viejo, $nuevo);
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::INDICES as $tablaVieja => $renombres) {
            foreach ($renombres as $viejo => $nuevo) {
                $nueva = self::TABLAS[$tablaVieja];
                $this->renombrarIndice($nueva, $tablaVieja, $nuevo, $viejo);
            }
        }

        foreach (array_reverse(self::TABLAS, true) as $viejo => $nuevo) {
            $this->renombrar($nuevo, $viejo);
        }
    }

    /**
     * Renombra sólo si el origen existe y el destino no.
     *
     * Sin esto, relanzar la migración en un entorno a medias aborta el lote entero y deja unas
     * tablas renombradas y otras no — que es el peor sitio donde parar.
     */
    private function renombrar(string $origen, string $destino): void
    {
        $existe = static fn (string $t): string => sprintf(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
            "'" . $t . "'"
        );

        if ((int) $this->connection->fetchOne($existe($origen)) === 0) {
            $this->write(sprintf('  · «%s» no existe; se omite.', $origen));

            return;
        }

        if ((int) $this->connection->fetchOne($existe($destino)) > 0) {
            $this->write(sprintf('  · «%s» ya existe; se omite.', $destino));

            return;
        }

        $this->addSql(sprintf('RENAME TABLE %s TO %s', $origen, $destino));
    }

    /**
     * Renombra un índice sólo si existe con el nombre de origen.
     *
     * ⚠️ **`addSql()` ENCOLA, no ejecuta.** Cuando corre este método, los `RENAME TABLE` de
     * arriba todavía no han pasado: la tabla sigue con su nombre viejo. Por eso la comprobación
     * y el `ALTER` van sobre tablas DISTINTAS —`$tablaAhora` para preguntar, `$tablaLuego` para
     * alterar—. La primera versión preguntaba por la tabla nueva, no la encontraba nunca y se
     * saltaba los cinco índices en silencio; sólo se vio porque `schema:validate` seguía rojo.
     *
     * ⚠️ Y la guarda hace falta porque **los nombres de la lista salieron de una base
     * concreta**: Doctrine los deriva de un hash que calculó la versión que creó la tabla, y
     * otro entorno pudo quedarse con otros. Sin ella, un nombre que no coincida aborta la
     * migración a mitad y deja el esquema entre dos estados — peor que un validate en rojo.
     */
    private function renombrarIndice(string $tablaAhora, string $tablaLuego, string $origen, string $destino): void
    {
        $existe = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$tablaAhora, $origen]
        );

        if ($existe === 0) {
            $this->write(sprintf('  · índice «%s» de %s no existe; se omite.', $origen, $tablaAhora));

            return;
        }

        $this->addSql(sprintf('ALTER TABLE %s RENAME INDEX %s TO %s', $tablaLuego, $origen, $destino));
    }
}
