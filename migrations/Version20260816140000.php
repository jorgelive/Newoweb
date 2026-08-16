<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `cotizacion_cotcomponente.prestador_visible`: la visibilidad deja de derivarse del modo.
 *
 * ── Qué estaba mal ──────────────────────────────────────────────────────────
 * `CotizacionCotcomponentePrestadorPublicNormalizer` decidía si el cliente ve al prestador
 * releyendo `modo !== 'no_incluido'` **en cada serialización**. El modo es una clasificación
 * comercial, no una decisión editorial sobre qué se enseña, y reevaluarlo al vuelo producía
 * dos cambios que nadie pidió y de los que nadie se enteraba:
 *
 *   · Pasar un componente a `incluido` **borraba al prestador de una propuesta ya enviada**.
 *   · Pasarlo a `no_incluido` **publicaba** un prestador que nadie había revisado, con sus
 *     fotos y su URL.
 *
 * Y dejaba un caso sin poder expresar: asignar un prestador **sólo para operar** —el editor
 * copia siempre el título junto al teléfono y la dirección del recojo— sin publicarlo. La
 * única forma de ocultarlo era borrar el título, que es la única copia del texto.
 *
 * ── Esta migración NO cambia lo que ve ningún cliente ───────────────────────
 * El backfill copia la regla vieja: visible ⟺ el componente es `no_incluido`. Además, hoy
 * el efecto real es nulo en los dos sentidos —ningún componente tiene prestador propio (0 de
 * 150) y ninguno de los 19 `no_incluido` resuelve título por la cascada de la tarifa—, así
 * que no hay ni una propuesta viva cuya vista pueda moverse. Es el momento más barato para
 * hacerlo, y por eso se hace ahora.
 *
 * ── Qué pasa a partir de aquí ───────────────────────────────────────────────
 * La regla no desaparece: se convierte en el DEFAULT al asignar, sembrado en el editor con
 * `modo === no_incluido` **Y** la bandera del maestro `Proveedor::$visibleParaCliente`
 * (`Version20260816120000`). Lo guardado manda desde ese momento, así que reclasificar el
 * componente ya no reescribe lo que el cliente ve.
 *
 * El default es `false` por el mismo motivo que en el maestro: el olvido caro es nombrar a
 * quien no tocaba, no callar a quien sí.
 */
final class Version20260816140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cotcomponente: bandera explícita de visibilidad del prestador, sembrada desde el modo.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotcomponente
            ADD prestador_visible TINYINT(1) DEFAULT 0 NOT NULL');

        // Preserva la regla anterior tal cual: lo que hoy se mostraría es lo `no_incluido`.
        $this->addSql("UPDATE cotizacion_cotcomponente
            SET prestador_visible = 1
            WHERE modo = 'no_incluido'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotizacion_cotcomponente DROP prestador_visible');
    }
}
