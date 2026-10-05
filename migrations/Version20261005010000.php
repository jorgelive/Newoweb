<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * «Quelcaya» → «Quelccaya» en todo lo CONGELADO: snapshots, derivados y el código del servicio.
 *
 * El glaciar lleva dos ces y el catálogo tenía las dos grafías a la vez —«Ingreso a Quelccaya»
 * junto a «Pool Quelcaya»—. Con una letra de diferencia la línea de la orden parecía repetir el
 * mismo texto y ninguna regla de silencio podía verlo: para una comparación son dos cadenas
 * distintas. Ver `docs/Operacion.md` §13.bis.
 *
 * ## El reparto, que no es cosmético
 *
 * Aquí va lo que **ningún listener recalcula al guardarse**. Los dos `titulo` con
 * `#[AutoTranslate]` —el del componente maestro y el de la cotización— van por
 * `app:travel:corregir-quelccaya`, porque un `UPDATE` se salta el listener y dejaría el español
 * corregido con siete traducciones diciendo «Quelcaya».
 *
 * ## Lo que NO se toca, a propósito
 *
 * Las 10 ocurrencias de mensajería: `msg_message.content_local` y `content_external` son frases
 * que **escribió una persona** el 20/09/2026 («Jueves 8 para el glaciar de quelcaya para 4
 * personas»), y `msg_meta_webhook_audit.payload_raw` y `msg_whatsapp_meta_send_queue
 * .last_request_raw` son el registro literal de lo que Meta nos mandó y de lo que le mandamos.
 *
 * ⚠️ **Corregir la ortografía de una conversación es falsificar lo que alguien dijo**, y el
 * registro de un envío sirve precisamente para comparar contra lo que salió. Es la misma regla
 * que «no se borra: se marca» y que «un documento emitido dice lo que decía al emitirse».
 *
 * ## Por qué `REPLACE` es seguro aquí
 *
 * `REPLACE(x, 'Quelcaya', 'Quelccaya')` no puede producir «Quelcccaya»: la cadena «Quelccaya» no
 * contiene «Quelcaya» —después de «Quelc» va una «c», no una «a»—, así que las filas ya correctas
 * no casan y la migración es repetible. El `WHERE … LIKE` lo deja además explícito.
 *
 * Medido antes de escribirla: 33 ocurrencias en 16 columnas de toda la base; 23 aquí, 2 en el
 * comando y 8 intactas en mensajería.
 */
final class Version20261005010000 extends AbstractMigration
{
    /** Columna → tabla, de lo que no pasa por ningún listener. */
    private const CAMPOS = [
        'travel_componente' => ['nombre_interno'],
        'travel_servicio' => ['codigo'],
        'cotizacion_cotcomponente' => ['nombre_interno_snapshot', 'titulo_snapshot'],
        'cotizacion_cotizacion' => ['clasificacion_financiera', 'clasificacion_financiera_cliente'],
        'operacion_servicio' => ['nombre_componente', 'snapshot_origen'],
        'operacion_orden_servicio_item' => ['nombre_componente', 'descripcion'],
    ];

    public function getDescription(): string
    {
        return 'Quelcaya → Quelccaya en snapshots, derivados y el código del servicio';
    }

    public function up(Schema $schema): void
    {
        foreach (self::CAMPOS as $tabla => $columnas) {
            foreach ($columnas as $columna) {
                $this->addSql(sprintf(
                    "UPDATE `%s` SET `%s` = REPLACE(`%s`, 'Quelcaya', 'Quelccaya') WHERE `%s` LIKE '%%Quelcaya%%'",
                    $tabla,
                    $columna,
                    $columna,
                    $columna
                ));
            }
        }

        // El código del servicio va en mayúsculas y por eso no lo cazaría el REPLACE de arriba
        // en una base con collation sensible; se hace aparte y explícito.
        $this->addSql("UPDATE `travel_servicio` SET `codigo` = 'QUELCCAYA' WHERE `codigo` = 'QUELCAYA'");
    }

    public function down(Schema $schema): void
    {
        // No hay vuelta atrás honesta: «Quelccaya» legítimo —el componente «Ingreso a Quelccaya»
        // ya lo escribía bien antes de esto— quedaría indistinguible del corregido, y revertir
        // reintroduciría la errata en filas que nunca la tuvieron. La grafía correcta se queda.
        $this->throwIrreversibleMigrationException('La ortografía corregida no se revierte: no se puede distinguir lo ya correcto.');
    }
}
