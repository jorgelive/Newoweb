<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recorta el aviso del conmutador a soles para que entre en UNA línea, y añade el rótulo
 * «Detalle» del estado de cuenta.
 *
 * El aviso decía además «El cobro se realiza en USD». En móvil eso ocupaba dos renglones y
 * empujaba el desglose fuera de la pantalla — y era redundante: la moneda de cobro se ve en
 * cada importe en cuanto se desactiva el conmutador.
 *
 * ⚠️ Este SÍ sobrescribe el texto anterior (`UPDATE`, no `INSERT … ON DUPLICATE`): la frase
 * larga se dio de alta hace unas horas en esta misma sesión y nadie la ha ajustado a mano.
 * Si en el futuro se retoca desde el panel, esta migración ya habrá corrido.
 */
final class Version20260810011000 extends AbstractMigration
{
    private const array TEXTOS = [
        'es' => 'Importes referenciales al tipo de cambio de hoy.',
        'en' => "Reference amounts at today's exchange rate.",
        'pt' => 'Valores de referência à taxa de câmbio de hoje.',
        'fr' => 'Montants indicatifs au taux de change du jour.',
        'it' => 'Importi indicativi al tasso di cambio odierno.',
        'de' => 'Referenzbeträge zum heutigen Wechselkurs.',
        'nl' => 'Referentiebedragen tegen de wisselkoers van vandaag.',
    ];

    private const array DETALLE = [
        'es' => 'Detalle', 'en' => 'Details', 'pt' => 'Detalhes',
        'fr' => 'Détail', 'it' => 'Dettaglio', 'de' => 'Details', 'nl' => 'Details',
    ];

    public function getDescription(): string
    {
        return 'Aviso referencial en una línea y rótulo «Detalle» del estado de cuenta.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE pax_ui_i18n SET contenido = :contenido, updated_at = NOW() WHERE id = :id',
            ['id' => 'res_soles_referencial', 'contenido' => self::json(self::TEXTOS)]
        );

        $this->addSql(
            'INSERT INTO pax_ui_i18n (id, scope, contenido, created_at, sobreescribir_traduccion)
             VALUES (:id, :scope, :contenido, NOW(), 0)
             ON DUPLICATE KEY UPDATE id = id',
            ['id' => 'res_detalle', 'scope' => 'reserva', 'contenido' => self::json(self::DETALLE)]
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM pax_ui_i18n WHERE id = :id', ['id' => 'res_detalle']);
        // El texto largo del aviso no se restaura: era peor y nadie lo quiere de vuelta.
    }

    /** @param array<string, string> $textos */
    private static function json(array $textos): string
    {
        $contenido = [];

        foreach ($textos as $idioma => $texto) {
            $contenido[] = ['content' => $texto, 'language' => $idioma];
        }

        return json_encode($contenido, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
