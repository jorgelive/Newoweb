<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Revisión de códigos y nombres internos de los segmentos (Jorge, 23/10/2026: grupos 1 y 2).
 *
 * 1. **Errores**: una errata (`SAGADO`), una ciudad mal abreviada (`CUS`), tres códigos en
 *    minúsculas —los únicos del catálogo— que pasan a mayúsculas SIN prefijo de contenedor, como
 *    pide `docs/Travel.md` («Slugs SIN el código del contenedor»), y el retorno de la Ruta Amazónica, que no
 *    estaba en ningún servicio y por eso no se podía elegir al cotizar. Va a los mismos que su ida.
 * 2. **Nombres internos repetidos**: dos o tres segmentos distintos con el mismo nombre no se
 *    distinguen en el buscador. Cada uno dice ahora lo que lo separa de su hermano.
 *
 * Por SQL porque `slug` y `nombre_interno` no se traducen ni los escucha nadie, y los pools son
 * tablas de unión. Las cotizaciones enlazan el segmento por id: no cambian.
 */
final class Version20261023210000 extends AbstractMigration
{
    /** @var array<string, string> código antes → código ahora */
    private const array CODIGOS = [
        'SAL_EXC-VALLE_SAGADO-POOL' => 'SAL_EXC-VALLE_SAGRADO-POOL',
        'RET_EXC-CENTRO-CUS' => 'RET_EXC-CENTRO-CUZ',
        'album-fotografico' => 'ALBUM_FOTOS',
        'kit-de-viaje' => 'KIT_VIAJE',
        'seguro-de-viaje' => 'SEGURO_VIAJE',
    ];

    /** @var array<string, array{string, string}> código (ya nuevo) → [nombre antes, nombre ahora] */
    private const array NOMBRES = [
        'CNX_TREN_OLL_DIRECTO' => ['Conexión a la estación de Ollantaytambo', 'Conexión a la estación de Ollantaytambo (directa)'],
        'CNX_TREN_OLL_ESPERA' => ['Conexión a la estación de Ollantaytambo', 'Conexión a la estación de Ollantaytambo (con espera)'],
        'RET-VERT_SKY-HASTA_CUZ' => ['Traslado de Salida desde Vertical Sky Suites', 'Traslado de salida desde Vertical Sky Suites a Cusco'],
        'RET-VERT_SKY-HASTA_OLL' => ['Traslado de Salida desde Vertical Sky Suites', 'Traslado de salida desde Vertical Sky Suites a Ollantaytambo'],
        'RET-VERT_SKY-HASTA_VALLE' => ['Traslado de Salida desde Vertical Sky Suites', 'Traslado de salida desde Vertical Sky Suites al Valle Sagrado'],
        'SAL-VERT_SKY-DESDE_CUZ' => ['Traslado y Arribo de Aventura a Vertical Sky', 'Traslado y arribo a Vertical Sky desde Cusco'],
        'SAL-VERT_SKY-DESDE_OLL' => ['Traslado y Arribo de Aventura a Vertical Sky', 'Traslado y arribo a Vertical Sky desde Ollantaytambo'],
        'SAL-VERT_SKY-DESDE_VALLE' => ['Traslado y Arribo de Aventura a Vertical Sky', 'Traslado y arribo a Vertical Sky desde el Valle Sagrado'],
        'VIS-PARACAS_ICA-HUACACHINA-ADRENALINA' => ['Oasis de Huacachina: Buggies y Sandboard', 'Oasis de Huacachina: Buggies y Sandboard (adrenalina)'],
        'VIS-PARACAS_ICA-HUACACHINA-SUNSET-2H' => ['Oasis de Huacachina: Buggies y Sandboard', 'Oasis de Huacachina: Buggies y Sandboard (atardecer, 2 h)'],
        'SAL_EXC-CITY_CUZ-POOL' => ['Recojo e inicio de excursión (Servicio Grupal)', 'City Tour Cusco · Recojo e inicio (servicio grupal)'],
        'SAL_EXC-MARAS_MORAY-POOL' => ['Recojo e inicio de excursión (Servicio Grupal)', 'Maras y Moray · Recojo e inicio (servicio grupal)'],
        'SAL_EXC-VALLE_SAGRADO-POOL' => ['Recojo e inicio de excursión (Servicio Grupal)', 'Valle Sagrado · Recojo e inicio (servicio grupal)'],
        'SAL_EXC-MARAS_MORAY-PRIV' => ['Recojo en el Hotel (Servicio Privado)', 'Maras y Moray · Recojo en el hotel (servicio privado)'],
        'SAL_EXC-VALLE_SAGRADO-PRIV' => ['Recojo en el Hotel (Servicio Privado)', 'Valle Sagrado · Recojo en el hotel (servicio privado)'],
    ];

    private const string RETORNO_AMAZONICO = 'TRANS_BYCAR_RET-MAPI-HIDRO';
    private const string IDA_AMAZONICA = 'TRANS_BYCAR_SAL-MAPI-HIDRO';

    public function getDescription(): string
    {
        return 'Segmentos: códigos con errata o sin patrón, nombres internos repetidos y el retorno amazónico sin servicio';
    }

    public function up(Schema $schema): void
    {
        foreach (self::CODIGOS as $antes => $ahora) {
            $this->addSql('UPDATE travel_segmento SET slug = ? WHERE slug = ?', [$ahora, $antes]);
        }
        foreach (self::NOMBRES as $slug => [$antes, $ahora]) {
            $this->addSql('UPDATE travel_segmento SET nombre_interno = ? WHERE slug = ? AND nombre_interno = ?', [$ahora, $slug, $antes]);
        }
        // El retorno, a los mismos servicios que su ida.
        $this->addSql(
            'INSERT IGNORE INTO travel_segmento_servicio_pool (travel_segmento_id, travel_servicio_id)
             SELECT r.id, p.travel_servicio_id FROM travel_segmento r
               JOIN travel_segmento i ON i.slug = ?
               JOIN travel_segmento_servicio_pool p ON p.travel_segmento_id = i.id
              WHERE r.slug = ?',
            [self::IDA_AMAZONICA, self::RETORNO_AMAZONICO]
        );
    }

    public function down(Schema $schema): void
    {
        foreach (self::NOMBRES as $slug => [$antes, $ahora]) {
            $this->addSql('UPDATE travel_segmento SET nombre_interno = ? WHERE slug = ? AND nombre_interno = ?', [$antes, $slug, $ahora]);
        }
        foreach (self::CODIGOS as $antes => $ahora) {
            $this->addSql('UPDATE travel_segmento SET slug = ? WHERE slug = ?', [$antes, $ahora]);
        }
        $this->addSql(
            'DELETE p FROM travel_segmento_servicio_pool p JOIN travel_segmento r ON r.id = p.travel_segmento_id WHERE r.slug = ?',
            [self::RETORNO_AMAZONICO]
        );
    }
}
