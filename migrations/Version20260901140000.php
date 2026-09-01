<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La botonera emulada queda APAGADA en todas las plantillas, y dicha explícitamente.
 *
 * ── Por qué ─────────────────────────────────────────────────────────────────
 * Decisión de producto (01/09/2026): por ahora ninguna plantilla emula la botonera al final del
 * mensaje. Es una opción que puede volver —por eso sigue siendo una casilla y no se borra el
 * código—, pero hoy no la queremos en ninguna.
 *
 * Y en un caso corregía un defecto real: `recordatorio_llegada` tenía la casilla sin poner en
 * Beds24 y su cuerpo ya escribe «👉 {{guide_url}}» dentro del texto, así que el huésped recibía
 * **el mismo enlace dos veces**, una en la frase y otra en la botonera emulada de abajo.
 *
 * ── Por qué se escribe también donde el default ya lo hacía ─────────────────
 * `isWhatsappLinkMetaButtonsDisabled()` devuelve `true` por defecto, así que el comportamiento
 * ya era el correcto. Pero el formulario del panel pinta la casilla desde el JSON: con la clave
 * ausente se veía **desmarcada** mientras el sistema hacía lo contrario. Una casilla que miente
 * sobre lo que está pasando es peor que no tenerla — se escribe el valor para que el panel diga
 * la verdad.
 *
 * ── Por qué va por migración y no por comando ───────────────────────────────
 * Es una bandera dentro de un JSON. No lleva `#[AutoTranslate]`, ningún listener la mira y
 * ninguna entidad la recalcula al guardarse. Ver la tabla de CLAUDE.md.
 */
final class Version20260901140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'msg_template: disable_meta_buttons = true en beds24_tmpl y whatsapp_link_tmpl de todas.';
    }

    public function up(Schema $schema): void
    {
        // `JSON_SET` sobre un objeto existente; si la columna fuese NULL no se toca, que es lo
        // correcto: sin bloque de canal no hay botonera que apagar.
        $this->addSql("UPDATE msg_template SET beds24_tmpl = JSON_SET(beds24_tmpl, '$.disable_meta_buttons', true) WHERE beds24_tmpl IS NOT NULL");
        $this->addSql("UPDATE msg_template SET whatsapp_link_tmpl = JSON_SET(whatsapp_link_tmpl, '$.disable_meta_buttons', true) WHERE whatsapp_link_tmpl IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        // Se quita la clave en vez de ponerla a `false`: así cada bloque vuelve a su default
        // —`false` en Beds24, `true` en el enlace— que es el estado anterior de verdad.
        $this->addSql("UPDATE msg_template SET beds24_tmpl = JSON_REMOVE(beds24_tmpl, '$.disable_meta_buttons') WHERE beds24_tmpl IS NOT NULL");
        $this->addSql("UPDATE msg_template SET whatsapp_link_tmpl = JSON_REMOVE(whatsapp_link_tmpl, '$.disable_meta_buttons') WHERE whatsapp_link_tmpl IS NOT NULL");
    }
}
