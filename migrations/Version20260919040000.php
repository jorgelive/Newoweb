<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Devuelve a «nunca leído» los escaneos que girar dejó MUDOS.
 *
 * ── Qué pasó ────────────────────────────────────────────────────────────────
 * 🔥 `GiradorDeEscaneo` corregía la orientación guardada en la lectura, y cuando **no había**
 * lectura `conOrientacionCorregida(null, …)` devolvía `null` → `registrarLectura(null)`. Eso NO
 * borra la lectura: significa «se intentó y falló» —deja `leido_en` puesto—, así que
 * `ValidadorDeDocumento::lecturaDe()` devolvía `null` para siempre.
 *
 * El reverso del DNI no lo lee ninguna tanda desde que dejó de ser validable (09/09/2026), así que
 * llegaba al girador sin lectura y **girarlo lo dejaba mudo**: sin `bordeSuperior` no hay
 * `rotacionPendiente`, y el aviso de «este escaneo está torcido» no volvía a aparecer. La acción
 * que endereza el documento era la que apagaba su propio aviso.
 *
 * Medido en producción el 19/09/2026: de 127 reversos, **32 en ese estado —y los 32 girados—** y 26
 * que nunca se intentaron. El código ya no lo reintroduce (el girador sólo toca la lectura si
 * existe, y el comando vuelve a leer los tres escaneos de identidad).
 *
 * ── Por qué por migración y no por comando ──────────────────────────────────
 * Sólo pone tres columnas a NULL y **ningún listener recalcula nada a partir de ellas**:
 * `EscaneoNuevoInvalidaVeredictoListener` mira fichero nuevo, dueño y tipo, no la lectura. Lo que
 * sigue después es una lectura normal, que se paga cuando alguien la pida.
 *
 * ⚠️ **Acotado a lo que de verdad está roto**: lectura vacía, fecha puesta, sin motivo y girado. Un
 * fallo con motivo (`lectura_error`) se queda como está — ése sí es «se intentó y falló», y
 * reintentarlo a ciegas es pagar por el mismo error.
 */
final class Version20260919040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Olvida la lectura fantasma que dejaba girar un escaneo sin lectura previa (leido_en puesto, datos vacíos, sin motivo).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE cotizacion_file_archivo
            SET leido_en = NULL
            WHERE datos_leidos IS NULL
              AND leido_en IS NOT NULL
              AND lectura_error IS NULL
              AND rotacion_aplicada <> 0
              AND tipo_archivo IN ('pasaporte', 'dni_anverso', 'dni_reverso', 'autorizacion')");
    }

    /**
     * No hay vuelta: «nunca leído» es el estado correcto y el anterior era el fallo. Volver a
     * marcarlos como intentados sería reintroducirlo.
     */
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Marcar de nuevo esos escaneos como «leídos y fallidos» sería volver al fallo.');
    }
}
