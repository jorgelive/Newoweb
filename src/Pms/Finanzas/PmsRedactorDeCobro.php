<?php

declare(strict_types=1);

namespace App\Pms\Finanzas;

use App\Pax\Service\TextosUi;
use App\Pms\Entity\PmsReserva;
use App\Pms\Enum\PmsMotivoSinCobro;
use App\Pms\Enum\PmsQueSePide;
use App\Pms\Service\Finance\PmsTotalesPorMoneda;

/**
 * El bloque de dinero de un mensaje: {@see PmsSituacionDeCobro} convertido en texto.
 *
 * ── Qué es y qué no ─────────────────────────────────────────────────────────
 * Es el ÚNICO sitio donde el read-model se vuelve prosa. No decide nada: qué se pide, cuánto,
 * con qué medios y a qué precio ya viene resuelto. Aquí sólo se elige el orden de las líneas y
 * de dónde sale cada rótulo.
 *
 * **No lleva saludo ni despedida.** Eso vive en el cuerpo de la plantilla, que está traducido a
 * siete idiomas por `AutoTranslate` y que es donde el tono cambia según a quién se escriba. Este
 * bloque entra por `{{ bloque_pago }}` y se ocupa sólo de las cifras. Repartirlo así es lo que
 * permite reformular la bienvenida sin tocar el dinero, y al revés.
 *
 * ── A quién jubila ──────────────────────────────────────────────────────────
 * A `GenerarMensajePrepagoSkill`, que compone su propio texto en 400 líneas de PHP con su propio
 * formato de fechas —en español a pelo— y su propia idea de los medios de pago. Era el productor
 * nº 3 de los seis que documenta §«La fuente única sobre el dinero»; mientras coexistan hay dos
 * verdades sobre el mismo dinero.
 *
 * ── Los rótulos NO se escriben aquí ─────────────────────────────────────────
 * Salen de `pax_ui_i18n` por {@see TextosUi}, en los siete idiomas, y los medios se resuelven por
 * **código** (`res_medio_yape`) y no por su etiqueta, que viene del enum PHP y está en español.
 * Lo que nunca se traduce: importes, códigos de moneda y el enlace. Ver §22.24 de
 * `docs/Mensajeria.md` — «a ese nombre» traducido al italiano dejó un giro sin poder cobrar.
 */
final readonly class PmsRedactorDeCobro
{
    /** Viñeta de cada opción de precio. Sobrevive a la degradación por canal; un `•` no. */
    private const string VINETA = '▪️';

    public function __construct(
        private PmsSituacionDeCobroResolver $situaciones,
        private PmsEquivalenciaEnSoles $equivalencia,
        private TextosUi $textos,
    ) {
    }

    /**
     * El bloque, o cadena vacía cuando no hay nada honesto que decir.
     *
     * ⚠️ **Vacío es una respuesta válida y el cuerpo tiene que aguantarlo.** Con un cruce de
     * monedas sin imputar, o una cuenta con datos incompletos, el read-model calla a propósito —
     * y aquí no se rellena el hueco con una frase amable, porque cualquier frase afirmaría algo.
     * Por eso el cuerpo de la plantilla no puede escribirse como «Aquí tienes tu resumen:
     * {{ bloque_pago }}»: la línea de arriba tiene que sostenerse sola.
     */
    public function bloque(PmsReserva $reserva, string $idioma): string
    {
        $situacion = $this->situaciones->paraHuesped($reserva);

        if (!$situacion->hayAlgoQuePedir()) {
            // Sólo se dice lo que es seguro decir. «Saldada» es un hecho que el huésped agradece
            // leer; «cruce de monedas» o «datos incompletos» son cosas nuestras, y contárselas
            // sería pedirle que entienda nuestra contabilidad.
            return $situacion->motivo === PmsMotivoSinCobro::SALDADA
                ? $this->t('res_todo_pagado', $idioma)
                : '';
        }

        $lineas = [];

        // La moneda del primer importe rotula TODAS las líneas. Sin esto, el total salía como
        // «USD 107.74» y las opciones de pago como «107.74 (S/ 360.93)»: dos formatos para el
        // mismo dinero en el mismo párrafo, y el huésped preguntándose si son cifras distintas.
        $moneda = $situacion->importes[0]->moneda;

        // El TOTAL de la reserva primero, siempre. No sale del read-model —que lleva lo
        // pendiente, no el total— sino de la cabecera financiera, que es de donde salen todas
        // las demás cifras de esta casa.
        $total = $this->totalDeLaReserva($reserva);

        if ($total !== null) {
            $lineas[] = sprintf(
                '*%s:* %s',
                $this->t('res_total_reserva', $idioma),
                // Con su equivalencia, como las de abajo: el total sin soles y las opciones con
                // ellos era la otra mitad de la misma incoherencia.
                $this->importe($total, $moneda, $this->equivalencia->de($total, $moneda, $reserva))
            );
            $lineas[] = '';
        }

        // Qué se pide AHORA, con sus precios.
        $lineas[] = sprintf(
            '*%s:*',
            $this->t($situacion->queSePide === PmsQueSePide::ADELANTO ? 'res_pide_adelanto' : 'res_pide_total', $idioma)
        );

        foreach ($situacion->mediosPorImporte() as $grupo) {
            $lineas[] = $this->linea($grupo, $moneda, $idioma);
        }

        // El enlace va con el primer tramo y no al final del mensaje: es la acción de AHORA, y
        // puesto debajo del saldo se leería como si sirviera para pagarlo todo.
        if ($situacion->enlacePago !== null) {
            $lineas[] = sprintf('🔗 %s: %s', $this->t('res_pagar_online', $idioma), $situacion->enlacePago);
        }

        // Y el segundo momento, cuando lo hay. Sus medios NO son los de arriba —aquí aparece el
        // efectivo y desaparece Western Union— y por eso se listan otra vez en vez de decir «lo
        // mismo». Ver PmsTramoDeCobro.
        $saldo = $situacion->saldoTrasAdelanto;

        if ($saldo !== null) {
            $lineas[] = '';
            $lineas[] = sprintf(
                '*%s:* %s',
                $this->t('res_saldo_al_llegar', $idioma),
                $this->importe($saldo->importe->importe, $saldo->importe->moneda, $saldo->importe->enSoles)
            );

            foreach ($saldo->mediosPorImporte() as $grupo) {
                $lineas[] = $this->linea($grupo, $saldo->importe->moneda, $idioma);
            }
        }

        return implode("\n", $lineas);
    }

    /**
     * Una línea de precio: los medios que valen lo mismo, su importe y su matiz de comisión.
     *
     * @param array{importe: string, enSoles: string|null, recargoPorcentaje: string|null, codigos: list<string>, etiquetas: list<string>} $grupo
     */
    private function linea(array $grupo, string $moneda, string $idioma): string
    {
        $nombres = [];

        foreach ($grupo['codigos'] as $i => $codigo) {
            // La etiqueta del backend es el respaldo, no la fuente: sale de
            // `FinMedioCobroTipo::label()` y está en español y sólo en español.
            $nombres[] = $this->t('res_medio_' . $codigo, $idioma) ?: ($grupo['etiquetas'][$i] ?? $codigo);
        }

        $matiz = $grupo['recargoPorcentaje'] !== null
            ? $this->t('res_recargo_nota', $idioma, ['pct' => (string) (float) $grupo['recargoPorcentaje']])
            : $this->t('res_sin_comision', $idioma);

        return sprintf(
            '%s *%s:* %s _%s_',
            self::VINETA,
            implode(', ', $nombres),
            $this->importe($grupo['importe'], $moneda, $grupo['enSoles']),
            $matiz
        );
    }

    /**
     * El total que abona la reserva, en la moneda de la cabecera.
     *
     * Sale de `PmsTotalesPorMoneda`, que es la misma fuente del saldo y de los adelantos. Con
     * varias monedas devuelve `null`: un total único no existe ahí y sumarlas sería convertir sin
     * decirlo (§12.2b). El mensaje se queda sin esa línea, que es preferible a una cifra falsa.
     *
     * Devuelve la CIFRA pelada; la moneda y la equivalencia las pone quien la escribe, que es
     * quien sabe con qué formato van las demás líneas del bloque.
     */
    private function totalDeLaReserva(PmsReserva $reserva): ?string
    {
        $info = $reserva->getInformacionFinanciera();

        if ($info === null) {
            return null;
        }

        $totales = PmsTotalesPorMoneda::de($info);

        if (count($totales->porMoneda) !== 1) {
            return null;
        }

        return (string) $totales->porMoneda[array_key_first($totales->porMoneda)]['cargos'];
    }

    /** Un importe con su moneda y, si procede, su equivalencia orientativa en soles. */
    private function importe(string $importe, ?string $moneda, ?string $enSoles): string
    {
        $texto = $moneda !== null ? sprintf('%s %s', $moneda, $importe) : $importe;

        return $enSoles !== null ? sprintf('%s (S/ %s)', $texto, $enSoles) : $texto;
    }

    /** @param array<string, string> $marcadores */
    private function t(string $clave, string $idioma, array $marcadores = []): string
    {
        $texto = $this->textos->texto($clave, $idioma, $marcadores);

        // `TextosUi` devuelve la clave cuando no existe —es visible y se arregla el mismo día—,
        // pero un `res_medio_paypal` en mitad de un WhatsApp al huésped no. Aquí se prefiere el
        // hueco, que el llamante sabe rellenar con el respaldo en español.
        return $texto === $clave ? '' : $texto;
    }
}
