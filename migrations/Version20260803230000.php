<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Añade `visibilidad` a los ítems de la guía (público / privado / llegada).
 *
 * Es el campo que permite separar el catálogo público de la guía del huésped:
 * hasta ahora ambos servían el mismo árbol completo de secciones. Ver
 * App\Pms\Enum\PmsGuiaVisibilidad y docs/PmsGuiaHuesped.md §2.
 *
 * Backfill en dos pasos, elegido para NO cambiar lo que ve nadie hoy:
 *
 *  1. Todo a 'privado'. Es el default conservador: sacar contenido al
 *     escaparate tiene que ser una decisión editorial explícita, nunca el
 *     efecto secundario de una migración. El catálogo arranca vacío a
 *     propósito, y se puebla marcando ítems desde el panel.
 *
 *  2. Los ítems cuyo cuerpo usa un placeholder sensible pasan a 'llegada'.
 *     Son exactamente los que GuiaHelperResponseTrait ya enmascaraba fuera de
 *     la ventana de 24 h, así que marcarlos reproduce el comportamiento actual
 *     en vez de inventar uno nuevo.
 */
final class Version20260803230000 extends AbstractMigration
{
    /**
     * Placeholders que hoy se sustituyen por máscara o por el mensaje de
     * bloqueo cuando la estancia no está autorizada. `wifi_data` es la forma
     * antigua que RichContentEngine normaliza a `{{ widget: wifi }}`.
     */
    private const PLACEHOLDERS_SENSIBLES = [
        'door_code',
        'safe_code',
        'keybox_main',
        'keybox_sec',
        'wifi_data',
        'widget',
    ];

    public function getDescription(): string
    {
        return 'Añade pms_guia_item.visibilidad (publico|privado|llegada) y clasifica el contenido existente.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE pms_guia_item
            ADD visibilidad VARCHAR(20) DEFAULT 'privado' NOT NULL
            SQL);

        // Paso 1: explícito aunque el DEFAULT ya lo cubra. Las filas existentes
        // se rellenan con el default al añadir la columna NOT NULL, pero dejarlo
        // escrito documenta la intención y hace la migración idempotente si se
        // reejecuta sobre una columna ya creada a mano.
        $this->addSql("UPDATE pms_guia_item SET visibilidad = 'privado'");

        // Paso 2: el cuerpo es un JSON [{language, content}]; basta buscar el
        // placeholder como subcadena del documento entero, sin recorrer idiomas:
        // si aparece en una traducción, el ítem es sensible en todas.
        $condiciones = [];
        foreach (self::PLACEHOLDERS_SENSIBLES as $clave) {
            // Tolerante a los espacios que admite el parser: {{clave}} y {{ clave }}.
            $condiciones[] = sprintf(
                "descripcion LIKE %s",
                $this->connection->quote('%{{%' . $clave . '%}}%')
            );
        }

        $this->addSql(sprintf(
            "UPDATE pms_guia_item SET visibilidad = 'llegada' WHERE descripcion IS NOT NULL AND (%s)",
            implode(' OR ', $condiciones)
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_guia_item DROP visibilidad');
    }
}
