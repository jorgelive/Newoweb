<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Textos del bloque de prepago en el estado de cuenta del huésped, en los siete idiomas.
 *
 * Una clave por cada valor de {@see \App\Pms\Enum\PmsPoliticaPrepago}, más el rótulo. El
 * enum resuelve la clave con `claveI18n()`, así que añadir una política nueva obliga a
 * añadir aquí su texto — si falta, el front cae al código de la clave y se nota enseguida.
 *
 * **Por qué el texto importa tanto como el importe.** El prepago no es flujo de caja: es un
 * compromiso, y su función es que cancelar le cueste algo al huésped. Una cifra suelta no
 * disuade a nadie; «equivalente a la primera noche» sí, porque la reconoce y se la puede
 * explicar. Por eso estos textos existen antes que el cálculo.
 */
final class Version20260810001500 extends AbstractMigration
{
    /**
     * Clave => [idioma => texto].
     *
     * Las frases NO llevan el importe ni la moneda: los pone la vista al lado, igual que en
     * `res_soles_referencial`. Con la cifra incrustada habría que rehacer 35 textos el día
     * que cambie el formato.
     */
    private const array TEXTOS = [
        'res_prepago_titulo' => [
            'es' => 'Prepago', 'en' => 'Prepayment', 'pt' => 'Pré-pagamento',
            'fr' => 'Prépaiement', 'it' => 'Prepagamento', 'de' => 'Vorauszahlung',
            'nl' => 'Vooruitbetaling',
        ],
        'res_prepago_primera_noche_total' => [
            'es' => 'Equivalente a la primera noche',
            'en' => "Equivalent to the first night's stay",
            'pt' => 'Equivalente à primeira noite',
            'fr' => 'Équivalent à la première nuit',
            'it' => 'Equivalente alla prima notte',
            'de' => 'Entspricht der ersten Übernachtung',
            'nl' => 'Gelijk aan de eerste nacht',
        ],
        'res_prepago_primera_noche_alojamiento' => [
            'es' => 'Equivalente a la primera noche de alojamiento',
            'en' => "Equivalent to the first night's accommodation",
            'pt' => 'Equivalente à primeira noite de hospedagem',
            'fr' => "Équivalent à la première nuit d'hébergement",
            'it' => 'Equivalente alla prima notte di soggiorno',
            'de' => 'Entspricht der ersten Übernachtung (nur Unterkunft)',
            'nl' => 'Gelijk aan de eerste overnachting',
        ],
        'res_prepago_mitad_total' => [
            'es' => '50 % del total de la reserva',
            'en' => '50% of the booking total',
            'pt' => '50% do total da reserva',
            'fr' => '50 % du total de la réservation',
            'it' => '50% del totale della prenotazione',
            'de' => '50 % des Buchungsgesamtbetrags',
            'nl' => '50% van het boekingstotaal',
        ],
        'res_prepago_mitad_alojamiento' => [
            'es' => '50 % del alojamiento',
            'en' => '50% of the accommodation',
            'pt' => '50% da hospedagem',
            'fr' => "50 % de l'hébergement",
            'it' => '50% del soggiorno',
            'de' => '50 % der Unterkunft',
            'nl' => '50% van het verblijf',
        ],
    ];

    public function getDescription(): string
    {
        return 'Textos del prepago para el huésped (5 claves × 7 idiomas).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TEXTOS as $clave => $traducciones) {
            $contenido = [];

            foreach ($traducciones as $idioma => $texto) {
                $contenido[] = ['content' => $texto, 'language' => $idioma];
            }

            // Idempotente: si alguien ya ajustó el texto desde el panel, manda el suyo.
            $this->addSql(
                'INSERT INTO pax_ui_i18n (id, scope, contenido, created_at, sobreescribir_traduccion)
                 VALUES (:id, :scope, :contenido, NOW(), 0)
                 ON DUPLICATE KEY UPDATE id = id',
                [
                    'id' => $clave,
                    'scope' => 'reserva',
                    'contenido' => json_encode($contenido, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (array_keys(self::TEXTOS) as $clave) {
            $this->addSql('DELETE FROM pax_ui_i18n WHERE id = :id', ['id' => $clave]);
        }
    }
}
