<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Los medios del edificio salen de un campo suelto y pasan a su propia entidad.
 *
 * ── Por qué ────────────────────────────────────────────────────────────────
 * `pms_establecimiento.video_caja_fuerte_url` era un campo suelto, y el experimento salió mal: se
 * creó el 11/09/2026 y sobrevivió **un día entero vacío** porque nadie lo puso en el panel. La
 * guía pedía `{{ video_caja_fuerte }}`, resolvía a nada, y el vídeo seguía copiado a mano dentro
 * de un ítem en los siete idiomas. Añadir tres campos más habría sido repetir esa historia cuatro
 * veces; con `pms_establecimiento_media`, un medio nuevo es un caso del enum.
 *
 * ── El renombrado del marcador ─────────────────────────────────────────────
 * `{{ video_caja_fuerte }}` → `{{ video_caja_llaves }}`. Ahora hay DOS cajas —la de las llaves y
 * la del dinero— y «caja fuerte» ya no dice cuál. Un nombre ambiguo entre dos cosas que abren
 * sitios distintos es exactamente el patrón del `{{ door_code }}` que acabó anunciando «el código
 * de la puerta es #5».
 *
 * ⚠️ Ayer un renombrado así apuntó a una clave que no existía y el huésped leyó el marcador crudo
 * durante un día. Hoy lo cubre `app:pms:verificar-marcadores-guia`: si esto se equivoca, la
 * siguiente ejecución lo dice.
 *
 * ── Lo que NO se crea aquí ─────────────────────────────────────────────────
 * Las fotos y el vídeo de la caja del dinero: son contenido y los sube Jorge. La tabla nace con la
 * fila que ya existía —el vídeo de la caja de llaves— y nada más.
 */
final class Version20260913100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea pms_establecimiento_media y mueve ahí el vídeo de la caja de llaves.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE pms_establecimiento_media (
            id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\',
            establecimiento_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\',
            tipo VARCHAR(30) NOT NULL,
            image_name VARCHAR(255) DEFAULT NULL,
            image_updated_at DATETIME DEFAULT NULL,
            url VARCHAR(255) DEFAULT NULL,
            orden INT DEFAULT 0 NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            token VARCHAR(16) DEFAULT NULL,
            INDEX IDX_EST_MEDIA_ESTABLECIMIENTO (establecimiento_id),
            UNIQUE INDEX uniq_establecimiento_tipo (establecimiento_id, tipo),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE pms_establecimiento_media
            ADD CONSTRAINT FK_EST_MEDIA_ESTABLECIMIENTO
            FOREIGN KEY (establecimiento_id) REFERENCES pms_establecimiento (id) ON DELETE CASCADE');

        // El dato que ya había, a su sitio. UUID v7 no lo genera MySQL: basta con que sea único.
        // El `token` se rellena porque estas filas no pasan por una subida y `MediaTokenNamer` lo
        // necesita para nombrar un reemplazo futuro.
        $this->addSql("INSERT INTO pms_establecimiento_media
                (id, establecimiento_id, tipo, image_name, image_updated_at, url, orden, token, created_at, updated_at)
            SELECT UUID_TO_BIN(UUID()), e.id, 'video_caja_llaves', NULL, NULL, e.video_caja_fuerte_url, 0,
                   LEFT(REPLACE(UUID(), '-', ''), 16), NOW(), NULL
              FROM pms_establecimiento e
             WHERE COALESCE(e.video_caja_fuerte_url, '') <> ''");

        $this->addSql('ALTER TABLE pms_establecimiento DROP video_caja_fuerte_url');

        // El marcador de la guía, al nombre que ya distingue las dos cajas.
        $this->addSql(
            'UPDATE pms_guia_item SET descripcion = REPLACE(descripcion, :viejo, :nuevo) WHERE descripcion LIKE :patron',
            [
                'viejo' => '{{ video_caja_fuerte }}',
                'nuevo' => '{{ video_caja_llaves }}',
                'patron' => '%{{ video_caja_fuerte }}%',
            ]
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_establecimiento ADD video_caja_fuerte_url VARCHAR(255) DEFAULT NULL');

        $this->addSql("UPDATE pms_establecimiento e
                         JOIN pms_establecimiento_media m
                           ON m.establecimiento_id = e.id AND m.tipo = 'video_caja_llaves'
                          SET e.video_caja_fuerte_url = m.url");

        $this->addSql(
            'UPDATE pms_guia_item SET descripcion = REPLACE(descripcion, :nuevo, :viejo) WHERE descripcion LIKE :patron',
            [
                'viejo' => '{{ video_caja_fuerte }}',
                'nuevo' => '{{ video_caja_llaves }}',
                'patron' => '%{{ video_caja_llaves }}%',
            ]
        );

        $this->addSql('DROP TABLE pms_establecimiento_media');
    }
}
