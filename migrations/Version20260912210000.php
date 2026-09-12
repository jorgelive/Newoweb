<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * 🔥 Tres marcadores que no resolvía nadie y le llegaban crudos al huésped.
 *
 * Los encontró `app:pms:verificar-marcadores-guia` la primera vez que se ejecutó. Ninguno de los
 * tres es visible desde el código: el nombre de una clave es contenido.
 *
 * ── El primero: `{{ numero_llave }}` ───────────────────────────────────────
 * `Version20260911180000` renombró `{{ door_code }}` → `{{ numero_llave }}` en el ítem «Llaves
 * (general)», con buen motivo: ese número no es el código de ninguna puerta, es el grabado en la
 * llave. Pero la clave que `PmsGuiaContexto` carga —y la única que `PmsGuiaInterpolador` conoce—
 * se llama **`numero`**. El renombrado apuntó a una clave que no existe.
 *
 * El interpolador deja intactas las claves que no conoce, a propósito: son erratas del editor y
 * «tienen que verse en la revisión». Aquí no se vio. Y el front dejó de interpolar datos, así que
 * no había red debajo: **el huésped leía «Toma la Llave {{ numero_llave }} del interior»**, en los
 * siete idiomas, en el ítem que le explica cómo sacar su llave, el día de su llegada.
 *
 * ── Por qué `numero` y no al revés ─────────────────────────────────────────
 * Porque el número es UNO con tres usos —el de la llave, el de su puerta en el croquis, el de su
 * nombre— y {@see \App\Pms\Entity\PmsUnidad::$numero} ya dice por qué no se parte en tres: tres
 * columnas que deben coincidir son tres oportunidades de contradecirse. **La diferenciación va en
 * las palabras de cada consumidor**, y el ítem ya las pone: escribe «Toma la Llave» delante.
 *
 * Añadir `numero_llave` como alias sería la otra salida y es peor: dos nombres para el mismo dato,
 * que es de donde salen estos líos.
 *
 * ⚠️ Es una sustitución de texto sobre `descripcion`, que es JSON. Las barras no van escapadas en
 * el serializado de MySQL —comprobado antes de escribir esto—, así que el literal casa.
 */
final class Version20260912210000 extends AbstractMigration
{
    /**
     * Marcador roto → marcador bueno.
     *
     * 🇮🇹 **Los dos italianos no son una errata de nadie: los tradujo la máquina.** El
     * traductor automático trató `wifi_data` y `map` como palabras del texto y los pasó a
     * `dati_wifi` y `mappa`. Sólo en italiano, y sólo ahí: por eso nadie lo vio. Un huésped
     * italiano veía el marcador crudo donde iban su WiFi y su mapa.
     */
    private const ARREGLOS = [
        '{{ numero_llave }}' => '{{ numero }}',
        '{{ dati_wifi }}'    => '{{ wifi_data }}',
        '{{ mappa: '         => '{{ map: ',
    ];

    public function getDescription(): string
    {
        return 'Corrige tres marcadores que nadie resolvía: numero_llave, y dati_wifi/mappa del traductor.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ARREGLOS as $roto => $bueno) {
            $this->addSql(
                'UPDATE pms_guia_item SET descripcion = REPLACE(descripcion, :roto, :bueno) WHERE descripcion LIKE :patron',
                ['roto' => $roto, 'bueno' => $bueno, 'patron' => '%' . $roto . '%']
            );
        }
    }

    /**
     * ⚠️ **No deshace nada, y es deliberado.**
     *
     * La vuelta simétrica sería peor que no volver: `{{ map: }}` y `{{ wifi_data }}` son los
     * nombres BUENOS en los otros seis idiomas, así que un `REPLACE` a la inversa no repondría el
     * italiano roto — rompería los seis que están bien. Y el italiano roto tampoco es un estado al
     * que se quiera volver.
     *
     * Esto no cambia esquema: corrige datos que estaban mal. Deshacerlo es volver a enseñarle
     * marcadores crudos al huésped.
     */
    public function down(Schema $schema): void
    {
        $this->warnIf(true, 'Version20260912210000 no se deshace: revertirla volvería a romper la guía.');
    }
}
