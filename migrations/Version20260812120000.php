<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El widget de medios de pago aparece en los siete idiomas, no sólo en español.
 *
 * El ítem «Pago (general)» —compartido por las siete casitas— lleva `{{ medios_pago }}`, el
 * marcador que el front sustituye por las cuentas de cobro reales (Yape, Plin, bancos). Estaba
 * **sólo en el bloque español**: un huésped que lea su guía en inglés, portugués, francés,
 * alemán, italiano o neerlandés veía el apartado de pago sin ninguna forma de pagar.
 *
 * No es un fallo de código —el interpolador hace su trabajo— sino de contenido: el marcador se
 * escribió a mano en un idioma y las traducciones automáticas no lo arrastraron.
 *
 * ── Dónde se inserta, y por qué así ─────────────────────────────────────────
 * En español el marcador va entre el párrafo de las OTA («🌐 Huéspedes de Airbnb…», que no pagan
 * aquí) y el de las reservas directas («🤝 Reservas directas, Booking.com…», que sí). Ese es su
 * sitio: justo antes de a quién le hace falta.
 *
 * El ancla es el **emoji 🤝**, no una palabra traducida: está presente en los siete bloques
 * porque los emojis no se traducen. Anclar en «Reservas directas» habría funcionado en español
 * y fallado en los otros seis, que es exactamente el error que esta migración viene a corregir.
 *
 * ── Por qué en PHP y no en SQL ──────────────────────────────────────────────
 * `descripcion` es un JSON con un bloque por idioma y HTML dentro. Un `REPLACE()` de SQL sobre
 * ese texto es cirugía a ciegas: basta una comilla escapada distinta para romper el JSON entero
 * y dejar la guía en blanco. Decodificar, tocar y recodificar en PHP falla ruidosamente si algo
 * no encaja.
 *
 * ── Reejecutable ────────────────────────────────────────────────────────────
 * Cada bloque que YA tenga el marcador se salta. Correr esto dos veces no duplica el widget.
 *
 * ⚠️ Ojo al editar ese ítem después: si alguien reescribe el texto español y deja que se
 * retraduzca (`sobreescribir_traduccion` está hoy en 0), las traducciones se regeneran y el
 * marcador vuelve a perderse en los seis idiomas. La solución de fondo sería que el traductor
 * respete los `{{ … }}`; mientras tanto, esto lo deja como debe estar.
 */
final class Version20260812120000 extends AbstractMigration
{
    private const string ITEM = '019c2ffd-190f-7d17-86f5-79ad68073d9d';

    private const string MARCADOR = '{{ medios_pago }}';

    /** El párrafo ante el cual va el widget. Emoji porque no se traduce. */
    private const string ANCLA = '🤝';

    public function getDescription(): string
    {
        return '{{ medios_pago }} en los 7 idiomas del ítem «Pago (general)», no sólo en español';
    }

    public function up(Schema $schema): void
    {
        $this->migrarMarcador(insertar: true);
    }

    public function down(Schema $schema): void
    {
        $this->migrarMarcador(insertar: false);
    }

    /**
     * Añade o quita el marcador en todos los idiomas que no sean el español.
     *
     * El español no se toca en ninguna dirección: es el original, ya lo tiene, y quitárselo al
     * revertir dejaría la guía peor de como estaba.
     */
    private function migrarMarcador(bool $insertar): void
    {
        $json = $this->connection->fetchOne(
            'SELECT descripcion FROM pms_guia_item WHERE id = UUID_TO_BIN(:id)',
            ['id' => self::ITEM]
        );

        if (!is_string($json) || $json === '') {
            $this->write(sprintf('  ⚠️ El ítem %s no existe o no tiene descripción; no se toca nada.', self::ITEM));

            return;
        }

        /** @var list<array{language: string, content: string}> $bloques */
        $bloques = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $tocados = 0;

        foreach ($bloques as $i => $bloque) {
            if (($bloque['language'] ?? '') === 'es') {
                continue;
            }

            $contenido = (string) ($bloque['content'] ?? '');
            $tiene = str_contains($contenido, self::MARCADOR);

            if ($insertar === $tiene) {
                continue; // ya está como queremos
            }

            $nuevo = $insertar
                ? $this->insertar($contenido, $bloque['language'] ?? '?')
                : str_replace('<p>' . self::MARCADOR . '</p>', '', $contenido);

            if ($nuevo === $contenido) {
                continue;
            }

            $bloques[$i]['content'] = $nuevo;
            ++$tocados;
        }

        if ($tocados === 0) {
            $this->write('  Nada que cambiar: los idiomas ya están como deben.');

            return;
        }

        $this->connection->executeStatement(
            'UPDATE pms_guia_item SET descripcion = :json WHERE id = UUID_TO_BIN(:id)',
            [
                'json' => json_encode($bloques, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'id' => self::ITEM,
            ]
        );

        $this->write(sprintf('  %s el marcador en %d idioma(s).', $insertar ? 'Añadido' : 'Quitado', $tocados));
    }

    /**
     * Mete `<p>{{ medios_pago }}</p>` justo antes del párrafo que contiene el ancla.
     *
     * Si el ancla no aparece —alguien reescribió el ítem y quitó el emoji— se deja el bloque
     * intacto y se avisa por consola, en vez de colocarlo en un sitio inventado. Un widget de
     * cuentas bancarias en mitad del párrafo equivocado es peor que no tenerlo.
     */
    private function insertar(string $contenido, string $idioma): string
    {
        $posAncla = mb_strpos($contenido, self::ANCLA);

        if ($posAncla === false) {
            $this->write(sprintf('  ⚠️ [%s] sin el ancla «%s»: se deja como estaba.', $idioma, self::ANCLA));

            return $contenido;
        }

        // El `<p>` que abre el párrafo del ancla: el último que hay antes de ella.
        $posParrafo = mb_strrpos(mb_substr($contenido, 0, $posAncla), '<p>');

        if ($posParrafo === false) {
            $this->write(sprintf('  ⚠️ [%s] el ancla no está dentro de un <p>: se deja como estaba.', $idioma));

            return $contenido;
        }

        return mb_substr($contenido, 0, $posParrafo)
            . '<p>' . self::MARCADOR . '</p>'
            . mb_substr($contenido, $posParrafo);
    }
}
