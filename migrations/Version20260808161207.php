<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Da de alta la plantilla del aviso interno de escalado (`aviso_escalado_interno`).
 *
 * ### Por qué hace falta
 *
 * `EscalarAlEquipoSkill` avisa por WhatsApp al operador de guardia. Un operador **no le escribe
 * al número del negocio casi nunca**, así que su ventana de 24 h de Meta está cerrada
 * prácticamente siempre, y fuera de ventana Meta sólo acepta plantillas aprobadas. Las 11
 * plantillas que había son todas para huéspedes: el aviso interno se quedaba encolado y fallaba.
 *
 * ### Sólo la parte de WhatsApp
 *
 * `email_tmpl`, `beds24_tmpl` y `whatsapp_link_tmpl` quedan vacíos a propósito. Beds24 es el
 * canal del huésped en las OTAs y aquí no pinta nada; el aviso interno sale por WhatsApp o no
 * sale. Eso además deja `MessageDispatcher::resolveChannels()` resolviendo un único canal.
 *
 * ### ⚠️ Esta migración NO la aprueba Meta
 *
 * El estado del cuerpo se inserta como `PENDING`, que es la verdad: la plantilla existe en el
 * PMS pero todavía no en Meta. Hasta que esté aprobada, `WhatsappMetaSendEnqueuer` se niega a
 * encolarla fuera de ventana —y hace bien—. Los pasos que faltan, a mano:
 *
 * 1. Crear en el WhatsApp Manager una plantilla **UTILITY** llamada `aviso_escalado_interno`,
 *    con el mismo cuerpo, los mismos parámetros con nombre (`huesped`, `motivo`) y el botón de
 *    URL dinámica.
 * 2. Cuando Meta la apruebe: `php bin/console app:whatsapp:sync-templates`. El sync casa por
 *    `meta_template_name`, pisa el `status` con el real y **conserva el `resolver_key`** de los
 *    botones, que es lo único que Meta no sabe.
 *
 * Mientras tanto el escalado no se rompe: la conversación queda marcada como no leída igual, que
 * es la garantía que no depende de ninguna red. Ver docs/Mensajeria.md §11.
 */
final class Version20260808161207 extends AbstractMigration
{
    private const string CODIGO = 'aviso_escalado_interno';

    /** Fijo, no aleatorio: así una reejecución no puede acabar con dos filas. */
    private const string ID = '019fe338-0752-79c0-89b9-50fdd9eeda29';

    public function getDescription(): string
    {
        return 'Plantilla de WhatsApp para el aviso interno de escalado al equipo.';
    }

    public function up(Schema $schema): void
    {
        // Idempotente: si alguien ya la creó a mano desde el panel, esta migración no la pisa.
        // Su contenido es editable por una persona y el sync de Meta también lo toca.
        if ($this->existe()) {
            $this->write(sprintf('  -> La plantilla "%s" ya existe; no se toca.', self::CODIGO));

            return;
        }

        $this->addSql(
            'INSERT INTO msg_template '
            . '(id, code, name, parameters, context_type, agente_uso, autoenvio_habilitada, '
            . ' allowed_sources, allowed_agencies, email_tmpl, beds24_tmpl, whatsapp_link_tmpl, '
            . ' whatsapp_meta_tmpl, sobreescribir_traduccion, created_at) '
            . 'VALUES (UNHEX(REPLACE(:id, \'-\', \'\')), :code, :name, \'[]\', :contexto, NULL, 0, '
            . ' \'[]\', \'[]\', \'{}\', \'{}\', \'{}\', :meta, 0, NOW())',
            [
                'id' => self::ID,
                'code' => self::CODIGO,
                'name' => 'Aviso interno de escalado',
                // La conversación del operador es de tipo `staff`, no `pms_reserva`. Acotarlo
                // aquí hace que ValidTemplateScopeValidator rechace mandársela a un huésped.
                'contexto' => 'staff',
                'meta' => json_encode($this->plantillaWhatsapp(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM msg_template WHERE code = :code', ['code' => self::CODIGO]);
    }

    /**
     * El JSON de `whatsapp_meta_tmpl`, en la misma forma que el resto de plantillas oficiales.
     *
     * Dos avisos sobre los parámetros, porque los dos rompen **en tiempo de envío** y no antes:
     *
     * - **Ninguno puede llegar vacío.** `WhatsappMetaSendMappingStrategy` lanza excepción si una
     *   variable del cuerpo no viene resuelta, así que `huesped` y `motivo` los rellena siempre
     *   la skill, con valor por defecto incluido.
     * - **Ninguno puede llevar saltos de línea.** Meta rechaza el envío si un parámetro tiene
     *   `\n`, tabuladores o más de cuatro espacios seguidos. Por eso el detalle de márgenes de
     *   ocupación —que es multilínea— NO viaja en la plantilla: sólo se manda en texto libre,
     *   dentro de ventana.
     *
     * @return array<string, mixed>
     */
    private function plantillaWhatsapp(): array
    {
        $cuerpo = "🔔 *{{huesped}}* está esperando respuesta.\n\n"
            . "Necesita: {{motivo}}\n\n"
            . 'Abre el chat para contestarle.';

        $cuerpoEn = "🔔 *{{huesped}}* is waiting for an answer.\n\n"
            . "Needs: {{motivo}}\n\n"
            . 'Open the chat to reply.';

        return [
            'is_active' => true,
            'is_official_meta' => true,
            'meta_template_name' => self::CODIGO,
            'category' => 'UTILITY',
            // PENDING, no APPROVED: es el estado real hasta que Meta la revise, y es lo que
            // mira `MessageTemplate::hasWhatsappMetaOfficialData()` para dejar encolar.
            'body' => [
                ['language' => 'es', 'status' => 'PENDING', 'content' => $cuerpo],
                ['language' => 'en', 'status' => 'PENDING', 'content' => $cuerpoEn],
            ],
            'header' => [
                ['language' => 'es', 'format' => 'TEXT', 'content' => 'Aviso interno'],
                ['language' => 'en', 'format' => 'TEXT', 'content' => 'Internal alert'],
            ],
            'footer' => [
                ['language' => 'es', 'content' => 'Aviso automático del PMS'],
                ['language' => 'en', 'content' => 'Automatic PMS alert'],
            ],
            // URL dinámica con el sufijo al final, que es lo único que Meta acepta. El dominio
            // es el de producción a propósito: la plantilla vive aprobada en Meta y no puede
            // apuntar a `.test`. El sufijo lo pone la skill en `chat_path`.
            'buttons_map' => [[
                'index' => 0,
                'type' => 'url',
                'content' => 'https://util.openperu.pe/{{1}}',
                'resolver_key' => 'chat_path',
                'button_text' => [
                    ['language' => 'es', 'content' => 'Abrir el chat'],
                    ['language' => 'en', 'content' => 'Open the chat'],
                ],
            ]],
        ];
    }

    private function existe(): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM msg_template WHERE code = ?',
            [self::CODIGO]
        );
    }
}
