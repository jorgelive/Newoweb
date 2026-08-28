<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `enlace_pago_id` en los pagos del PMS: un cobro por pasarela deja de poder borrarse.
 *
 * ### Qué problema resuelve
 *
 * Un pago generado por un enlace de pasarela era borrable como cualquier otro, y borrarlo
 * dejaba dos afirmaciones contradictorias vivas a la vez: el `fin_enlace_pago` seguía en
 * `pagado`, con su fecha y su código de autorización, mientras la reserva volvía a deber un
 * dinero que el huésped sí había pagado. Y rompía la trazabilidad en las dos direcciones:
 * `fin_enlace_pago.movimiento_generado_id` quedaba apuntando a una fila inexistente, y con
 * ella desaparecía la etiqueta «Enlace · pasarela» que es lo único que explica por qué el
 * enlace y el pago no valen lo mismo (el enlace cobra el total, el pago abona el neto).
 *
 * La regla vive en `PmsPagoFinanciero::getMotivoNoBorrable()` —fuente única: el listener de
 * coherencia la usa para vetar y la SPA para pintar un candado en vez de un basurero— y para
 * poder aplicarla hacía falta que el pago SUPIERA de dónde vino.
 *
 * ### Por qué una columna y no cruzarlo con Finanzas al vuelo
 *
 * La relación ya existía y se resolvía **en el frontend**, cruzando
 * `enlace.movimientoGeneradoId` con el id del pago: allí las dos listas están cargadas y
 * bastaba para pintar una etiqueta. `docs/FinanzasEnlacesPago.md` lo dejó dicho — «si algún
 * día hace falta esa marca FUERA del panel, habrá que persistirla».
 *
 * Ese día es éste: el veto es una regla de negocio y corre en el backend, donde no hay
 * ninguna lista de enlaces a mano. Preguntárselo a Finanzas por repositorio metería una
 * consulta dentro de un getter de entidad y partiría la fuente única en dos.
 *
 * ### Sin foreign key, a propósito
 *
 * Misma decisión que `fin_enlace_pago.origen_id` en la dirección contraria (§2 del doc):
 * Finanzas es transversal y las dos tablas se referencian en soft. Una FK aquí ataría el PMS
 * a Finanzas justo en el sentido que el módulo evita.
 *
 * ### El relleno
 *
 * Se rellena desde `fin_enlace_pago.movimiento_generado_id`, que es la misma relación vista
 * del otro lado. Va por SQL y no por comando porque **ningún listener recalcula esta
 * columna**: no toca importes, no dispara coherencia financiera y no se traduce.
 *
 * En producción, a 28/08/2026, no rellena nada: el único enlace `pagado` es un cobro manual
 * de prueba y los manuales no generan movimiento. Se escribe igual porque el día que haya
 * cobros reales anteriores a este despliegue, sin relleno quedarían borrables.
 */
final class Version20260828110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade enlace_pago_id a pms_pago_financiero y lo rellena desde fin_enlace_pago.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_pago_financiero ADD enlace_pago_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');

        // Índice: lo consulta el relleno de arriba y cualquier «¿de qué enlace vino este
        // pago?» futuro. Barato en una tabla de pagos, que crece despacio.
        $this->addSql('CREATE INDEX idx_pms_pago_enlace ON pms_pago_financiero (enlace_pago_id)');

        $this->addSql(<<<'SQL'
            UPDATE pms_pago_financiero p
            INNER JOIN fin_enlace_pago e ON e.movimiento_generado_id = p.id
            SET p.enlace_pago_id = e.id
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_pms_pago_enlace ON pms_pago_financiero');
        $this->addSql('ALTER TABLE pms_pago_financiero DROP enlace_pago_id');
    }
}
