<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Suplemento por persona adicional, por unidad.
 *
 * La tarifa de una noche cubre a un grupo de cierto tamaño y a partir de ahí cada persona
 * suma. Hasta ahora esa regla vivía en la cabeza del equipo y se aplicaba a mano al cotizar,
 * así que ni el agente ni el cargo automático la conocían.
 *
 * ⚠️ **Escrita a mano y NO con `migrations:diff`**: el árbol tiene en paralelo el módulo de
 * enlaces de pago, y un diff habría metido sus tablas aquí dentro. Sólo toca `pms_unidad`.
 *
 * Los valores iniciales van con `WHERE nombre =` y no por id: son los siete de este
 * alojamiento y el nombre es lo estable entre entornos. Cada UPDATE es independiente, así que
 * una casita que no exista en otro entorno no rompe el resto.
 */
final class Version20260808040000 extends AbstractMigration
{
    /**
     * nombre => [personas que cubre la tarifa, precio por persona extra y noche]
     *
     * Casita 5 va a 0.00: su tarifa cubre las dos plazas que tiene y no admite a nadie más.
     */
    private const array REGLA = [
        'Casita 1' => [5, '6.00'],
        'Casita 2' => [4, '6.00'],
        'Casita 3' => [4, '6.00'],
        'Casita 4' => [3, '6.00'],
        'Casita 5' => [2, '0.00'],
        'Casita 6' => [7, '6.00'],
        'Casita 7' => [7, '6.00'],
    ];

    public function getDescription(): string
    {
        return 'pms_unidad: pax_incluidos y precio_pax_adicional, con la regla actual cargada';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE pms_unidad '
            . 'ADD pax_incluidos SMALLINT DEFAULT 0 NOT NULL, '
            . 'ADD precio_pax_adicional NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL'
        );

        // Sólo si siguen en el valor por defecto: si alguien ya los tocó en este entorno,
        // esta migración no le pisa lo suyo.
        foreach (self::REGLA as $nombre => [$incluidos, $precio]) {
            $this->addSql(
                'UPDATE pms_unidad SET pax_incluidos = :incluidos, precio_pax_adicional = :precio '
                . 'WHERE nombre = :nombre AND pax_incluidos = 0',
                ['incluidos' => $incluidos, 'precio' => $precio, 'nombre' => $nombre]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_unidad DROP pax_incluidos, DROP precio_pax_adicional');
    }
}
