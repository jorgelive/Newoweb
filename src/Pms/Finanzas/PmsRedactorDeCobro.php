<?php

declare(strict_types=1);

namespace App\Pms\Finanzas;

use App\Pax\Service\TextosUi;
use App\Pms\Entity\PmsReserva;
use App\Pms\Enum\PmsMedioPago;
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
 * ── A quién jubiló ──────────────────────────────────────────────────────────
 * A `GenerarMensajePrepagoSkill`, **borrada el 06/09/2026**: componía su propio texto en 400
 * líneas de PHP, con su formato de fechas en español a pelo, `US$` en duro y su propia idea de
 * los medios de pago. Era el productor nº 3 de los seis que documenta §«La fuente única sobre el
 * dinero». Este bloque y sus tres variables hermanas son ahora el único camino.
 *
 * ── Los rótulos NO se escriben aquí ─────────────────────────────────────────
 * Salen de `pax_ui_i18n` por {@see TextosUi}, en los siete idiomas, y los medios se resuelven por
 * **código** (`res_medio_yape`) y no por su etiqueta, que viene del enum PHP y está en español.
 * Lo que nunca se traduce: importes, códigos de moneda y el enlace. Ver §22.24 de
 * `docs/Mensajeria.md` — «a ese nombre» traducido al italiano dejó un giro sin poder cobrar.
 */
final class PmsRedactorDeCobro
{
    /**
     * La situación del mensaje en curso, memorizada por reserva.
     *
     * ── Por qué esta clase dejó de ser `readonly` ───────────────────────────────
     * Cada variable de plantilla —`bloque_pago`, `importe_a_pagar`, `medios_de_pago`— resolvía
     * la situación por su cuenta, y todas se piden para el MISMO mensaje: tres resoluciones
     * idénticas, con sus consultas de medios y de enlaces vivos, por cada envío. Con una cuarta
     * forma más ya era ridículo.
     *
     * ⚠️ **Se guarda por id de reserva y no una sola**, porque un lote mapea varios mensajes
     * seguidos en el mismo proceso y el caché de uno no puede contestar por otro.
     *
     * ⚠️ Y **no sobrevive al proceso**: es un servicio de Symfony, así que muere con la petición
     * o con el ciclo del worker. Es justo lo que hace falta — un caché que durara más sería una
     * foto vieja contestando dentro de la misma petición que acaba de registrar un cobro, que es
     * el fallo que este módulo lleva meses evitando.
     *
     * @var array<string, PmsSituacionDeCobro>
     */
    private array $memoria = [];

    public function __construct(
        private readonly PmsSituacionDeCobroResolver $situaciones,
        private readonly TextosUi $textos,
    ) {
    }

    /** La situación de esta reserva, resuelta UNA vez para todas las variables del mensaje. */
    private function situacion(PmsReserva $reserva): PmsSituacionDeCobro
    {
        return $this->memoria[(string) $reserva->getId()] ??= $this->situaciones->paraHuesped($reserva);
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
        $situacion = $this->situacion($reserva);

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
        // Sólo con adelanto: «Equivalente a la primera noche» explica un número que, sin eso, no
        // se parece a nada de la reserva.
        //
        // El texto de la política NO se escribe aquí: sale del enum del establecimiento virtual
        // vía `claveDeLaPolitica`. Ver `PmsSituacionDeCobro`.
        //
        // ⚠️ **El total ya no va en esta línea** (11/09/2026). Iba aquí —«59.43 de 356.55» se
        // entiende solo—, pero quedaba entre el adelanto y la tarjeta: tres cifras seguidas de
        // las que sólo una es la que se paga, y la mayor en medio. Se mudó al final, el paso 5.
        if ($adelanto && $situacion->claveDeLaPolitica !== null) {
            $lineas[] = sprintf('_%s_', $this->t($situacion->claveDeLaPolitica, $idioma));
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

        // ── 5 · EL TOTAL, al final y como referencia ────────────────────────────
        //
        // Pedido por Jorge el 11/09/2026: la primera cifra que se ve tiene que ser la que se
        // paga, y la más pequeña. Al final se lee como lo que es —adelanto más saldo— y no como
        // una tercera cantidad que pagar. Es también el sitio que ya le da la ficha de `pax`, que
        // lo pone al pie del detalle de cargos.
        //
        // Sólo con adelanto, igual que antes: pidiendo el total, la primera línea YA es ese
        // número, y repetirlo abajo se leería como una segunda deuda.
        $total = $adelanto ? $this->totalDeLaReserva($reserva) : null;

        if ($total !== null) {
            $lineas[] = '';
            $lineas[] = sprintf(
                '_%s: %s_',
                $this->t('res_total_reserva', $idioma),
                $this->importe($total, $moneda, null)
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
     * Los DATOS para pagar —cuentas, número de Yape, destino del giro— escritos para un mensaje.
     *
     * ── Por qué existe, si la ficha ya los enseña ───────────────────────────────
     * Para la plantilla de políticas de Booking. Ahí las cuentas **tienen que ir en el texto**:
     * ese mensaje viaja por el chat de la OTA y su trabajo es dejar constancia de que se dieron
     * los plazos y los medios, que es lo que permite cancelar la reserva si el prepago no llega.
     * Un enlace no deja esa constancia.
     *
     * ⚠️ **Pero no se escriben a mano en la plantilla, que es como estaban.** El cuerpo viejo de
     * `welcome_booking` llevaba la cuenta de BCP y el número de Yape tecleados, y eso ofrecía a
     * TODO el mundo lo que sólo vale para algunos: a un huésped de Booking desde Europa se le
     * daba una cuenta peruana, con el riesgo de que mandara una transferencia internacional que
     * se come en comisiones buena parte del adelanto.
     *
     * Saliendo del catálogo, el filtro de audiencia se aplica solo: quien no paga desde Perú ve
     * Western Union y tarjeta, y ni se entera de que hay cuentas. Y cuando cambie un número, el
     * mensaje cambia con él.
     *
     * Sólo los `prioritario` — ver {@see \App\Finanzas\Entity\FinMedioCobro::isPrioritario()}.
     *
     * ── La tarjeta ENTRA en la lista, y trae el encabezado (11/09/2026) ─────────
     * Antes se saltaba («no tiene ficha que dar») y el cuerpo de la plantilla la escribía a mano
     * debajo de `{{ medios_de_pago }}`. Eso dejaba dos defectos que el cuerpo no podía arreglar,
     * porque la sustitución de marcadores no tiene condicionales:
     *
     * - con otros medios, el bloque de la tarjeta quedaba pegado a lo último de la lista —la
     *   línea de «¿Necesitas otro banco?»—, como si fuera parte de la transferencia;
     * - sin otros medios, quedaba «Puedes pagarlo por:», dos líneas en blanco y una lista de uno.
     *
     * El read-model ya la trata como un medio más —`PmsSituacionDeCobroResolver` la añade siempre
     * al final, «la opción cara»—, así que aquí se respeta eso en vez de esconderla. Y como lo que
     * se dice cambia según haya o no otros medios, el encabezado viene con ella: es la regla del
     * reparto de §«La invitación y el enlace van en el CUERPO» leída al revés — lo que es igual en
     * todos los casos va en el cuerpo, y lo que se bifurca, aquí.
     *
     * `$enlaceTarjeta` es `account_url`: la tarjeta no tiene número que dar, tiene la ficha del
     * huésped, que es donde vive el enlace de pago vigente. Sin él no se ofrece la tarjeta — un
     * medio sin forma de usarlo es la «media ficha» que no se enseña.
     */
    public function mediosConDatos(PmsReserva $reserva, string $idioma, bool $todas = false, ?string $enlaceTarjeta = null): string
    {
        $situacion = $todas
            ? $this->situaciones->paraHuesped($reserva, soloPrioritarios: false)
            : $this->situacion($reserva);
        $lineas = [];
        $tarjeta = null;

        foreach ($situacion->medios as $medio) {
            if ($medio->fichas === []) {
                // Sin ficha sólo hay uno: la tarjeta. Se aparta para el final, que es donde la
                // pone el read-model y donde tiene que ir — abrir por la opción con recargo
                // empuja a pagar de más a quien podía transferir.
                if ($medio->codigo === PmsMedioPago::TARJETA_CREDITO->value) {
                    $tarjeta = $medio;
                }

                continue;
            }

            $nombre = $this->t('res_medio_' . $medio->codigo, $idioma) ?: $medio->etiqueta;
            $lineas[] = sprintf('▪️ *%s*', $nombre);

            foreach ($medio->fichas as $ficha) {
                // Titular incluido en cada línea y no una vez al pie: aquí no hay un desplegable
                // que agrupe, y en un chat la línea tiene que poder leerse suelta — es la que el
                // huésped copia a su banca.
                $lineas[] = '   ' . implode(' · ', array_filter([
                    $ficha->getBanco(),
                    $ficha->getNumero(),
                    $ficha->getMoneda(),
                    $ficha->getTitular(),
                ]));

                // ⚠️ **Y su nota, que en un caso vale dinero.** La de Western Union es la que
                // dice que el giro va para recojo en tienda y NO a una cuenta bancaria: WU
                // ofrece las dos y ese dinero no lo podemos cobrar. En la ficha del huésped la
                // nota sale dentro de la «i»; aquí, donde no hay «i», tiene que ir escrita o el
                // mensaje ofrece un medio sin la advertencia que lo hace utilizable.
                $nota = trim((string) $ficha->getNotaEn($idioma));

                if ($nota !== '') {
                    $lineas[] = '   _' . $nota . '_';
                }
            }

            // ⚠️ **Que se sepa que hay más, aunque no se listen.** El bloque enseña una o dos
            // cuentas para no ser una sábana, y sin esta línea quien no sea de esos bancos
            // concluye que el suyo no está — y en una disputa con la OTA, un chat con dos
            // cuentas se lee como «información incompleta». Con ella, lo que queda dicho es que
            // se dio todo y que el resto está a una pregunta.
            //
            // Va DEBAJO DE SU MEDIO y con el formato de una nota, no al pie de la lista: habla de
            // bancos, y al pie quedaba entre las cuentas y la tarjeta, leyéndose como parte de lo
            // que venía después.
            if ($medio->hayMasFichas) {
                $lineas[] = '   _' . $this->t('res_mas_cuentas', $idioma) . '_';
            }
        }

        $conTarjeta = $tarjeta !== null && $enlaceTarjeta !== null && $enlaceTarjeta !== '';

        // ── Sólo la tarjeta: una frase, no una lista de uno ──────────────────────────
        //
        // Pasa con quien no paga desde Perú y llega en menos de dos días: las cuentas son para
        // Perú y a Western Union ya no le da tiempo. «Puedes pagarlo por:» con un único punto
        // debajo se lee como una lista a la que le falta algo.
        if ($lineas === []) {
            return $conTarjeta
                ? sprintf(
                    "%s %s\n🔗 %s",
                    $this->t('res_solo_tarjeta', $idioma),
                    $this->t('res_enlace_tarjeta', $idioma),
                    $enlaceTarjeta
                )
                : '';
        }

        if ($conTarjeta) {
            $lineas[] = sprintf('▪️ *%s*', $this->t('res_medio_tarjeta_credito', $idioma));
            $lineas[] = '   ' . $this->t('res_enlace_tarjeta', $idioma);
            $lineas[] = '   🔗 ' . $enlaceTarjeta;
        }

        // «pagarlo»: el antecedente es «el prepago» del cuerpo de `politicas_booking`, la única
        // plantilla que usa esto. Si otra lo adopta con otro antecedente, la frase es de aquí.
        return $this->t('res_puedes_pagar_por', $idioma) . "\n\n" . implode("\n", $lineas);
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
        $situacion = $this->situacion($reserva);

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
