<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Punta Cana 2026: JetSMART adelantó el JA7013 del retorno Lima → Cusco.
 *
 * Contrastado el archivo de horarios del 29/08/2026 contra los 16 segmentos que hay en la
 * base: **quince coinciden y sólo éste cambió**.
 *
 *   antes   JA7013 · LIM 23/09 06:30 → CUZ 23/09 07:55
 *   ahora   JA7013 · LIM 23/09 06:10 → CUZ 23/09 07:30
 *
 * Toca 5 grupos del expediente 5SRAJV: los cuatro que llegan en el JA7018 y el que viaja
 * anticipado el 11/09 en el JA7032.
 *
 * ⚠️ **Se actualizan los cinco, aunque la aerolínea sólo haya confirmado por correo el
 * localizador RBEJRT.** El avión despega a las 06:10 para todos; la confirmación pendiente es
 * administrativa. Y el error no es simétrico: dejar 06:30 en cuatro grupos es mandarlos al
 * aeropuerto **veinte minutos tarde** para un vuelo que ya salió. Los otros cuatro
 * localizadores —Y9KZ7J, X9SYVZ, LENV5U, ZF88GX— quedan pendientes de confirmación escrita.
 *
 * ── Por qué SÍ va por migración ─────────────────────────────────────────────
 *
 * `cotizacion_file_grupo.detalle` es un `text` plano: ni `#[AutoTranslate]` ni listeners que
 * recalculen nada al guardar. Es el caso que CLAUDE.md manda por migración.
 *
 * ⚠️ NO confundir con `cotizacion_cotcomponente.detalles_operativos`, que guarda un texto
 * parecido —«Latam LA-2356 Despega 12:10»— pero **sí lleva `#[AutoTranslate]` sobre
 * `detalle`**. Ese hay que tocarlo por comando: un UPDATE en SQL dejaría el español con la
 * hora nueva y los otros seis idiomas con la vieja.
 *
 * Es un REPLACE acotado por localizador y por la cadena completa, así que es idempotente:
 * repetirlo no encuentra nada.
 */
final class Version20260828180000 extends AbstractMigration
{
    private const ANTES = 'JA7013 · LIM 23/09 06:30 → CUZ 23/09 07:55';
    private const AHORA = 'JA7013 · LIM 23/09 06:10 → CUZ 23/09 07:30';

    public function getDescription(): string
    {
        return 'Punta Cana 2026: JA7013 pasa de 06:30→07:55 a 06:10→07:30 (JetSMART, 28/08/2026).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql($this->reemplazo(self::ANTES, self::AHORA));
    }

    /** Reversible: el horario anterior es un dato real, no un error. */
    public function down(Schema $schema): void
    {
        $this->addSql($this->reemplazo(self::AHORA, self::ANTES));
    }

    private function reemplazo(string $de, string $a): string
    {
        return sprintf(
            'UPDATE cotizacion_file_grupo g
               INNER JOIN cotizacion_file f ON f.id = g.file_id
                SET g.detalle = REPLACE(g.detalle, %s, %s)
              WHERE f.localizador = %s AND g.detalle LIKE %s',
            $this->connection->quote($de),
            $this->connection->quote($a),
            $this->connection->quote('5SRAJV'),
            $this->connection->quote('%' . $de . '%'),
        );
    }
}
