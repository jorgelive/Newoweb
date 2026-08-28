<?php

declare(strict_types=1);

namespace App\Pms\Finanzas;

use App\Pms\Enum\PmsMotivoSinCobro;
use App\Pms\Enum\PmsQueSePide;

/**
 * Qué se le puede decir a alguien sobre el dinero de una reserva, AHORA.
 *
 * Es el resultado ya resuelto de {@see PmsSituacionDeCobroResolver}: un objeto de sólo
 * lectura que responde a una pregunta y nada más. **Hechos, nunca prosa.**
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * La misma respuesta se componía en SEIS sitios —dos skills del agente, el mensaje de
 * prepago, la ficha del pax, el panel del operador y el aviso interno— y cada uno la
 * componía a su manera. Las piezas de cálculo (`PmsPrepagoCalculador`,
 * `PmsTotalesPorMoneda`, `FinMedioCobroRepository::ofrecibles()`) ya eran únicas y
 * correctas; **lo que se repetía era la composición**, y ahí es donde derivó: un mensaje
 * ofrecía Western Union a quien llega mañana, otro enseñaba base + comisión donde el
 * huésped sólo quería un total.
 *
 * ── Qué NO entra aquí ───────────────────────────────────────────────────────
 * El detalle línea a línea de los cargos, sus `explicacion_para_huesped` y las notas de
 * instrucción al modelo. Eso es de cada consumidor, y **son prosa**: si entraran, «hechos,
 * nunca prosa» duraría hasta el primero que las metiera. El motivo por el que no se pide
 * nada viaja como {@see PmsMotivoSinCobro}, un enum, no como una frase.
 *
 * Tampoco entra el FORMATO. Quien rinde cada salida es `MessageTemplate` con sus cuatro
 * cuerpos por canal, la ficha del pax o el panel — no este objeto.
 *
 * @see docs/Mensajeria.md
 */
final readonly class PmsSituacionDeCobro
{
    /**
     * @param PmsQueSePide           $queSePide  Adelanto, total, o nada.
     * @param PmsMotivoSinCobro|null $motivo     Por qué no se pide nada. `null` si sí se pide.
     * @param list<PmsImporteMoneda> $importes   Lo que se debe, POR MONEDA y sin convertir.
     * @param list<PmsMedioDeCobro>  $medios     Cómo puede pagarlo, con el importe de cada uno.
     * @param string|null            $enlacePago URL del enlace vivo, si lo hay.
     * @param bool                   $paraHuesped Proyección: `true` oculta lo interno.
     */
    public function __construct(
        public PmsQueSePide $queSePide,
        public ?PmsMotivoSinCobro $motivo,
        public array $importes,
        public array $medios,
        public ?string $enlacePago,
        public bool $paraHuesped,
    ) {
    }

    /** ¿Hay algo que pedirle a esta persona ahora mismo? */
    public function hayAlgoQuePedir(): bool
    {
        return $this->queSePide !== PmsQueSePide::NADA && $this->importes !== [];
    }

    /**
     * Los medios agrupados **por importe**, que es lo único que los distingue para el huésped.
     *
     * ── El abrumamiento vuelve si se listan uno a uno ───────────────────────────
     * Se agrupó por TIPO —de doce filas del catálogo a cinco medios— y seguía sobrando:
     * «Western Union 164.10 · Efectivo 164.10 · Yape 164.10 · Plin 164.10 · Transferencia
     * 164.10 · Tarjeta 173.13» son seis líneas que dicen **dos cifras**. Repetir el mismo
     * número cinco veces es el mismo ruido en otro formato.
     *
     * Lo que de verdad hay que decidir no es «¿por dónde pago?» sino «¿me cuesta lo mismo?».
     * Y sólo hay dos respuestas: el precio normal, y el de tarjeta con su recargo. Por eso se
     * agrupa por importe y no por medio:
     *
     *   Efectivo, Yape, Plin o transferencia ...... 164.10
     *   Tarjeta / Enlace (incluye 5.5%) ........... 173.13
     *
     * Es exactamente la forma del mensaje que el equipo ya escribía a mano.
     *
     * ⚠️ El orden se conserva —`ofrecibles()` ya viene ordenado y la tarjeta va al final—
     * porque abrir por la opción cara empuja a pagar de más a quien podía transferir.
     *
     * @return list<array{importe: string, enSoles: string|null, recargoPorcentaje: string|null, etiquetas: list<string>}>
     */
    public function mediosPorImporte(): array
    {
        $grupos = [];

        foreach ($this->medios as $medio) {
            // La clave incluye el recargo: dos medios con el mismo importe pero uno con
            // comisión no son el mismo caso, aunque hoy no pueda pasar.
            $clave = $medio->importe . '|' . $medio->recargoPorcentaje;

            if (!isset($grupos[$clave])) {
                $grupos[$clave] = [
                    'importe' => $medio->importe,
                    'enSoles' => $medio->enSoles,
                    'recargoPorcentaje' => $medio->llevaRecargo() ? $medio->recargoPorcentaje : null,
                    'etiquetas' => [],
                ];
            }

            $grupos[$clave]['etiquetas'][] = $medio->etiqueta;
        }

        return array_values($grupos);
    }

    /**
     * ¿Se debe en más de una moneda?
     *
     * Importa para el FORMATO: con dos monedas el resumen enseña dos bloques de total y no
     * un cuadre convertido. El cuadre con `≈` es para el panel interno, no para pedirle
     * dinero a alguien — ver §12.2b de docs/PmsBeds24ReservasSync.md.
     */
    public function esMixta(): bool
    {
        return count($this->importes) > 1;
    }
}
