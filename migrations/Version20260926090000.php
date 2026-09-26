<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `ocurrio_at` pasa a NOT NULL, y con eso se va el último `COALESCE` del código.
 *
 * ── Por qué se revierte la decisión de hace cinco días ──────────────────────
 * La columna nació nullable a propósito —«el día que una fila entre por un camino que no sea el
 * ORM, mejor un nulo visible que un INSERT que revienta»— y el precio de esa red fue que las
 * ONCE consultas se quedaron con `COALESCE(ocurrio_at, created_at)`. O sea: la fórmula duplicada
 * que esta columna venía a matar siguió viva, sólo más corta. Una red que obliga a repetir en
 * once sitios lo que la columna ya sabe no es una red, es la misma deuda con otro nombre.
 *
 * Y el camino que había que temer no existe: en `src/` no hay ni un `INSERT INTO msg_message`.
 * Los dos `UPDATE` crudos que hay tocan `metadata` y `status`, nunca las fechas. Si algún día
 * alguien escribe una fila a mano, que el INSERT reviente es exactamente lo que se quiere:
 * un mensaje sin fecha efectiva no se puede ni ordenar ni paginar.
 *
 * ── Y no es sólo estética: una función sobre la columna no usa índice ───────
 * Medido el 26/09/2026 en producción, sobre el hilo más gordo (665 mensajes), con la consulta
 * del acuse de recibo:
 *
 * | Filtro y orden | Plan |
 * |---|---|
 * | `COALESCE(ocurrio_at, created_at)` | `index_merge` de 332 filas **+ filesort** |
 * | `ocurrio_at` a secas | `idx_msg_hilo_ocurrio`, 31 filas, `backward index scan`, sin sort |
 *
 * ⚠️ **El listado del chat, en cambio, NUNCA tuvo ese filesort**, aunque la revisión que motivó
 * este cambio dijera que sí y hubiera que añadir un tercer tramo `id` al índice. No hace falta:
 * InnoDB **extiende** todo índice secundario con la clave primaria, así que
 * `idx_msg_hilo_ocurrio` ya es `(conversation_id, ocurrio_at, id)` para el optimizador y
 * `ORDER BY ocurrio_at DESC, id DESC` sale del índice. Comprobado con EXPLAIN antes de tocar
 * nada; el índice se queda como está.
 *
 * ── El relleno ──────────────────────────────────────────────────────────────
 * Cero filas nulas en producción (8.323 mensajes, medidas el 26/09/2026): la migración que
 * estrenó la columna las rellenó todas y desde entonces las sella `sellarCuandoOcurrio()`. El
 * `UPDATE` de abajo va igual, acotado a las nulas, porque esta migración corre también en bases
 * que no son producción y un `ALTER` a NOT NULL sobre un nulo convierte la fecha en `0000-00-00`
 * en vez de fallar.
 */
final class Version20260926090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'msg_message.ocurrio_at pasa a NOT NULL: la fecha efectiva ya no admite nulos.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE msg_message SET ocurrio_at = GREATEST(created_at, COALESCE(scheduled_at, created_at)) WHERE ocurrio_at IS NULL');

        $this->addSql("ALTER TABLE msg_message MODIFY ocurrio_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE msg_message MODIFY ocurrio_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }
}
