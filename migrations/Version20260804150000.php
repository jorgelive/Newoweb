<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Puebla el catálogo público: descripciones, galerías y ubicación pasan a `publico`.
 *
 * Hasta ahora el catálogo arrancaba vacío a propósito (ver Version20260803230000):
 * publicar era una decisión editorial ítem a ítem. Esta migración toma esa
 * decisión en bloque para el contenido de escaparate, que es el que no tiene
 * ningún valor operativo y sí lo tiene comercial.
 *
 * CRITERIO: el icono. No hay otro campo que distinga estos ítems — el `tipo`
 * de ítem (card/album/alert) mezcla descripciones con normas y avisos, y el
 * `tipo` de sección solo existe en tres secciones y deja fuera el resto. El
 * icono es lo único que el editor viene usando de forma consistente:
 *
 *   fa-circle-info   → descripción de la unidad
 *   fa-images        → galería de fotos
 *   fa-location-dot  → ubicación / cómo llegar
 *
 * DOS EXCLUSIONES, porque esto expone contenido a cualquiera sin reserva:
 *
 *  1. Ítems con un placeholder sensible en el cuerpo. Misma lista y misma
 *     detección que Version20260803230000: si el texto interpola un código, el
 *     ítem no es de escaparate por mucho que lleve el icono de galería.
 *  2. Ítems que hoy están en `solo-ventana`. Ahí alguien los bloqueó a
 *     propósito —fotos de la caja de llaves, de la puerta— y una migración no
 *     debe deshacer una decisión editorial explícita.
 *
 * Ver docs/PmsGuiaHuesped.md §2 y §3.
 */
final class Version20260804150000 extends AbstractMigration
{
    /** Iconos que identifican contenido de escaparate. */
    private const ICONOS_PUBLICOS = [
        'fa-circle-info',
        'fa-images',
        'fa-location-dot',
    ];

    /** Idéntica a Version20260803230000: si cambia allí, cambia aquí. */
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
        return 'Pasa a publico los items de guia con icono de descripcion, galeria o ubicacion.';
    }

    public function up(Schema $schema): void
    {
        $iconos = implode(', ', array_map(
            fn (string $icono): string => $this->connection->quote($icono),
            self::ICONOS_PUBLICOS,
        ));

        // El cuerpo es un JSON [{language, content}]: basta buscar el placeholder
        // como subcadena del documento entero. Si aparece en una traducción, el
        // ítem es sensible en todas. Tolerante a `{{clave}}` y `{{ clave }}`.
        $condicionesSensibles = [];
        foreach (self::PLACEHOLDERS_SENSIBLES as $clave) {
            $condicionesSensibles[] = sprintf(
                'descripcion LIKE %s',
                $this->connection->quote('%{{%' . $clave . '%}}%'),
            );
        }

        $this->addSql(sprintf(
            <<<'SQL'
                UPDATE pms_guia_item
                SET visibilidad = 'publico'
                WHERE icono IN (%s)
                  AND visibilidad <> 'solo-ventana'
                  AND NOT (descripcion IS NOT NULL AND (%s))
                SQL,
            $iconos,
            implode(' OR ', $condicionesSensibles),
        ));
    }

    public function down(Schema $schema): void
    {
        // Vuelven a `cliente`, el default del modelo. No se puede distinguir a
        // posteriori cuáles ya eran públicos ANTES de esta migración, pero como
        // el catálogo arrancaba vacío (Version20260803230000 lo dejó todo en
        // privado), en la práctica el conjunto coincide.
        $iconos = implode(', ', array_map(
            fn (string $icono): string => $this->connection->quote($icono),
            self::ICONOS_PUBLICOS,
        ));

        $this->addSql(sprintf(
            "UPDATE pms_guia_item SET visibilidad = 'cliente' WHERE icono IN (%s) AND visibilidad = 'publico'",
            $iconos,
        ));
    }
}
