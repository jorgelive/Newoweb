<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Texto del aviso «importes referenciales» del conmutador a soles de la tarjeta del huésped.
 *
 * Lo pinta `PmsReservaView.vue` al activar el conmutador, y NO es opcional: el backend manda
 * un único tipo de cambio —el del día— para toda la tarjeta, no el congelado de cada cargo.
 * Sin este aviso, el huésped puede leer una cifra en soles y creer que es lo que se le va a
 * cobrar. El cobro real va siempre en la moneda de la cabecera.
 *
 * La frase termina SIN la moneda a propósito: la vista le añade el código
 * (`{{ finanzas?.moneda }}.`) justo después, para no tener siete textos con «USD» incrustado
 * que habría que rehacer el día que una reserva llegue en otra divisa.
 *
 * Van los SIETE idiomas del diccionario (es, en, pt, fr, it, de, nl), que es el juego que ya
 * usan el resto de claves `res_*`.
 */
final class Version20260809225558 extends AbstractMigration
{
    private const string CLAVE = 'res_soles_referencial';

    public function getDescription(): string
    {
        return 'Añade la clave res_soles_referencial al diccionario del huésped, en 7 idiomas.';
    }

    public function up(Schema $schema): void
    {
        $contenido = json_encode([
            ['content' => 'Importes referenciales al tipo de cambio de hoy. El cobro se realiza en', 'language' => 'es'],
            ['content' => "Reference amounts at today's exchange rate. Payment is charged in", 'language' => 'en'],
            ['content' => 'Valores de referência à taxa de câmbio de hoje. A cobrança é feita em', 'language' => 'pt'],
            ['content' => 'Montants indicatifs au taux de change du jour. Le paiement est effectué en', 'language' => 'fr'],
            ['content' => "Importi indicativi al tasso di cambio odierno. L'addebito viene effettuato in", 'language' => 'it'],
            ['content' => 'Referenzbeträge zum heutigen Wechselkurs. Die Abrechnung erfolgt in', 'language' => 'de'],
            ['content' => 'Referentiebedragen tegen de wisselkoers van vandaag. De betaling gebeurt in', 'language' => 'nl'],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        // Idempotente: si alguien ya dio de alta la clave desde el panel, manda la suya. Una
        // migración no debería pisar un texto que un humano ajustó después.
        $this->addSql(
            'INSERT INTO pax_ui_i18n (id, scope, contenido, created_at, sobreescribir_traduccion)
             VALUES (:id, :scope, :contenido, NOW(), 0)
             ON DUPLICATE KEY UPDATE id = id',
            ['id' => self::CLAVE, 'scope' => 'reserva', 'contenido' => $contenido]
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM pax_ui_i18n WHERE id = :id', ['id' => self::CLAVE]);
    }
}
