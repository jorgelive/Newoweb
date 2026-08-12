<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fuera la gemela `WELCOME_BOOKING_META`, y lo que apuntara a ella, a la buena.
 *
 * ── De dónde salió ──────────────────────────────────────────────────────────
 * Nadie la creó. La fabricó el sincronizador el 2026-04-05 a las 22:15:04, que es lo único que
 * sabe hacer cuando Meta devuelve un nombre que ninguna fila local reclama: en Meta seguía viva
 * `welcome_booking` —la generación ANTERIOR de la bienvenida, con botones `url`— mientras la
 * plantilla buena ya apuntaba a `welcome_booking_command`, la de botones de comando que dejan
 * rastro de quién pulsó.
 *
 * ── Por qué no puede quedarse ───────────────────────────────────────────────
 * No es un duplicado inofensivo, es una escopeta cargada:
 *
 * - `context_type` y `allowed_sources` VACÍOS, o sea que pasa todos los filtros del panel y
 *   aparece en **todas** las conversaciones, incluso las que no son de Booking. Justo al revés
 *   que la buena, que sólo sale en reservas de Booking.
 * - En el desplegable del chat se lee «Welcome Booking» junto a «Bienvenida a los clientes de
 *   booking.com». Un clic de distancia.
 * - Sus tres botones están rotos: dos `url` sin `resolver_key` —enlace vacío— y un `quick_reply`
 *   sin `resolver_key`, que hace saltar una excepción en `WhatsappMetaSendMappingStrategy` y
 *   **tumba el envío entero**, no sólo el botón.
 * - Y no tiene nada más: `beds24_tmpl`, `whatsapp_link_tmpl` y `email_tmpl` vacíos. No es una
 *   plantilla, es una cáscara.
 *
 * Envíos en toda su vida: **0**. La buena lleva 210.
 *
 * ── Qué hace ────────────────────────────────────────────────────────────────
 * 1. Repunta a la buena cualquier mensaje o regla que la referencie. Hoy no hay ninguno —está
 *    medido— pero borrar una fila referenciada dejaría el mensaje sin plantilla o reventaría
 *    por la clave foránea, y esto cuesta dos `UPDATE`.
 * 2. Borra la fila.
 *
 * ⚠️ **Esto solo se sostiene junto al filtro del sincronizador.** Mientras Meta devuelva
 * `welcome_booking`, cada pasada de las 03:15 volvería a fabricarla; por eso ese nombre está en
 * `WhatsappMetaTemplateSyncService::NOMBRES_IGNORADOS`. Lo definitivo es borrarla en la consola
 * de Meta, y entonces el filtro sobra.
 *
 * La copia de seguridad de la definición que Meta tiene de `welcome_booking` (7 idiomas, con sus
 * ids) se tomó antes de esto.
 */
final class Version20260812200000 extends AbstractMigration
{
    private const string GEMELA = 'WELCOME_BOOKING_META';

    private const string BUENA = 'welcome_booking';

    public function getDescription(): string
    {
        return 'Borra la gemela WELCOME_BOOKING_META y repunta a welcome_booking lo que la referencie';
    }

    public function up(Schema $schema): void
    {
        $gemela = $this->idDe(self::GEMELA);

        if ($gemela === null) {
            $this->write(sprintf('  «%s» ya no existe; no hay nada que borrar.', self::GEMELA));

            return;
        }

        $buena = $this->idDe(self::BUENA);

        if ($buena === null) {
            // Sin destino no se repunta nada, y borrar a ciegas sería peor que no hacer nada.
            $this->write(sprintf('  ⚠️ No existe «%s»: no se toca la gemela.', self::BUENA));

            return;
        }

        foreach (['msg_message', 'msg_rule'] as $tabla) {
            $filas = $this->connection->executeStatement(
                sprintf('UPDATE %s SET template_id = :buena WHERE template_id = :gemela', $tabla),
                ['buena' => $buena, 'gemela' => $gemela]
            );

            if ($filas > 0) {
                $this->write(sprintf('  %s: %d fila(s) repuntadas a «%s».', $tabla, $filas, self::BUENA));
            }
        }

        $this->connection->executeStatement(
            'DELETE FROM msg_template WHERE id = :id',
            ['id' => $gemela]
        );

        $this->write(sprintf('  «%s» borrada.', self::GEMELA));
    }

    /**
     * No se recrea.
     *
     * Revertir esto significaría devolver una plantilla rota que nunca se usó, y no hace falta:
     * si algún día conviene volver a tenerla, basta con quitar `welcome_booking` de
     * `NOMBRES_IGNORADOS` y esperar a las 03:15 — el sincronizador la fabrica igual que la
     * fabricó la primera vez. Lo que esta migración NO puede devolver es el repunte, y ahí no
     * hay pérdida: los mensajes se quedan en la plantilla buena, que es donde deben estar.
     */
    public function down(Schema $schema): void
    {
        $this->write('  Sin vuelta atrás: la gemela la recrea el sincronizador si se quita de NOMBRES_IGNORADOS.');
    }

    private function idDe(string $code): ?string
    {
        $id = $this->connection->fetchOne('SELECT id FROM msg_template WHERE code = :code', ['code' => $code]);

        return $id === false || $id === null ? null : $id;
    }
}
