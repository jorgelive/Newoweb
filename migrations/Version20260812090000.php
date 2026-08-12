<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ComponenteEstadoEnum se reduce a `activo` / `cancelado`.
 *
 * Tenía cuatro casos heredados de cuando cotización y operación eran lo mismo. Tres no
 * los leía nadie —el cálculo financiero y la propuesta al cliente sólo preguntan por
 * `cancelado`— pero se ofrecían en un `<select>` que aparentaba registrar la
 * confirmación del proveedor, cosa que en realidad vive en `EstadoReservaEnum` sobre la
 * fila de La Biblia. En la base había 2 componentes marcados `confirmado`, sin efecto.
 *
 * **Los datos se migran ANTES de tocar el default**: si quedara un solo `pendiente` o
 * `confirmado`, Doctrine no fallaría al desplegar sino al hidratar, con un ValueError
 * en runtime y sobre una pantalla cualquiera.
 *
 * De paso se corrige una mina que llevaba tiempo puesta: el default de columna de
 * `cotizacion_cotcomponente.estado` y `cotizacion_cotizacion.estado` era `'Pendiente'`
 * con mayúscula, que **ninguno de los dos enums acepta**. Cualquier fila insertada sin
 * ese campo (un `INSERT` crudo, una importación, un fixture) quedaba inhidratable. No
 * ocurría porque Doctrine siempre escribe la propiedad, pero bastaba una carga de datos.
 *
 * Reejecutable: los `UPDATE` filtran por lo que aún no está migrado y los `ALTER` fijan
 * un estado final, así que correrla dos veces no hace daño.
 *
 * Ver docs/Cotizaciones.md §3.b.
 */
final class Version20260812090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ComponenteEstadoEnum → activo/cancelado; corrige los defaults de columna inválidos (\'Pendiente\').';
    }

    public function up(Schema $schema): void
    {
        // ── 1. DATOS primero ─────────────────────────────────────────────────
        // Todo lo que no esté cancelado pasa a activo. Se usa `<>` y no una lista de
        // valores conocidos a propósito: así arrastra también cualquier resto
        // inesperado —'Pendiente' con mayúscula incluido— en vez de dejarlo fuera.
        $this->addSql("UPDATE cotizacion_cotcomponente SET estado = 'activo' WHERE estado <> 'cancelado'");

        // El snapshot de La Biblia guarda el mismo vocabulario como string suelto. No
        // rompe nada si se queda viejo (no está atado al enum y la vista sólo consulta
        // 'cancelado'), pero dejar 'pendiente' ahí sería conservar una palabra que ya
        // no significa nada en el dominio.
        $this->addSql("UPDATE operacion_servicio SET estado_componente = 'activo' WHERE estado_componente IS NOT NULL AND estado_componente <> 'cancelado'");

        // ── 2. Y sólo entonces, el default de columna ────────────────────────
        $this->addSql("ALTER TABLE cotizacion_cotcomponente CHANGE estado estado VARCHAR(30) DEFAULT 'activo' NOT NULL");
        $this->addSql("ALTER TABLE cotizacion_cotizacion CHANGE estado estado VARCHAR(30) DEFAULT 'pendiente' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        // `activo` no tiene equivalente exacto: era el cajón de pendiente/confirmado/
        // reconfirmado. Se devuelve a `pendiente`, que era el valor del 98,7 % de las
        // filas. Los 2 componentes marcados `confirmado` no se recuperan — no había
        // dónde guardar esa distinción, y no la leía nadie.
        $this->addSql("UPDATE cotizacion_cotcomponente SET estado = 'pendiente' WHERE estado <> 'cancelado'");
        $this->addSql("UPDATE operacion_servicio SET estado_componente = 'pendiente' WHERE estado_componente IS NOT NULL AND estado_componente <> 'cancelado'");

        $this->addSql("ALTER TABLE cotizacion_cotcomponente CHANGE estado estado VARCHAR(30) DEFAULT 'Pendiente' NOT NULL");
        $this->addSql("ALTER TABLE cotizacion_cotizacion CHANGE estado estado VARCHAR(30) DEFAULT 'Pendiente' NOT NULL");
    }
}
