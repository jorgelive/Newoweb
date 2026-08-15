<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `intervenido` en los pagos: la válvula del depósito automático de las OTA (§12.4.5).
 *
 * ### Por qué una columna nueva y no reutilizar `es_automatico`
 *
 * Porque ya se intentó y salió mal. `es_automatico` es la IDENTIDAD del depósito («éste es el
 * del canal»), y es como lo encuentra `PmsPagoOtaAutomaticoService` para no crear un segundo.
 * El primer diseño lo apagaba al editarlo: el sincronizador dejaba de reconocerlo, creaba otro
 * y la reserva acababa con dos depósitos y el saldo en −100. Un booleano no puede significar a
 * la vez «es el depósito del canal» y «lo gestiona el sistema».
 *
 * `intervenido` es la segunda mitad: el depósito sigue siendo del canal, pero el importe lo
 * manda el operador y el sincronizador no lo toca. Se abre a propósito desde el candado del
 * panel financiero y se devuelve al automático poniéndola de nuevo en 0.
 *
 * ### Backfill
 *
 * Ninguno, y es lo correcto: hasta hoy el depósito era de sólo lectura, así que **no existe
 * ningún pago intervenido**. Todos arrancan en 0 = gobernados por el sistema, que es
 * exactamente el comportamiento actual.
 *
 * Va por SQL directo sin reparos: es esquema puro, y el valor por defecto no dispara ningún
 * listener ni recalcula totales.
 */
final class Version20260814200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade pms_pago_financiero.intervenido: permite fijar a mano el depósito automático del canal.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE pms_pago_financiero
            ADD intervenido TINYINT(1) DEFAULT 0 NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Al revertir, los depósitos que estaban intervenidos vuelven a quedar bajo el
        // sincronizador y el siguiente recálculo de su cabecera los cuadra contra los cargos
        // del canal. Se pierde el ajuste manual, que es lo único que esta columna guardaba.
        $this->addSql(<<<'SQL'
            ALTER TABLE pms_pago_financiero
            DROP intervenido
        SQL);
    }
}
