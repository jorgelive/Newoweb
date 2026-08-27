<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Renombra la clave `nombreSnapshot` → `tituloSnapshot` DENTRO del JSON de `snapshot_items`.
 *
 * Era el último sitio del proyecto donde sobrevivía el nombre viejo. No es una columna: es una
 * clave dentro de un `json`, así que no basta un `ALTER TABLE` — hay que reescribir el contenido.
 *
 * ⚠️ **Va aquí y no en un comando aparte** porque el código que lo lee se despliega en el mismo
 * empujón. Si el dato se migrara después, entre un paso y otro los 184 ítems se quedarían **sin
 * nombre en la pantalla**: el front buscaría `tituloSnapshot` y el JSON seguiría diciendo
 * `nombreSnapshot`. Y no daría error — daría un hueco, que es peor de detectar.
 *
 * ⚠️ **Se reescribe con PHP y no con `JSON_REPLACE`** a propósito. La transformación es «renombra
 * una clave conservando su posición y el resto del objeto intacto», y en SQL eso son tres
 * funciones anidadas por elemento del array que nadie va a saber releer. Aquí es un `foreach`.
 *
 * No traduce nada: el contenido ya viene con sus siete idiomas y sólo cambia el nombre de la
 * llave, así que saltarse `AutoTranslate` —que un UPDATE se salta— es correcto y no deja deuda.
 *
 * Idempotente: sólo toca las filas que todavía tienen la clave vieja.
 */
final class Version20260827180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'snapshot_items[].nombreSnapshot → tituloSnapshot (contenido JSON, no esquema).';
    }

    public function up(Schema $schema): void
    {
        $this->renombrarClave('nombreSnapshot', 'tituloSnapshot');
    }

    public function down(Schema $schema): void
    {
        $this->renombrarClave('tituloSnapshot', 'nombreSnapshot');
    }

    private function renombrarClave(string $de, string $a): void
    {
        // `warnIf` no vale: esto tiene que ejecutarse, no avisar.
        $filas = $this->connection->fetchAllAssociative(
            'SELECT id, snapshot_items FROM cotizacion_cotcomponente WHERE JSON_LENGTH(snapshot_items) > 0'
        );

        $tocadas = 0;
        $items   = 0;

        foreach ($filas as $fila) {
            $lista = json_decode((string) $fila['snapshot_items'], true);

            if (!\is_array($lista)) {
                continue;
            }

            $cambiada = false;
            $nueva    = [];

            foreach ($lista as $item) {
                if (!\is_array($item) || !\array_key_exists($de, $item)) {
                    $nueva[] = $item;
                    continue;
                }

                // Se reconstruye en orden para que la clave nueva quede donde estaba la vieja:
                // un diff de estos JSON es lo único que tiene alguien para revisarlos a mano.
                $reordenado = [];

                foreach ($item as $clave => $valor) {
                    $reordenado[$clave === $de ? $a : $clave] = $valor;
                }

                $nueva[]  = $reordenado;
                $cambiada = true;
                ++$items;
            }

            if (!$cambiada) {
                continue;
            }

            $this->connection->executeStatement(
                'UPDATE cotizacion_cotcomponente SET snapshot_items = ? WHERE id = ?',
                [json_encode($nueva, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $fila['id']]
            );

            ++$tocadas;
        }

        $this->write(sprintf('    <info>%d componentes · %d ítems renombrados a «%s»</info>', $tocadas, $items, $a));
    }
}
