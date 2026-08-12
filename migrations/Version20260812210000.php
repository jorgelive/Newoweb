<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * «Menú de tours» deja de ser un catálogo pegado en el mensaje y pasa a ser un enlace.
 *
 * ── El problema medido ──────────────────────────────────────────────────────
 * El cuerpo llevaba el catálogo entero —ocho tours con precios, horarios y entradas— y medía
 * entre **3701 y 4228 caracteres** según el idioma. El tope de Meta para el BODY son **1024**,
 * así que la plantilla NO se podía subir: los siete idiomas devolvían «Invalid parameter | El
 * campo Body no puede superar los 1024 caracteres». No es cuestión de podar frases; sobran
 * 2700-3200 caracteres. Un catálogo no cabe en una plantilla de WhatsApp y no va a caber.
 *
 * ── Lo que queda ────────────────────────────────────────────────────────────
 * Cuerpo corto que dice qué hay, y el detalle en el catálogo de pax
 * (`https://pax.openperu.pe/catalogo/2W883T`, «Oferta Cusco»). Es el mismo diseño que ya usa
 * `welcome_airbnb`: botón `url` con `resolver_key: tours_catalog_path`, que ahora resuelve a la
 * ruta buena — el parámetro apuntaba a `/catalog/tours`, que no existe en pax.
 *
 * Los tres canales, porque los tres enseñaban la misma lista:
 *
 * | Columna | Qué lleva |
 * |---|---|
 * | `whatsapp_meta_tmpl` | cuerpo corto + **botón** «Ver tours y precios» |
 * | `beds24_tmpl` | el mismo texto con el enlace en línea (`{{ tours_catalog_url }}`) |
 * | `whatsapp_link_tmpl` | ídem, para el enlace manual desde la reserva |
 *
 * En Beds24 se pone `disable_meta_buttons = true` y el enlace va escrito en el cuerpo, igual que
 * en `welcome_airbnb` y `enviar_guia`: si se dejara la emulación de botones encendida, el enlace
 * saldría dos veces.
 *
 * ── Los `status` se van, y es a propósito ───────────────────────────────────
 * Los siete bloques decían `PENDING` **sin haberse enviado nunca a Meta**: era texto local que
 * parecía una revisión en curso. Se quitan. Sin `status`, el panel muestra «SIN ENVIAR» —que es
 * la verdad— y `hasWhatsappMetaOfficialData()` sigue devolviendo `false`, así que no se puede
 * usar fuera de la ventana de 24 h hasta que Meta la apruebe de verdad. Cuando se suba y se
 * apruebe, el sincronizador de las 03:15 escribirá los estados reales.
 *
 * ⚠️ Sin vuelta atrás: `down()` no reconstruye el catálogo viejo. La copia de las tres columnas
 * tal como estaban se tomó antes de escribir esto.
 */
final class Version20260812210000 extends AbstractMigration
{
    private const string CODE = 'menu_tours';

    /** Los siete idiomas del proyecto, en el orden en que estaban. */
    private const array IDIOMAS = ['es', 'en', 'pt', 'fr', 'it', 'de', 'nl'];

    /**
     * El cuerpo, por idioma. Corto a propósito: lo que no cabe aquí está en el catálogo.
     *
     * Se trata de usted en todos los idiomas que lo distinguen, como el resto de plantillas
     * (ver `welcome_airbnb`). Sin marcadores: este mensaje no depende de la reserva.
     */
    private const array CUERPOS = [
        'es' => "🌄 *Tours y excursiones en Cusco*\n\nMachu Picchu, Valle Sagrado, Montaña de 7 Colores, laguna Humantay, City Tour y cuatrimotos.\n\nEn el catálogo verá precios, horarios y qué incluye cada uno. Dígame cuál le interesa y se lo reservamos.",
        'en' => "🌄 *Tours and day trips in Cusco*\n\nMachu Picchu, Sacred Valley, Rainbow Mountain, Humantay Lake, City Tour and quad bikes.\n\nThe catalogue shows prices, departure times and what each one includes. Tell me which one you like and we will book it for you.",
        'pt' => "🌄 *Passeios e excursões em Cusco*\n\nMachu Picchu, Vale Sagrado, Montanha Colorida, lagoa Humantay, City Tour e quadriciclos.\n\nNo catálogo você vê preços, horários e o que cada um inclui. Diga qual lhe interessa e reservamos para você.",
        'fr' => "🌄 *Excursions à Cusco*\n\nMachu Picchu, Vallée Sacrée, Montagne aux 7 couleurs, lagune Humantay, City Tour et quads.\n\nLe catalogue indique les prix, les horaires et ce que chaque excursion comprend. Dites-moi laquelle vous intéresse et nous la réservons.",
        'it' => "🌄 *Escursioni a Cusco*\n\nMachu Picchu, Valle Sacra, Montagna Arcobaleno, laguna Humantay, City Tour e quad.\n\nNel catalogo trova prezzi, orari e cosa include ciascuna. Mi dica quale le interessa e la prenotiamo.",
        'de' => "🌄 *Ausflüge in Cusco*\n\nMachu Picchu, Heiliges Tal, Regenbogenberg, Humantay-See, City Tour und Quads.\n\nIm Katalog finden Sie Preise, Uhrzeiten und Leistungen. Sagen Sie mir, welcher Ausflug Sie interessiert, und wir buchen ihn.",
        'nl' => "🌄 *Excursies in Cusco*\n\nMachu Picchu, Heilige Vallei, Regenboogberg, Humantay-meer, City Tour en quads.\n\nIn de catalogus ziet u prijzen, vertrektijden en wat er inbegrepen is. Laat me weten welke u interesseert en wij boeken hem.",
    ];

    /**
     * La etiqueta del botón. Meta corta a 25 caracteres; el más largo de aquí mide 21.
     */
    private const array ETIQUETAS = [
        'es' => 'Ver tours y precios',
        'en' => 'See tours and prices',
        'pt' => 'Ver passeios e preços',
        'fr' => 'Voir les excursions',
        'it' => 'Vedi tour e prezzi',
        'de' => 'Touren und Preise',
        'nl' => 'Tours en prijzen',
    ];

    /** La línea del enlace para los canales sin botones. */
    private const array LLAMADAS = [
        'es' => "👉 Vea el catálogo aquí:\n{{ tours_catalog_url }}",
        'en' => "👉 See the catalogue here:\n{{ tours_catalog_url }}",
        'pt' => "👉 Veja o catálogo aqui:\n{{ tours_catalog_url }}",
        'fr' => "👉 Consultez le catalogue ici :\n{{ tours_catalog_url }}",
        'it' => "👉 Consulti il catalogo qui:\n{{ tours_catalog_url }}",
        'de' => "👉 Hier finden Sie den Katalog:\n{{ tours_catalog_url }}",
        'nl' => "👉 Bekijk de catalogus hier:\n{{ tours_catalog_url }}",
    ];

    /** El host lo llevan escrito las demás plantillas de botón; se mantiene igual. */
    private const string URL_BOTON = 'https://pax.openperu.pe/{{1}}';

    public function getDescription(): string
    {
        return 'menu_tours: cuerpo corto y enlace al catálogo en los tres canales (el body no cabía en Meta)';
    }

    public function up(Schema $schema): void
    {
        $meta = $this->connection->fetchOne(
            'SELECT whatsapp_meta_tmpl FROM msg_template WHERE code = :code',
            ['code' => self::CODE]
        );

        if (!is_string($meta) || $meta === '') {
            $this->write(sprintf('  ⚠️ No existe la plantilla «%s»; no se toca nada.', self::CODE));

            return;
        }

        /** @var array<string, mixed> $metaTmpl */
        $metaTmpl = json_decode($meta, true, 512, JSON_THROW_ON_ERROR);

        // Se conserva lo que identifica la plantilla en Meta y se reescribe el contenido.
        $metaTmpl['body'] = array_map(
            static fn (string $idioma): array => ['language' => $idioma, 'content' => self::CUERPOS[$idioma]],
            self::IDIOMAS
        );
        $metaTmpl['header'] = [];
        $metaTmpl['footer'] = [];
        $metaTmpl['buttons_map'] = [[
            'index' => 0,
            'type' => 'url',
            'content' => self::URL_BOTON,
            'resolver_key' => 'tours_catalog_path',
            'button_text' => array_map(
                static fn (string $idioma): array => ['language' => $idioma, 'content' => self::ETIQUETAS[$idioma]],
                self::IDIOMAS
            ),
        ]];

        $this->connection->executeStatement(
            'UPDATE msg_template
                SET whatsapp_meta_tmpl = :meta,
                    beds24_tmpl = :beds24,
                    whatsapp_link_tmpl = :link,
                    updated_at = NOW()
              WHERE code = :code',
            [
                'meta' => $this->aJson($metaTmpl),
                'beds24' => $this->aJson([
                    'body' => $this->cuerposConEnlace(),
                    'is_active' => true,
                    // El enlace ya va escrito arriba: emular el botón lo pondría dos veces.
                    'disable_meta_buttons' => true,
                ]),
                'link' => $this->aJson(['body' => $this->cuerposConEnlace()]),
                'code' => self::CODE,
            ]
        );

        foreach (self::IDIOMAS as $idioma) {
            $this->write(sprintf('  %s: cuerpo de %d caracteres (tope de Meta: 1024).', $idioma, mb_strlen(self::CUERPOS[$idioma])));
        }
    }

    /**
     * No se reconstruye el catálogo viejo.
     *
     * Eran 3701-4228 caracteres por idioma que Meta no acepta: devolverlos dejaría la plantilla
     * exactamente igual de imposible de subir que estaba. La copia de las tres columnas se
     * guardó antes de la migración, y si hiciera falta el texto se pega desde ahí en el panel.
     */
    public function down(Schema $schema): void
    {
        $this->write('  Sin vuelta atrás: el catálogo viejo no cabía en Meta. Ver la copia previa.');
    }

    /**
     * El mismo cuerpo más la línea del enlace, para los canales que no tienen botones.
     *
     * @return list<array{language: string, content: string}>
     */
    private function cuerposConEnlace(): array
    {
        return array_map(
            static fn (string $idioma): array => [
                'language' => $idioma,
                'content' => self::CUERPOS[$idioma] . "\n\n" . self::LLAMADAS[$idioma],
            ],
            self::IDIOMAS
        );
    }

    /** @param array<string, mixed> $datos */
    private function aJson(array $datos): string
    {
        return json_encode($datos, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
