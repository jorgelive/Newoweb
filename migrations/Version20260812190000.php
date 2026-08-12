<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * «Menú de tours» no tenía «Nombre en Meta», y por eso nadie pudo aprobarla nunca.
 *
 * El campo `whatsapp_meta_tmpl.meta_template_name` es la clave por la que Meta identifica una
 * plantilla. En `menu_tours` estaba a `null` desde siempre, y eso rompía las dos direcciones a
 * la vez, en silencio:
 *
 * - **Al subir**, `WhatsappMetaTemplatePushService` manda ese valor como `name` del payload:
 *   con `null` la plantilla no llegaba a Meta, así que nunca entró en revisión.
 * - **Al bajar**, `WhatsappMetaTemplateSyncService` empareja por ese mismo nombre. Nada casaba,
 *   de modo que el sincronizador —que corre cada noche y toca las once restantes— pasaba de
 *   largo por ésta.
 *
 * El resultado es el peor de los dos mundos: el panel enseñaba `PENDING` en los siete idiomas
 * y ese `PENDING` era **texto local escrito a mano**, no un estado de Meta. Parecía una
 * aprobación que tardaba; era una plantilla que jamás se envió. Comprobado: `is_official_meta`
 * seguía en `false` —lo pone el sync en todo lo que toca— y `updated_at` estaba clavado en
 * 2026-07-16 aunque el sync corrió esta madrugada y otra vez a mano.
 *
 * Coste real: 9 envíos fallidos entre marzo y el 2026-08-02, todos huéspedes que pidieron el
 * menú de tours con la ventana de 24 h ya cerrada.
 *
 * ── Qué hace esta migración, y qué NO hace ──────────────────────────────────
 * Rellena el nombre. **No sube nada a Meta**: eso sigue siendo un botón del panel, y con razón
 * —subir un idioma reabre su revisión—. Después de esto, «Subir a Meta» ya funciona; el estado
 * real llegará solo en la siguiente pasada del sincronizador.
 *
 * Se usa el mismo `code` como nombre, que es la convención de las otras once. Ninguna plantilla
 * de Meta se llama hoy `menu_tours`: si la hubiera, el sync ya habría creado su gemela
 * `MENU_TOURS_META`, y no existe.
 */
final class Version20260812190000 extends AbstractMigration
{
    private const string CODE = 'menu_tours';

    public function getDescription(): string
    {
        return 'menu_tours recupera su «Nombre en Meta»: sin él no se podía subir ni sincronizar';
    }

    public function up(Schema $schema): void
    {
        $this->ponerNombre(self::CODE);
    }

    /**
     * Se revierte a `null`, que es como estaba. No se toca nada más: si para entonces la
     * plantilla ya está en Meta, quitarle el nombre aquí sólo la desconecta del sincronizador
     * —vuelve al estado roto—, no la borra de allí.
     */
    public function down(Schema $schema): void
    {
        $this->ponerNombre(null);
    }

    private function ponerNombre(?string $nombre): void
    {
        $filas = $this->connection->executeStatement(
            "UPDATE msg_template
                SET whatsapp_meta_tmpl = JSON_SET(whatsapp_meta_tmpl, '$.meta_template_name', :nombre),
                    updated_at = NOW()
              WHERE code = :code
                AND whatsapp_meta_tmpl IS NOT NULL",
            ['nombre' => $nombre, 'code' => self::CODE]
        );

        $this->write(sprintf(
            $filas === 0
                ? '  ⚠️ No se encontró la plantilla «%s»; no se toca nada.'
                : '  «%s» apunta ahora a ' . ($nombre ?? 'null') . ' en Meta.',
            self::CODE
        ));
    }
}
