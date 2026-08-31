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
    public function __construct(
        private PmsSituacionDeCobroResolver $situaciones,
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

        $moneda = $situacion->importes[0]->moneda;
        $adelanto = $situacion->queSePide === PmsQueSePide::ADELANTO;
        $pagado = $this->yaPagado($reserva);
        $lineas = [];

        // ── 1 · LO QUE HAY QUE HACER, primero ───────────────────────────────────
        //
        // Abre por la petición y no por el total. El total es orden de contabilidad; en un
        // WhatsApp la primera línea es el asunto, y el asunto es «adelanta 59.43».
        $lineas[] = sprintf(
            '*%s:* %s',
            $this->t(match (true) {
                $adelanto => 'res_pide_adelanto',
                $pagado !== null => 'res_saldo',
                default => 'res_pide_total',
            }, $idioma),
            $this->importe($situacion->importes[0]->importe, $moneda, $situacion->importes[0]->enSoles)
        );

        // ── 2 · POR QUÉ es esa cifra ────────────────────────────────────────────
        //
        // Sólo con adelanto, y en la misma línea que el total: «59.43 de 356.55» se entiende
        // solo, y ahí es donde el total sirve. Sin esto, el huésped ve un número suelto sobre
        // una reserva de otro importe.
        //
        // El texto de la política NO se escribe aquí: sale del enum del establecimiento virtual
        // vía `claveDeLaPolitica`. Ver `PmsSituacionDeCobro`.
        if ($adelanto) {
            $porQue = array_filter([
                $situacion->claveDeLaPolitica !== null
                    ? $this->t($situacion->claveDeLaPolitica, $idioma)
                    : '',
                $this->totalDeLaReserva($reserva) !== null
                    ? sprintf(
                        '%s: %s',
                        $this->t('res_total_reserva', $idioma),
                        $this->importe((string) $this->totalDeLaReserva($reserva), $moneda, null)
                    )
                    : '',
            ]);

            if ($porQue !== []) {
                $lineas[] = sprintf('_%s_', implode(' · ', $porQue));
            }
        }

        // ── 3 · LA TARJETA, porque cambia el número ─────────────────────────────
        //
        // Es la única forma de pago que se nombra en el mensaje, y por una regla: **si cambia el
        // importe, va aquí; si es un cómo-se-hace, va en la ficha**. Los nombres de los medios
        // no viajan — mañana se añade un banco o cambia una audiencia y el mensaje seguiría
        // diciendo lo de hoy. La tarjeta sí, porque quien pague con ella verá otra cifra y ahí
        // nace el «pero si ponía 59.43».
        foreach ($situacion->mediosPorImporte() as $grupo) {
            if ($grupo['recargoPorcentaje'] === null) {
                continue;
            }

            $lineas[] = sprintf(
                '_%s: %s — %s_',
                $this->t('res_con_tarjeta', $idioma),
                $this->importe($grupo['importe'], $moneda, $grupo['enSoles']),
                $this->t('res_recargo_nota', $idioma, ['pct' => (string) (float) $grupo['recargoPorcentaje']])
            );
        }

        // ── 4 · Y AL LLEGAR ─────────────────────────────────────────────────────
        //
        // Sólo con adelanto, igual que en la ficha: pidiendo el total sería el mismo número otra
        // vez y se leería como una segunda deuda. Sin sus medios —van en la ficha— porque aquí
        // sólo hace falta contestar «¿y cuánto pago al llegar?», que es la pregunta que llegaba
        // por chat con la respuesta delante.
        if ($adelanto && $situacion->pagoAlLlegar !== null) {
            $lineas[] = '';
            $lineas[] = sprintf(
                '*%s:* %s',
                $this->t('res_saldo_al_llegar', $idioma),
                $this->importe(
                    $situacion->pagoAlLlegar->importe->importe,
                    $moneda,
                    $situacion->pagoAlLlegar->importe->enSoles
                )
            );
        }

        // ⚠️ **El enlace de pago NO viaja aquí.** El mensaje manda a la ficha, y la ficha ya
        // tiene su botón «Pagar ahora» junto a la cifra que cobra. Dos caminos al mismo cobro es
        // el error que la propia ficha corrigió en su día: el cuadro decía un importe y el
        // enlace cobraba otro. La línea del enlace la pone el CUERPO de la plantilla con
        // `{{ account_url }}`, que es también donde se decide cómo invitarlo.
        return implode("\n", $lineas);
    }

    /**
     * Lo que se le pide AHORA, en una línea: «USD 60.96» o «USD 35.91 (S/ 120.30)».
     *
     * ── Por qué existe, si ya está el bloque ────────────────────────────────────
     * Para las plantillas de **Meta**, que no pueden llevar el bloque: un parámetro de Meta no
     * admite saltos de línea, ni tabuladores, ni cuatro espacios seguidos, y el bloque son cuatro
     * renglones con negritas. Un escalar sí cabe.
     *
     * ⚠️ **No es `balance` ni `total_amount`.** El primero es el saldo contable y el segundo el
     * total de la reserva; ninguno responde «cuánto se le pide ahora», que con una política de
     * adelanto es otra cifra distinta de las dos. En un mensaje que no lleva el detalle, decir el
     * número equivocado es peor que no decir ninguno.
     *
     * `null` cuando no hay nada que pedir — y entonces la plantilla no debería mandarse.
     */
    public function importeAPagar(PmsReserva $reserva): ?string
    {
        // Resuelve la situación por segunda vez si también se pidió el bloque. Son dos consultas
        // más por mensaje y se prefiere a memorizar estado dentro de un servicio: con veinte
        // mensajes al día no se nota, y un caché aquí es una foto que puede quedarse vieja
        // dentro de la misma petición que acaba de registrar un cobro.
        $situacion = $this->situaciones->paraHuesped($reserva);

        if (!$situacion->hayAlgoQuePedir()) {
            return null;
        }

        return $this->importe(
            $situacion->importes[0]->importe,
            $situacion->importes[0]->moneda,
            $situacion->importes[0]->enSoles
        );
    }

    /**
     * El total que abona la reserva, en la moneda de la cabecera.
     *
     * Sale de `PmsTotalesPorMoneda`, que es la misma fuente del saldo y de los adelantos. Con
     * varias monedas devuelve `null`: un total único no existe ahí y sumarlas sería convertir sin
     * decirlo (§12.2b). El mensaje se queda sin ese contexto, que es preferible a una cifra falsa.
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

    /**
     * Lo cobrado hasta ahora, o `null` si no hay nada que decir.
     *
     * Misma fuente que el total —`PmsTotalesPorMoneda`— y misma cautela: con varias monedas no
     * hay una cifra única, y sumarlas sería convertir sin decirlo (§12.2b).
     */
    private function yaPagado(PmsReserva $reserva): ?string
    {
        $info = $reserva->getInformacionFinanciera();

        if ($info === null) {
            return null;
        }

        $totales = PmsTotalesPorMoneda::de($info);

        if (count($totales->porMoneda) !== 1) {
            return null;
        }

        $pagos = (string) $totales->porMoneda[array_key_first($totales->porMoneda)]['pagos'];

        // Un cero no es información: «ya pagado: 0.00» sólo añade una línea que no dice nada.
        return (float) $pagos > 0.0 ? $pagos : null;
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
