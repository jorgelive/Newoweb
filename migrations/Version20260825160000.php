<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Da de alta la plantilla del aviso interno de COBRO (`aviso_cobro_interno`).
 *
 * Hermana de `Version20260808161207` (el aviso de escalado) y por el mismo motivo: un operador
 * de guardia no le escribe al número del negocio casi nunca, así que su ventana de 24 h está
 * cerrada prácticamente siempre y fuera de ella Meta sólo acepta plantillas aprobadas.
 *
 * La usa `FinAvisoDeCobro` cuando un enlace de pago se cobra (§11 ter de
 * docs/FinanzasEnlacesPago.md). Con los enlaces de prepago el huésped paga solo, a cualquier
 * hora, y hasta ahora nadie se enteraba hasta que alguien abría la reserva.
 *
 * ### ⚠️ Los nombres de las variables son IGUALES en los siete idiomas
 *
 * Y eso hay que defenderlo, porque el traductor automático quiere traducirlos. En la plantilla
 * del escalado se le escaparon: el código manda `huesped`/`motivo`, pero el cuerpo en portugués
 * pide `{{guest}}`/`{{reason}}` y el neerlandés `{{gast}}`/`{{reden}}`. En esos idiomas la
 * variable no hidrata, llega vacía y `WhatsappMetaSendMappingStrategy` lanza — el aviso no sale.
 * Está dormido sólo porque el hilo del operador nace en español.
 *
 * Aquí `{{cliente}}`, `{{importe}}` y `{{concepto}}` se escriben idénticos en todos. Si algún
 * día se regenera este contenido con el traductor, hay que volver a comprobarlo.
 *
 * ### Sólo la parte de WhatsApp
 *
 * `email_tmpl`, `beds24_tmpl` y `whatsapp_link_tmpl` quedan vacíos a propósito: Beds24 es el
 * canal del huésped en las OTA y aquí no pinta nada. El aviso interno sale por WhatsApp o no
 * sale, y así `MessageDispatcher::resolveChannels()` resuelve un único canal.
 *
 * ### ⚠️ Esta migración NO la aprueba Meta
 *
 * El cuerpo se inserta como `PENDING`, que es la verdad: la plantilla existe en el PMS y todavía
 * no en Meta. Hasta que esté aprobada, el aviso **dentro** de ventana sale igual (texto libre) y
 * **fuera** queda en `no_avisados`, registrado en el log. Falta:
 *
 * 1. Crear en el WhatsApp Manager una plantilla **UTILITY** llamada `aviso_cobro_interno` con
 *    este mismo cuerpo, los mismos parámetros con nombre y el botón de URL dinámica.
 * 2. Aprobada: `php bin/console app:whatsapp:sync-templates`, que casa por `meta_template_name`,
 *    pisa el `status` con el real y conserva el `resolver_key` de los botones.
 */
final class Version20260825160000 extends AbstractMigration
{
    private const string CODIGO = 'aviso_cobro_interno';

    /** Fijo, no aleatorio: así una reejecución no puede acabar con dos filas. */
    private const string ID = '01a03c5f-bea4-7db9-a247-d28f1b9d6054';

    public function getDescription(): string
    {
        return 'Plantilla de WhatsApp para el aviso interno de cobro al equipo.';
    }

    public function up(Schema $schema): void
    {
        // Idempotente: si alguien ya la creó a mano desde el panel, no se pisa. Su contenido es
        // editable por una persona y el sync de Meta también lo toca.
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
                'name' => 'Aviso interno de cobro',
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

    private function existe(): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM msg_template WHERE code = ?',
            [self::CODIGO]
        ) > 0;
    }

    /** @return array<string, mixed> */
    private function plantillaWhatsapp(): array
    {
        $cuerpos = [
            'es' => "💰 *{{cliente}}* ha pagado {{importe}}.\n\nConcepto: {{concepto}}\n\nYa está registrado en Finanzas.",
            'en' => "💰 *{{cliente}}* paid {{importe}}.\n\nFor: {{concepto}}\n\nIt is already recorded in Finance.",
            'pt' => "💰 *{{cliente}}* pagou {{importe}}.\n\nReferente a: {{concepto}}\n\nJá está registado em Finanças.",
            'fr' => "💰 *{{cliente}}* a payé {{importe}}.\n\nConcept : {{concepto}}\n\nC'est déjà enregistré dans Finances.",
            'it' => "💰 *{{cliente}}* ha pagato {{importe}}.\n\nCausale: {{concepto}}\n\nÈ già registrato in Finanze.",
            'de' => "💰 *{{cliente}}* hat {{importe}} bezahlt.\n\nVerwendungszweck: {{concepto}}\n\nEs ist bereits in Finanzen erfasst.",
            'nl' => "💰 *{{cliente}}* heeft {{importe}} betaald.\n\nBetreft: {{concepto}}\n\nHet is al vastgelegd in Financiën.",
        ];

        $cabeceras = [
            'es' => 'Aviso interno', 'en' => 'Internal alert', 'pt' => 'Aviso interno',
            'fr' => 'Avis interne', 'it' => 'Avviso interno', 'de' => 'Interne Mitteilung',
            'nl' => 'Interne mededeling',
        ];

        // ⚠️ «PMS» es el sistema, no el síndrome premenstruel. Ver el `down()` del pie francés
        // del aviso de escalado, donde el traductor automático lo tradujo así de verdad.
        $pies = [
            'es' => 'Aviso automático del PMS', 'en' => 'Automatic PMS alert',
            'pt' => 'Notificação automática do PMS', 'fr' => 'Notification automatique du PMS',
            'it' => 'Notifica automatica del PMS', 'de' => 'Automatische PMS-Benachrichtigung',
            'nl' => 'Automatische PMS-melding',
        ];

        $botones = [
            'es' => 'Ver en Finanzas', 'en' => 'View in Finance', 'pt' => 'Ver em Finanças',
            'fr' => 'Voir dans Finances', 'it' => 'Vedi in Finanze', 'de' => 'In Finanzen ansehen',
            'nl' => 'Bekijk in Financiën',
        ];

        $porIdioma = static fn (array $textos, array $extra = []): array => array_map(
            static fn (string $lang, string $content): array => $extra + ['content' => $content, 'language' => $lang],
            array_keys($textos),
            array_values($textos),
        );

        return [
            'header' => $porIdioma($cabeceras, ['format' => 'TEXT']),
            'body' => $porIdioma($cuerpos, ['status' => 'PENDING']),
            'footer' => $porIdioma($pies),
            'buttons_map' => [[
                'type' => 'url',
                'index' => 0,
                'content' => 'https://util.openperu.pe/{{1}}',
                'button_text' => $porIdioma($botones),
                // Lo único que Meta no sabe y el sync conserva: de dónde sale el sufijo.
                'resolver_key' => 'finanzas_path',
            ]],
            'category' => 'UTILITY',
            'is_active' => true,
            'is_official_meta' => true,
            'meta_template_name' => self::CODIGO,
        ];
    }
}
