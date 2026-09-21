<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Materializa «cuándo ocurrió» un mensaje, que es por donde se ordena un hilo.
 *
 * ── Qué arregla ─────────────────────────────────────────────────────────────
 * El chat ordenaba con DOS claves distintas: la API paginaba por `created_at` y el front pintaba
 * por la fecha efectiva. Para un mensaje inmediato coinciden; para uno programado no, y ahí el
 * orden cambiaba según por dónde hubiera llegado el mensaje —por Mercure entraba en su sitio
 * efectivo, por pull aparecía donde tocara por creación—. Una guía creada el 10/07 y enviada el
 * 08/08 paginaba como de julio y se pintaba en agosto, así que al hacer scroll la lista se
 * recolocaba delante del operador.
 *
 * Y la fórmula estaba repetida en trece sitios: el getter de la entidad, el `sort` del front y
 * **once consultas SQL** —resúmenes, menú de entrada, recalentado de hilos—. El 21/09/2026 el
 * getter pasó a ser un máximo y las once se quedaron con el `COALESCE` viejo. Una columna deja
 * una sola fuente.
 *
 * ── El relleno ──────────────────────────────────────────────────────────────
 * `GREATEST(created_at, COALESCE(scheduled_at, created_at))`, que es exactamente lo que calcula
 * `Message::calcularCuandoOcurrio()`:
 *
 * - inmediato (`scheduled_at` nulo) → su creación;
 * - programado a futuro → la fecha programada, que es cuando saldrá o salió;
 * - programado a una hora YA PASADA —el motor lo crea con la hora a la que la regla debía
 *   dispararse y el cron llegó tarde— → su creación, que es cuando salió de verdad. Son 210
 *   filas, con saltos de hasta 77 minutos.
 *
 * ⚠️ Por SQL y no por ORM a propósito: son ~5.500 filas y el valor es **derivado**, no dispara
 * ningún listener. La regla de la casa —lo que dispara listeners entra por el ORM— aquí no
 * aplica, porque nada escucha a esta columna: la escriben `sellarCuandoOcurrio()` al insertar y
 * `setScheduledAt()` al reprogramar, y ninguna de las dos reacciona a un `UPDATE` ajeno.
 *
 * ── El índice ───────────────────────────────────────────────────────────────
 * `(conversation_id, ocurrio_at)`. Abrir un chat es la consulta más caliente del panel y ahora
 * ordena por esta columna; sin índice sería un `filesort` del hilo entero en cada apertura.
 * `GREATEST(...)` como expresión nunca habría podido usarlo, que es la otra mitad de por qué
 * esto es una columna y no una fórmula.
 *
 * La columna se deja NULLABLE: el día que una fila entre por un camino que no sea el ORM, es
 * preferible un nulo visible —el getter cae al cálculo— que un `NOT NULL` que reviente el INSERT.
 */
final class Version20260921090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade msg_message.ocurrio_at (fecha efectiva materializada) con su índice de hilo.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE msg_message ADD ocurrio_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");

        $this->addSql('UPDATE msg_message SET ocurrio_at = GREATEST(created_at, COALESCE(scheduled_at, created_at))');

        $this->addSql('CREATE INDEX idx_msg_hilo_ocurrio ON msg_message (conversation_id, ocurrio_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_msg_hilo_ocurrio ON msg_message');
        $this->addSql('ALTER TABLE msg_message DROP ocurrio_at');
    }
}
