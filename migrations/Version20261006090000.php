<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * De UN destacado a VARIOS: la columna pasa de id suelto a lista.
 *
 * Nació con uno el mismo día —«la experiencia que se vende» sonaba a una cosa— y es falso en
 * cuanto el viaje tiene dos protagonistas: el resort **y** la excursión a Saona, que es el caso
 * normal de una promoción escolar.
 *
 * ⚠️ **El valor que ya había se conserva**: 1 de las 15 cotizaciones tenía su destacado puesto y
 * entra en la lista como único elemento. Un `ADD` + `DROP` sin el `UPDATE` de en medio lo habría
 * perdido sin decir nada — y una columna que nace vacía parece exactamente igual a una que nunca
 * se llenó.
 *
 * El orden es `JSON_ARRAY(...)` y no un literal `'["..."]'` porque así lo construye MySQL y no hay
 * que preocuparse de comillas ni de escapes.
 *
 * Ampliar a lista fue barato porque la columna guardaba un **puntero** y no contenido. Si hubiera
 * guardado el texto y las fotos del hotel, pasar a varios habría sido multiplicar por N el
 * problema de la copia que ese diseño vino a evitar.
 */
final class Version20261006090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cotizacion_cotizacion: el destacado pasa de un id suelto a una lista';
    }

    public function up(Schema $schema): void
    {
        // ⚠️ **Nulable primero, NOT NULL al final.** Una columna `JSON NOT NULL` añadida a una
        // tabla con filas se rellena con el literal JSON `null`, que satisface el NOT NULL, NO lo
        // caza un `WHERE … IS NULL` y revienta al LEER: `json_decode('null')` da `null` de PHP y
        // Doctrine lo asigna a una propiedad `array`. La migración diría [OK] y el fallo saldría
        // días después, la primera vez que alguien abra una fila vieja.
        //
        // Y sin `DEFAULT (JSON_ARRAY())`, que deja el esquema fuera de sync con el mapeo: Doctrine
        // mapea `JSON NOT NULL` a secas y `schema:validate` se queda en rojo para siempre — que es
        // justo lo que entrena a no mirar la única herramienta que compara las dos mitades.
        $this->addSql('ALTER TABLE cotizacion_cotizacion ADD destacados_componente_ids JSON DEFAULT NULL');

        // Todas las filas, no sólo las marcadas: las demás necesitan su `[]` antes del NOT NULL.
        // El que ya estaba marcado entra como único elemento de su lista.
        $this->addSql(<<<'SQL'
            UPDATE cotizacion_cotizacion
            SET destacados_componente_ids = IF(
                destacado_componente_id IS NULL,
                JSON_ARRAY(),
                JSON_ARRAY(destacado_componente_id)
            )
            SQL);

        $this->addSql('ALTER TABLE cotizacion_cotizacion MODIFY destacados_componente_ids JSON NOT NULL');
        $this->addSql('ALTER TABLE cotizacion_cotizacion DROP destacado_componente_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotizacion ADD destacado_componente_id VARCHAR(36) DEFAULT NULL');

        // Vuelve el PRIMERO. Si había varios, los demás se pierden — por eso esto degrada y se
        // dice: el campo viejo no sabe expresar una lista.
        $this->addSql(<<<'SQL'
            UPDATE cotizacion_cotizacion
            SET destacado_componente_id = JSON_UNQUOTE(JSON_EXTRACT(destacados_componente_ids, '$[0]'))
            WHERE JSON_LENGTH(destacados_componente_ids) > 0
            SQL);

        $this->addSql('ALTER TABLE cotizacion_cotizacion DROP destacados_componente_ids');
    }
}
