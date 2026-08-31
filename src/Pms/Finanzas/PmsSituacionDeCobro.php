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
     * @param PmsTramoDeCobro|null   $saldoTrasAdelanto Lo que quedará por pagar al llegar.
     */
    public function __construct(
        public PmsQueSePide $queSePide,
        public ?PmsMotivoSinCobro $motivo,
        public array $importes,
        public array $medios,
        public ?string $enlacePago,
        public bool $paraHuesped,
        /**
         * El SEGUNDO tramo: lo que queda por pagar cuando ya se haya abonado el adelanto.
         *
         * `null` en todos los casos menos uno —`queSePide === ADELANTO`, una sola moneda y
         * resto positivo—, y esa estrechez es la respuesta correcta: pidiendo el TOTAL no hay
         * segundo tramo, y con dos monedas restar sería convertir sin decirlo (§12.2b).
         *
         * ⚠️ **Sus medios NO son los mismos que los del adelanto**, y por eso es un tramo y no
         * una cifra. El adelanto se paga a distancia y el resto en la puerta: el catálogo ya
         * distingue los dos momentos con `diasMinimos`/`diasMaximos` —Western Union pide 2 días
         * de antelación, el efectivo exige a la persona delante (`diasMaximos = 0`)—, así que
         * el efectivo **no** aparece en el adelanto y Western Union **no** aparece en el saldo.
         * Eso sale solo de evaluar la misma regla en el momento de cada tramo.
         */
        public ?PmsTramoDeCobro $saldoTrasAdelanto = null,
    ) {
    }

    /** ¿Hay algo que pedirle a esta persona ahora mismo? */
    public function hayAlgoQuePedir(): bool
    {
        return $this->queSePide !== PmsQueSePide::NADA && $this->importes !== [];
    }

    /**
     * Los medios del PRIMER tramo —lo que se pide ahora— agrupados por importe.
     *
     * El porqué de agrupar y el de conservar el orden están en
     * {@see PmsMedioDeCobro::agruparPorImporte()}, que es donde vive la agrupación desde que
     * hay un segundo tramo que necesita la misma.
     *
     * @return list<array{importe: string, enSoles: string|null, recargoPorcentaje: string|null, codigos: list<string>, etiquetas: list<string>}>
     */
    public function mediosPorImporte(): array
    {
        return PmsMedioDeCobro::agruparPorImporte($this->medios);
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
