<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `operacion_pago.medio_pago`: por qué medio se le pagó al proveedor.
 *
 * ── Nace NOT NULL, y eso hubo que comprobarlo ───────────────────────────────
 * Un campo así se añade normalmente nullable, para no reprobar a las filas que ya están: los
 * pagos viejos no dicen cómo se pagaron, y rellenarlos con el medio más común —«transferencia»—
 * sería inventar un hecho sobre dinero que ya salió.
 *
 * Aquí no hace falta: **`operacion_pago` está vacía** —3 órdenes, 0 pagos, comprobado en
 * producción el 21/08/2026 antes de escribir esto—. Sin filas que respetar, el `NULL` sólo
 * habría servido para arrastrar por toda la aplicación una rama de «medio no consta» que nunca
 * se daría: en la entidad, en el panel y en la documentación.
 *
 * ⚠️ Si esta migración llega a una base donde **sí** hay pagos, `NOT NULL` sin `DEFAULT` falla
 * al aplicarla. Es lo que se quiere: mejor que reviente el despliegue a que rellene en silencio
 * un dato de caja que nadie ha dicho.
 *
 * ⚠️ **Sin `enum` de MySQL, `VARCHAR(30)`.** Es el mismo criterio que el resto del repo
 * (`estado_os`, `medio_pago` del PMS): añadir un caso al enum de PHP no puede exigir un `ALTER
 * TABLE` en producción, que es exactamente el paso que se olvida.
 */
final class Version20260821460000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'operacion_pago: medio de pago del abono al proveedor.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_pago ADD medio_pago VARCHAR(30) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operacion_pago DROP medio_pago');
    }
}
