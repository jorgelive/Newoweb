<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Devuelve a hora de pared los entrantes de Beds24 que quedaron guardados en UTC.
 *
 * 🔥 **El mismo fallo, dos veces, con seis meses de diferencia.** El `time` de un mensaje de
 * Beds24 llega en UTC y lo dice con una `Z` (1.890 de 1.890 webhooks de la auditoría). Cuando el
 * persistidor no normaliza el huso antes de guardar, Doctrine escribe los dígitos de UTC y el
 * mensaje queda **cinco horas en el futuro**: en el chat, la pregunta del huésped se pinta por
 * encima de la respuesta que la contesta.
 *
 * | cuándo | qué pasó | filas |
 * |---|---|---|
 * | 12–15/03/2026 | aún no existía la conversión | 17 |
 * | 15/03/2026 | se añadió `setTimezone()` — se acabó el problema | — |
 * | 08/09/2026 | se quitó por «no-op con nombre engañoso» (§12.16) | — |
 * | 09–10/09/2026 | vuelve a pasar | 4 |
 *
 * ⚠️ **Las filas se eligen por el reloj real de inserción, no por fecha.** El identificador es un
 * UUID v7: sus primeros 48 bits son los milisegundos de época en que se insertó la fila, así que
 * `created_at` se puede comparar contra el instante en que se guardó de verdad. Una fila sana
 * difiere en segundos —la latencia del webhook—; una torcida, en cinco horas. Filtrar por rango
 * de fechas habría arrastrado mensajes viejos legítimos de huéspedes que escriben de madrugada.
 *
 * ⚠️ **Y el huso de sesión se fija a mano.** `UNIX_TIMESTAMP()` interpreta la columna en el huso
 * de la sesión, que no es el mismo en todas las conexiones: sin fijarlo, la misma migración
 * corregiría un número distinto de filas según quién la corra.
 */
final class Version20260910120000 extends AbstractMigration
{
    /** Margen para separar la latencia real del webhook (segundos) del desfase de cinco horas. */
    private const int UMBRAL_SEGUNDOS = 3600;

    public function getDescription(): string
    {
        return 'Corrige los entrantes de Beds24 guardados con la hora en UTC (21 filas).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET time_zone = '-05:00'");

        $this->addSql(sprintf(
            "UPDATE msg_message
                SET created_at = CONVERT_TZ(created_at, '+00:00', '-05:00')
              WHERE direction = 'incoming'
                AND channel_id = 'beds24'
                AND UNIX_TIMESTAMP(created_at) - (CONV(SUBSTR(HEX(id), 1, 12), 16, 10) / 1000) > %d",
            self::UMBRAL_SEGUNDOS
        ));
    }

    public function down(Schema $schema): void
    {
        // No hay vuelta atrás fiable: tras corregirlas, esas filas son indistinguibles de las que
        // siempre estuvieron bien. Deshacerlo desplazaría también a las sanas.
        $this->throwIrreversibleMigrationException(
            'Los entrantes corregidos ya no se distinguen de los sanos: revertir movería a los dos.'
        );
    }
}
