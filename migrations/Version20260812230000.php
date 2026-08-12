<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\UuidV7;

/**
 * La plantilla «te hemos escrito»: la única salida cuando la ventana de 24 h está cerrada.
 *
 * ── El problema que resuelve ────────────────────────────────────────────────
 * WhatsApp sólo deja escribir texto libre dentro de las 24 h siguientes al último mensaje del
 * huésped. Fuera de esa ventana, el operador escribe, le da a enviar, y el mensaje **no sale**.
 * Pasó el 2026-05-05 con Blandine: reserva directa —WhatsApp era el único canal—, ventana
 * cerrada, y la huésped se quedó sin la respuesta que había pedido.
 *
 * Y no hay forma de arreglarlo metiendo el texto en una plantilla: una plantilla oficial lleva
 * texto **fijo, aprobado por Meta**, no lo que el operador acaba de escribir. Lo único que se
 * puede hacer es avisar al huésped de que hay algo esperándole y pedirle que conteste: **su
 * respuesta reabre la ventana** y entonces el mensaje real sí puede salir.
 *
 * ── Por qué no lleva botones ────────────────────────────────────────────────
 * Un `quick_reply` sería más cómodo, pero exige un `resolver_key` (`CMD_*`) que el motor
 * determinista sepa atender; inventarse uno aquí es la receta del botón roto que ya costó caro
 * en `WELCOME_BOOKING_META`. Cualquier respuesta reabre la ventana, así que el botón no aporta
 * nada que el texto no consiga.
 *
 * ── Estado ──────────────────────────────────────────────────────────────────
 * Nace **sin `status`**: el panel dirá «SIN ENVIAR», que es la verdad. Hay que subirla a Meta
 * desde el panel («Push a Meta») y esperar su aprobación; hasta entonces
 * `hasWhatsappMetaOfficialData()` devuelve `false` y el enganche del flujo no la usará —lo
 * comprueba antes de encolar, así que no hay nada que pueda salir mal por desplegar esto antes
 * de que Meta conteste.
 *
 * Categoría UTILITY y no MARKETING: no vende nada, avisa de algo que el huésped pidió.
 *
 * El enganche con el flujo está en `AvisarEnvioFallidoDispatchHandler`.
 */
final class Version20260812230000 extends AbstractMigration
{
    public const string CODE = 'ventana_cerrada';

    private const array IDIOMAS = ['es', 'en', 'pt', 'fr', 'it', 'de', 'nl'];

    /**
     * Corto y sin prometer nada que no se pueda cumplir.
     *
     * No dice «te responderemos enseguida» —quien contesta es una persona y puede tardar— sino
     * qué tiene que hacer el huésped para que el mensaje le llegue.
     */
    private const array CUERPOS = [
        'es' => "Hola 👋 Le hemos escrito por aquí, pero WhatsApp no nos deja enviarle el mensaje porque han pasado más de 24 horas desde su última respuesta.\n\nResponda cualquier cosa a este chat y se lo mandamos enseguida.",
        'en' => "Hello 👋 We have a message for you, but WhatsApp will not let us send it because more than 24 hours have passed since you last wrote.\n\nJust reply anything to this chat and we will send it right away.",
        'pt' => "Olá 👋 Temos uma mensagem para você, mas o WhatsApp não nos deixa enviá-la porque já se passaram mais de 24 horas desde a sua última resposta.\n\nResponda qualquer coisa neste chat e enviaremos na hora.",
        'fr' => "Bonjour 👋 Nous avons un message pour vous, mais WhatsApp ne nous laisse pas l'envoyer car plus de 24 heures se sont écoulées depuis votre dernière réponse.\n\nRépondez n'importe quoi à ce chat et nous vous l'enverrons aussitôt.",
        'it' => "Salve 👋 Abbiamo un messaggio per lei, ma WhatsApp non ci permette di inviarlo perché sono passate più di 24 ore dalla sua ultima risposta.\n\nRisponda qualsiasi cosa a questa chat e glielo inviamo subito.",
        'de' => "Hallo 👋 Wir haben eine Nachricht für Sie, aber WhatsApp lässt uns nicht zu, sie zu senden, weil seit Ihrer letzten Antwort mehr als 24 Stunden vergangen sind.\n\nAntworten Sie einfach irgendetwas in diesem Chat, dann schicken wir sie sofort.",
        'nl' => "Hallo 👋 We hebben een bericht voor u, maar WhatsApp laat ons dit niet versturen omdat er meer dan 24 uur zijn verstreken sinds uw laatste antwoord.\n\nStuur gerust een willekeurig antwoord in deze chat en we sturen het meteen.",
    ];

    public function getDescription(): string
    {
        return 'Crea la plantilla «ventana_cerrada»: pide al huésped que conteste para poder escribirle';
    }

    public function up(Schema $schema): void
    {
        $existe = $this->connection->fetchOne('SELECT id FROM msg_template WHERE code = :code', ['code' => self::CODE]);

        if ($existe !== false && $existe !== null) {
            $this->write(sprintf('  «%s» ya existe; no se toca.', self::CODE));

            return;
        }

        $cuerpos = array_map(
            static fn (string $idioma): array => ['language' => $idioma, 'content' => self::CUERPOS[$idioma]],
            self::IDIOMAS
        );

        $this->connection->executeStatement(
            'INSERT INTO msg_template (id, code, name, parameters, whatsapp_meta_tmpl, beds24_tmpl, whatsapp_link_tmpl, email_tmpl, agente_uso, created_at, updated_at)
             VALUES (:id, :code, :name, :parametros, :meta, :beds24, :link, :email, :uso, NOW(), NOW())',
            [
                'id' => (new UuidV7())->toBinary(),
                'code' => self::CODE,
                'name' => 'Aviso de ventana cerrada',
                // Sin marcadores: el texto no depende de la reserva, sólo pide una respuesta.
                'parametros' => $this->aJson([]),
                'meta' => $this->aJson([
                    'is_active' => true,
                    'is_official_meta' => false,
                    'meta_template_name' => self::CODE,
                    'category' => 'UTILITY',
                    'body' => $cuerpos,
                    'header' => [],
                    'footer' => [],
                    'buttons_map' => [],
                ]),
                // Sólo WhatsApp: en las OTA no existe la ventana de 24 h, así que este aviso no
                // tiene ningún sentido allí — y ofrecerlo invita a mandarlo donde sobra.
                'beds24' => $this->aJson(['is_active' => false, 'body' => []]),
                'link' => $this->aJson(['body' => []]),
                'email' => $this->aJson(['is_active' => false, 'body' => []]),
                'uso' => 'Automática. La manda el sistema cuando un mensaje escrito por una persona no sale '
                    . 'porque la ventana de 24 h de WhatsApp está cerrada. No hace falta enviarla a mano.',
            ]
        );

        $this->write(sprintf('  Creada «%s» con %d idiomas. Falta subirla a Meta desde el panel.', self::CODE, count(self::IDIOMAS)));
    }

    /**
     * Se borra sólo si nadie la ha usado todavía.
     *
     * En cuanto haya salido un mensaje con ella, borrarla dejaría esos mensajes sin plantilla o
     * reventaría por la clave foránea. Revertir no puede costar más que no revertir.
     */
    public function down(Schema $schema): void
    {
        $usos = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM msg_message m JOIN msg_template t ON t.id = m.template_id WHERE t.code = :code',
            ['code' => self::CODE]
        );

        if ($usos > 0) {
            $this->write(sprintf('  «%s» ya se usó en %d mensaje(s): no se borra.', self::CODE, $usos));

            return;
        }

        $this->connection->executeStatement('DELETE FROM msg_template WHERE code = :code', ['code' => self::CODE]);
        $this->write(sprintf('  «%s» borrada.', self::CODE));
    }

    /** @param array<string, mixed> $datos */
    private function aJson(array $datos): string
    {
        return json_encode($datos, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
