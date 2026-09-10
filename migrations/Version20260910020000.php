<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Borra las notas fósiles «el escaneo está girado N°».
 *
 * 🔥 Las escribió la versión que ponía el giro en el VEREDICTO. Al sacarlo de ahí —el giro es una
 * propiedad del archivo, no del número— dejaron de escribirse, pero las 58 ya guardadas **no se
 * van solas**: la tanda salta lo `validado_*`, así que un documento ya enderezado sigue diciendo
 * que está torcido. Y eso no es sólo ruido: manda a girar otra vez uno que ya está bien, y cada
 * giro cuesta un 14 % de calidad.
 *
 * Va en SQL y no por comando porque no dispara ningún listener: es limpiar texto muerto.
 */
final class Version20260910020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quita las notas de giro que quedaron en los veredictos.';
    }

    public function up(Schema $schema): void
    {
        // JSON_SEARCH devuelve la ruta de cada coincidencia; se quitan una a una y se deja `[]`
        // cuando no queda ninguna, que es lo que `getNotasValidacion()` promete.
        $this->addSql("UPDATE cotizacion_pasajero_identificacion
            SET notas_validacion = COALESCE(
                JSON_REMOVE(notas_validacion, JSON_UNQUOTE(JSON_SEARCH(notas_validacion, 'one', '%girado%'))),
                notas_validacion)
            WHERE JSON_SEARCH(notas_validacion, 'one', '%girado%') IS NOT NULL");

        // Una segunda pasada: una identificación puede tener más de una nota de giro.
        $this->addSql("UPDATE cotizacion_pasajero_identificacion
            SET notas_validacion = COALESCE(
                JSON_REMOVE(notas_validacion, JSON_UNQUOTE(JSON_SEARCH(notas_validacion, 'one', '%girado%'))),
                notas_validacion)
            WHERE JSON_SEARCH(notas_validacion, 'one', '%girado%') IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Las notas de giro ya no se escriben: devolverlas sería reintroducir el fallo.');
    }
}
