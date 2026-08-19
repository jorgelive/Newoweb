<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `categoria` en los ítems de guía: DE QUÉ habla un ítem, para poder restarlo por canal.
 *
 * Nace de una fuga real. A una interesada que llegó por Airbnb y aún no había confirmado se le
 * dio la dirección exacta del alojamiento —calle, número y referencia—, que es justo lo que la
 * plataforma prohíbe antes de que la reserva se cierre y por lo que penaliza al anfitrión. El
 * sistema no falló: `PmsGuiaVisibilidad` clasifica la ubicación como `Publico`, así que
 * entregarla era, según el modelo vigente, lo correcto.
 *
 * El eje que faltaba no es cuánta confianza merece quien pregunta, sino por qué tubo sale la
 * respuesta. Y no se podía resolver moviendo la dirección a un escalón más alto: eso la habría
 * escondido también en el catálogo público de la web, donde debe seguir estando. Tampoco se
 * podía resolver bajando el escalón entero del interesado, porque a esa misma persona hay que
 * contestarle cuántas habitaciones hay, cuántas camas y cómo es el check-in — o se pierde la
 * reserva, que fue lo que pasó.
 *
 * Por eso es una RESTA ortogonal y no un peldaño: la escalera dice cuánto se abre, la categoría
 * dice qué no pasa por este canal. Ver `App\Contract\CategoriaConocimiento` y
 * `App\Agent\Access\RestriccionCanal`.
 *
 * ⚠️ Esta migración sólo crea la columna con todo en `general` —«siempre se puede contar»—,
 * que es el default seguro para no dejar mudo al agente. **No clasifica el contenido
 * existente**: mientras nadie marque los ítems de dirección y contacto, la resta no tapa nada
 * porque no hay nada marcado. Esa clasificación es una decisión editorial, se hace desde el
 * panel ítem a ítem, y hasta que se haga la protección real sigue siendo el aviso del prompt,
 * que es una petición al modelo y no un cierre.
 */
final class Version20260812250000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade pms_guia_item.categoria para restar contenido por canal (dirección, contacto, canal alternativo).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE pms_guia_item ADD categoria VARCHAR(30) DEFAULT 'general' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_guia_item DROP categoria');
    }
}
