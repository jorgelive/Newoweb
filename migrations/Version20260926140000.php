<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un vacío de Beds24 se guarda como NULL, venga por el pull o por el webhook.
 *
 * 🔥 El pull construía `Beds24BookingDto` con el serializer de Symfony y el webhook con
 * `fromArray()`, que normaliza «vacío = null». Mismo dato, dos formas en la base según quién tocó
 * la reserva por última vez — y cada alternancia pull↔webhook era un cambio que no era un cambio.
 * Medido el 26/09/2026: comentarios 92 `''`, hora de llegada 184, tarifa 99, referencia del canal
 * 99 (evento) y 129 (link), nota de la reserva 89. El código ya va por un solo camino
 * (`BookingsPullHandler` usa `fromArray()`); esto alinea lo que ya estaba guardado.
 *
 * Por SQL a propósito: son seis columnas de texto donde `''` y `NULL` significan lo mismo —«Beds24
 * no mandó nada»— y ningún listener deriva nada de la diferencia. Ver
 * `docs/PmsBeds24ReservasSync.md` §12.20.
 */
final class Version20260926140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normaliza a NULL los vacíos que dejaba el pull de Beds24 (comentarios, hora de llegada, tarifa, referencia, nota).';
    }

    public function up(Schema $schema): void
    {
        foreach (['comentarios_huesped', 'hora_llegada_canal', 'rate_description', 'referencia_canal'] as $columna) {
            $this->addSql("UPDATE pms_evento_calendario SET $columna = NULL WHERE $columna = ''");
        }
        $this->addSql("UPDATE pms_evento_beds24_link SET referencia_canal = NULL WHERE referencia_canal = ''");
        $this->addSql("UPDATE pms_reserva SET nota = NULL WHERE nota = ''");
    }

    /**
     * Sin vuelta: no se sabe qué filas eran `''` antes, y `''` era justo la mitad del fallo.
     */
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Los vacíos no se pueden reconstruir, y reconstruirlos sería volver al fallo.');
    }
}
