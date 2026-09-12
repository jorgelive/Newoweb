<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La plantilla «Instrucciones caja del dinero», que sólo puede mandar un operador.
 *
 * ── Por qué una plantilla y no una skill nueva ─────────────────────────────
 * Porque el camino ya existía: `enviar_plantilla` manda un texto aprobado de antemano y exige
 * `ROLE_MENSAJES_WRITE`, cerrado por `GuardiaDeSkills` y no por una frase en el prompt. El huésped
 * no puede dispararla ni pidiéndolo. Y el catálogo de plantillas se compone leyendo `agente_uso`
 * de la base, así que esto entra en circulación **sin desplegar código**.
 *
 * ── Un cuerpo por canal, y no es un adorno ─────────────────────────────────
 * | canal | qué dice el paso 3 | por qué |
 * |---|---|---|
 * | WhatsApp (`whatsapp_link_tmpl`) | «mándanosla por aquí» | ya está en WhatsApp: responde y listo |
 * | OTA (`beds24_tmpl`) | «por WhatsApp al `{{ whatsapp_numero }}`» | 🔥 **en el chat de Booking el huésped NO puede adjuntar imágenes** — su caja de mensaje no tiene botón. Ver `PmsChannel::CHAT_SIN_IMAGENES` |
 *
 * ⚠️ `beds24_tmpl` es un cuerpo para TODAS las OTA, y por ahí entran Booking y Airbnb. El chat de
 * Airbnb sí admite imágenes, así que a ese huésped se le manda a WhatsApp sin necesidad estricta.
 * Se acepta: la foto por WhatsApp nos llega directamente en vez de quedarse en el chat de una
 * plataforma que hay que estar mirando. Partirlo por OTA no existe hoy.
 *
 * **Email queda inactivo a propósito**: nadie manda por correo instrucciones para dejar efectivo
 * en una caja. Vacío significa «este canal no la ofrece», en vez de un texto muerto que un día
 * alguien dispara.
 *
 * ── Sólo el español ────────────────────────────────────────────────────────
 * Los otros seis idiomas los rellena la autotraducción, y los `{{ marcadores }}` van a salvo:
 * `ProtectorDeMarcadores` los enmascara antes de mandarlos a traducir. Sin él, Google los traduce
 * —no los pierde, **les cambia el nombre**— y el huésped acaba leyendo el marcador en crudo. Es lo
 * que le pasó al italiano de la guía (`{{ dati_wifi }}`, `{{ mappa: }}`), de antes de esa defensa.
 *
 * ⚠️ **El importe NO va en el texto.** `{{ importe_a_pagar }}` puede venir vacío, y si lo acordado
 * fue pagar sólo una parte, el importe calculado no es el que toca. La cifra la dice el operador.
 *
 * ⚠️ A 13/09/2026 `video_caja_dinero` apunta **al vídeo de las llaves**, como provisional. El texto
 * dice «la de arriba» y el vídeo enseña la de abajo: no ponerla en circulación hasta que esté el
 * vídeo bueno.
 */
final class Version20260913200000 extends AbstractMigration
{
    private const CODE = 'caja_dinero';

    /** Los pasos 1, 2 y 4 son iguales en los dos canales; sólo cambia el 3. */
    private static function cuerpo(string $paso3): string
    {
        return "Hola {{ guest_name }}, para dejar el pago en efectivo:\n\n"
            . "1️⃣ En el pasadizo hay dos cajas fuertes digitales. La del pago es la de ARRIBA "
            . "— la de abajo es la de las llaves.\n"
            . "2️⃣ Marca el código {{ codigo_caja_dinero }} y gira la perilla hacia la derecha "
            . "para abrir.\n"
            . $paso3 . "\n"
            . "4️⃣ Cierra girando la perilla hacia la izquierda y asegúrate de que quedó bien "
            . "cerrada.\n\n"
            . "📷 Así es la caja: {{ foto_caja_dinero }}\n"
            . '🎥 Cómo se abre: {{ video_caja_dinero }}';
    }

    public function getDescription(): string
    {
        return 'Crea la plantilla caja_dinero, con un cuerpo por canal.';
    }

    public function up(Schema $schema): void
    {
        $porWhatsapp = self::cuerpo(
            '3️⃣ Antes de cerrar, toma una foto del dinero dentro de la caja y mándanosla por '
            . 'aquí: es tu comprobante y el nuestro.'
        );

        $porOta = self::cuerpo(
            '3️⃣ Antes de cerrar, toma una foto del dinero dentro de la caja y mándanosla por '
            . 'WhatsApp al {{ whatsapp_numero }}: es tu comprobante y el nuestro.'
        );

        $envolver = static fn (string $texto): string => json_encode(
            ['body' => [['content' => $texto, 'language' => 'es']]],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $uso = 'Instrucciones para dejar un pago en EFECTIVO en la caja fuerte del pasaje. La '
            . 'manda un operador cuando se ha acordado que el huésped pague así. EL HUÉSPED NO '
            . 'PUEDE PEDIRLA por su cuenta: si la pide él, no la mandes y consulta al equipo. El '
            . 'importe no va en el texto: dilo tú en el mensaje.';

        $this->addSql(
            "INSERT INTO msg_template
                (id, code, name, parameters, email_tmpl, beds24_tmpl, whatsapp_meta_tmpl,
                 whatsapp_link_tmpl, created_at, updated_at, context_type, allowed_sources,
                 allowed_agencies, sobreescribir_traduccion, agente_uso, autoenvio_habilitada)
             SELECT UUID_TO_BIN(UUID()), :code, :name, '[]',
                    '{\"body\": [], \"subject\": [], \"is_active\": false}',
                    :ota,
                    '{\"body\": [], \"is_active\": true, \"is_official_meta\": false}',
                    :wa,
                    NOW(), NULL, 'pms_reserva', NULL, NULL, 0, :uso, 0
              FROM DUAL
             WHERE NOT EXISTS (SELECT 1 FROM (SELECT code FROM msg_template) t WHERE t.code = :code2)",
            [
                'code' => self::CODE,
                'code2' => self::CODE,
                'name' => 'Instrucciones caja del dinero',
                'ota' => $envolver($porOta),
                'wa' => $envolver($porWhatsapp),
                'uso' => $uso,
            ]
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM msg_template WHERE code = :code', ['code' => self::CODE]);
    }
}
