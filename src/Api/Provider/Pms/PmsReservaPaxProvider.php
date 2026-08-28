<?php

declare(strict_types=1);

namespace App\Api\Provider\Pms;

use ApiPlatform\Doctrine\Orm\State\ItemProvider;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Pms\Entity\PmsChannel;
use App\Entity\Maestro\MaestroMoneda;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Service\Finance\PmsTotalesPorMoneda;
use App\Pms\Entity\PmsReserva;
use App\Pms\Finanzas\PmsPrepagoEnlaceService;
use App\Pms\Finanzas\PmsSituacionDeCobro;
use App\Pms\Finanzas\PmsSituacionDeCobroResolver;
use App\Pms\Service\Finance\PmsPrepagoCalculador;
use App\Pms\Service\Finance\TipoCambioDelDia;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Decora el Get público de la reserva (`/client/pax/pms/pms_reserva/{localizador}`)
 * para añadirle el resumen del estado de cuenta que ve el huésped.
 *
 * La relación va de PmsInformacionFinanciera hacia la reserva (1:1 lógico con
 * JoinColumn unique), así que la entidad no puede calcularlo sola: hay que
 * buscar la cabecera aquí. `findOneBy` con el objeto tipa bien el UUID binario
 * — un SearchFilter sobre la relación devolvería vacío en silencio (§12.6 del
 * doc de sync).
 *
 * Solo viaja el AGREGADO (total, pagado, saldo, moneda): el árbol de cargos y
 * pagos es del panel interno. El recargo por tarjeta NO se calcula aquí — es
 * presentación, y el porcentaje vive con su espejo en la vista
 * (PmsReservaView::RECARGO_TARJETA_PCT).
 */
final class PmsReservaPaxProvider implements ProviderInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.item_provider')]
        private readonly ItemProvider $itemProvider,
        private readonly EntityManagerInterface $em,
        private readonly TipoCambioDelDia $tipoCambioDelDia,
        private readonly PmsPrepagoCalculador $prepagoCalculador,
        private readonly PmsPrepagoEnlaceService $prepagoEnlaces,
        private readonly PmsSituacionDeCobroResolver $situacion,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?PmsReserva
    {
        $reserva = $this->itemProvider->provide($operation, $uriVariables, $context);

        if (!$reserva instanceof PmsReserva) {
            return $reserva;
        }

        $finanzas = $this->em->getRepository(PmsInformacionFinanciera::class)
            ->findOneBy(['reserva' => $reserva]);

        // Sin cabecera o sin cargos no hay nada que contar: la tarjeta no se pinta.
        // Con `activa = false` los caches ya solo suman la penalización (§12.7),
        // así que el saldo que sale de aquí sigue siendo el correcto.
        // «¿Hay algo que cobrar?», preguntado al VO y no al escalar convertido en retirada. La
        // respuesta es la misma, pero deja de depender de una columna que va a desaparecer.
        if ($finanzas === null || !PmsTotalesPorMoneda::de($finanzas)->hayCargos()) {
            return $reserva;
        }

        $base = [
            'moneda'  => $finanzas->getMoneda()?->getId() ?? 'USD',
            'simbolo' => $finanzas->getMoneda()?->getSimbolo(),
        ] + $this->referenciaSoles($finanzas);

        $cifras = $this->cifras($finanzas);

        // Enlaces por los que puede pagar AHORA. Van con las cifras y no aparte porque su
        // sitio es la tarjeta de estado de cuenta, junto al saldo que pagan.
        //
        // En `soloProgreso` NO se mandan: esa reserva no enseña un solo importe a propósito
        // (el canal ya cobró), y un botón de «pagar 40.50» ahí es pedirle dinero dos veces
        // al mismo huésped. `pagables()` devuelve vacío con el flag apagado.
        if (($cifras['soloProgreso'] ?? false) !== true) {
            $enlaces = $this->enlacesPagables($reserva);

            if ($enlaces !== []) {
                $cifras['enlacesPago'] = $enlaces;
            }

            // 💡 EL RESUMEN, tal como lo decide la fuente única.
            //
            // Va junto a las cifras y no las sustituye: `total`, `porMoneda` y `lineas` siguen
            // alimentando el DETALLE, que es la misma información desglosada. Esto es lo que
            // se lee de un vistazo — cuánto y por qué medios— y su forma la fija
            // `PmsSituacionDeCobro`, no esta vista.
            //
            // ⚠️ Proyección de HUÉSPED: sin comisión interna ni coste teórico. La decisión es
            // la misma que ve el equipo; lo que cambia es qué campos lleva.
            $cifras['situacion'] = $this->comoResumen($this->situacion->paraHuesped($reserva));
        }

        $reserva->setResumenFinancieroCliente($base + $cifras);

        return $reserva;
    }

    /**
     * Enlaces de pago vigentes de esta reserva, en la forma mínima que necesita la app.
     *
     * ⚠️ Viaja el **token**, que es la credencial de la página de pago. Es correcto aquí y
     * no en cualquier sitio: este endpoint ya está acotado al localizador, que es la misma
     * llave con la que el huésped ve su reserva entera. Quien pueda leer esto podía ya ver
     * el saldo; lo único que suma el token es poder PAGARLO, que no es un daño.
     *
     * No viaja nada del emisor ni del origen: la app arma la URL como `/pago/{token}` sobre
     * su propio router, igual que hace el enlace que llega por WhatsApp.
     *
     * @return list<array<string, mixed>>
     */
    private function enlacesPagables(PmsReserva $reserva): array
    {
        $filas = [];

        foreach ($this->prepagoEnlaces->pagables($reserva) as $enlace) {
            $filas[] = [
                'token' => $enlace->getToken(),
                'concepto' => $enlace->getConcepto(),
                // El NETO es lo que abona la reserva; el TOTAL es lo que se le pasa a la
                // tarjeta. Van los dos porque el huésped tiene que poder cuadrar su extracto
                // con su saldo, y no coinciden (§6).
                'montoNeto' => $enlace->getMontoNeto(),
                'montoTotal' => $enlace->getMontoTotal(),
                'recargoPorcentaje' => $enlace->getRecargoPorcentaje(),
                'moneda' => $enlace->getMonedaCodigo(),
                'simbolo' => $enlace->getMonedaSimbolo(),
                'expiraEn' => $enlace->getExpiraEn()?->format(DATE_ATOM),
            ];
        }

        return $filas;
    }

    /**
     * Total, pagado y saldo tal como los ve el HUÉSPED.
     *
     * En los canales que cobran por nosotros (Airbnb, VRBO) no se pueden enseñar
     * los importes del canal: lo que guardamos es lo que la OTA nos remite, no lo
     * que el huésped pagó —que incluye la comisión de servicio de la OTA—, y la
     * diferencia genera una conversación que nadie quiere tener.
     *
     * La exclusión va por MARCA EXPLÍCITA, no por heurística: `esAutomatico` en el
     * cargo (lo pone Beds24InvoiceReceivePersister) y en el pago (lo pone
     * PmsPagoOtaAutomaticoService). Emparejar "el cargo de subtipo 8 con el pago
     * del mismo importe" se rompía de dos formas: el Cancel Fee de Beds24 llega
     * con el MISMO subtipo 8 que el alojamiento (ver PmsTipoCargo::desdeBeds24),
     * así que habría escondido las penalizaciones; y dos cargos del mismo importe
     * confunden el emparejamiento.
     *
     * Resultado: un huésped de Airbnb sin extras ve `soloProgreso` y la barra al
     * 100 %, sin una sola cifra. Con extras ve SOLO esos, que son los que nos debe
     * a nosotros y sí puede reconocer.
     *
     * @return array<string, mixed>
     */
    private function cifras(PmsInformacionFinanciera $finanzas): array
    {
        $canal = $finanzas->getReserva()?->getChannel()?->getId();
        $espejo = $canal !== null && in_array($canal, PmsChannel::CANAL_PAGO_TOTAL, true);

        // El desglose por tipo lo calcula la cabecera: ahí viven las tres reglas
        // (anulación §12.7, `esCargo()`, `totalLinea ?? monto`) y duplicarlas aquí
        // era garantía de que se separasen con el tiempo.
        $cargos = $finanzas->getDesglosePorTipo(excluirEspejoCanal: $espejo);
        $pagos  = $this->pagosVisibles($finanzas, $espejo);

        $total  = array_sum(array_map('floatval', $cargos));
        $pagado = array_sum(array_map(static fn (array $p): float => (float) $p['monto'], $pagos));

        // Todo lo que había era el espejo del canal: no queda nada que enseñar.
        // La barra llena es puro acuse de recibo ("está cobrado"), sin importes.
        if ($espejo && $total <= 0.0) {
            return ['soloProgreso' => true];
        }

        // 💱 POR MONEDA, sin convertir. El huésped que pagó S/ 223.70 por Yape tiene que ver
        // S/ 223.70 — no «US$ 65.97», que es una cifra que no reconoce de ningún recibo suyo.
        //
        // En canal espejo se conservan las sumas locales: ahí lo que se enseña son SÓLO los
        // extras, y ésos ya vienen filtrados de `$cargos`/`$pagos`.
        $totales = PmsTotalesPorMoneda::de($finanzas);

        return [
            // El CUADRE, para la barra de progreso y el titular «cuánto falta»: son preguntas que
            // sólo admiten una respuesta. Va marcado con `mixta` para que la tarjeta escriba `≈`
            // y no lo presente como exacto.
            'total'  => $espejo ? number_format($total, 2, '.', '')  : $this->totalDelCuadre($totales, 'cargos'),
            'pagado' => $espejo ? number_format($pagado, 2, '.', '') : $this->totalDelCuadre($totales, 'pagos'),
            'saldo'  => $espejo ? number_format($total - $pagado, 2, '.', '') : $totales->cuadre,
            'monedaCuadre' => $totales->monedaCuadre,
            'mixta' => !$espejo && $totales->esMixta(),
            // La verdad, para el detalle: una fila por moneda con lo suyo.
            'porMoneda' => $espejo ? [] : $this->desglosePorMoneda($totales),
            'cargos' => $cargos,
            // Detalle línea a línea, con la descripción redactada para el huésped. Es lo que
            // pinta la tarjeta; `cargos` (agrupado por tipo) se mantiene porque sigue siendo
            // el resumen barato para cualquier otro consumidor.
            'lineas' => $finanzas->getLineasCliente(excluirEspejoCanal: $espejo),
            'pagos'  => $pagos,

            // Prepago pendiente. La regla «si ya pagó algo, ese pago ERA el prepago» está
            // dentro de `pendiente()`, no aquí: la comparten el panel y la skill
            // `consultar_cuenta` del agente, y escrita tres veces se separa a la primera.
            'prepago' => $this->prepagoCalculador->pendiente($finanzas),
        ];
    }

    /**
     * La situación de cobro en la forma mínima que necesita la app.
     *
     * ⚠️ **No se serializa el objeto entero.** `PmsSituacionDeCobro` lleva las fichas de cada
     * medio —titular, banco, número, CCI— y eso es del DETALLE, no del resumen: volcarlo aquí
     * pondría cuentas bancarias en la primera pantalla de todo el mundo. Se toma lo que el
     * resumen pinta y nada más.
     *
     * El `motivo` viaja como su `name` —un identificador, no una frase— y lo traduce la app:
     * el read-model devuelve hechos y el texto es de quien rinde.
     *
     * @return array<string, mixed>
     */
    private function comoResumen(PmsSituacionDeCobro $situacion): array
    {
        return [
            'queSePide' => $situacion->queSePide->name,
            'motivo' => $situacion->motivo?->name,
            'hayAlgoQuePedir' => $situacion->hayAlgoQuePedir(),
            'importes' => array_map(static fn ($i): array => [
                'moneda' => $i->moneda,
                'simbolo' => $i->simbolo,
                'importe' => $i->importe,
                'enSoles' => $i->enSoles,
            ], $situacion->importes),
            'medios' => array_map(static fn ($m): array => [
                'codigo' => $m->codigo,
                'etiqueta' => $m->etiqueta,
                'importe' => $m->importe,
                'enSoles' => $m->enSoles,
                'recargoPorcentaje' => $m->llevaRecargo() ? $m->recargoPorcentaje : null,
            ], $situacion->medios),
        ];
    }

    /**
     * Los cargos o los cobros de todas las monedas, llevados a la de cuadre.
     *
     * Sólo alimenta la barra de progreso y el titular. El detalle que el huésped lee va sin
     * convertir en `porMoneda`.
     */
    private function totalDelCuadre(PmsTotalesPorMoneda $totales, string $campo): string
    {
        $tc = (float) ($totales->tipoCambio ?? 0);
        $suma = 0.0;

        foreach ($totales->porMoneda as $moneda => $cifras) {
            $valor = (float) $cifras[$campo];

            if ($moneda === $totales->monedaCuadre) {
                $suma += $valor;

                continue;
            }

            // Sin tipo de cambio no se inventa nada: esa moneda se queda fuera de la barra. El
            // detalle de `porMoneda` la sigue enseñando entera, que es lo que importa.
            if ($tc <= 0.0) {
                continue;
            }

            $suma += match (true) {
                $moneda === 'USD' && $totales->monedaCuadre === 'PEN' => $valor * $tc,
                $moneda === 'PEN' && $totales->monedaCuadre === 'USD' => $valor / $tc,
                default => $valor,
            };
        }

        return number_format($suma, 2, '.', '');
    }

    /**
     * Una fila por moneda, con su símbolo, para que la tarjeta la pinte tal cual.
     *
     * @return list<array{moneda: string, simbolo: string|null, cargos: string, pagado: string, saldo: string}>
     */
    private function desglosePorMoneda(PmsTotalesPorMoneda $totales): array
    {
        $salida = [];

        foreach ($totales->porMoneda as $moneda => $cifras) {
            $entidad = $this->em->find(MaestroMoneda::class, $moneda);

            $salida[] = [
                'moneda' => $moneda,
                'simbolo' => $entidad?->getSimbolo(),
                'cargos' => $cifras['cargos'],
                'pagado' => $cifras['pagos'],
                'saldo' => $cifras['saldo'],
            ];
        }

        return $salida;
    }

    /**
     * Equivalencia REFERENCIAL en soles, para el conmutador de la tarjeta.
     *
     * Se manda un único tipo de cambio —el del día— para toda la tarjeta, y no el que cada
     * cargo tiene congelado. Con los TC históricos las líneas no sumarían el total
     * convertido, y esa descuadre es justo la conversación que la tarjeta quiere evitar.
     *
     * Por eso es **referencial y hay que decirlo en pantalla**: no es lo que se cobró ni lo
     * que se va a cobrar, es «cuánto viene siendo hoy». El cobro real sigue siendo en la
     * moneda de la cabecera.
     *
     * Si no hay TC del día o la cabecera ya está en soles, no se manda nada y el front
     * sencillamente no pinta el conmutador.
     *
     * @return array{tipoCambioReferencial?: string, monedaReferencial?: string, simboloReferencial?: string}
     */
    private function referenciaSoles(PmsInformacionFinanciera $finanzas): array
    {
        if (($finanzas->getMoneda()?->getId() ?? 'USD') === 'PEN') {
            return [];
        }

        // ⚠️ Con DOS monedas no se ofrece: convertir una de las dos a soles —dejando la otra
        // como está, o convirtiéndola también con un tipo que no es el suyo— produce una tarjeta
        // que no cuadra consigo misma. Y el huésped ya tiene delante lo que pagó en cada una,
        // que es justo lo que el conmutador venía a resolver cuando sólo había una.
        if (PmsTotalesPorMoneda::de($finanzas)->esMixta()) {
            return [];
        }

        $tc = $this->tipoCambioDelDia->venta();

        if ($tc === null || (float) $tc <= 0) {
            return [];
        }

        return [
            'tipoCambioReferencial' => $tc,
            'monedaReferencial' => 'PEN',
            'simboloReferencial' => 'S/.',
        ];
    }

    /**
     * Pagos que el huésped puede ver, del más antiguo al más reciente.
     *
     * Viaja el MEDIO (valor del enum, traducible en el front) y la FECHA, no las
     * notas ni la referencia: son campos libres del operador, en español y con
     * datos internos. Tampoco el `montoTotalCobrado`, que incluye la comisión de
     * tarjeta y no es lo que el huésped entregó.
     *
     * @return list<array{fecha: string|null, medio: string, monto: string}>
     */
    private function pagosVisibles(PmsInformacionFinanciera $finanzas, bool $excluirEspejoCanal): array
    {
        $pagos = [];

        foreach ($finanzas->getPagos() as $pago) {
            if ($excluirEspejoCanal && $pago->isEsAutomatico()) {
                continue;
            }

            $pagos[] = [
                'fecha' => $pago->getFechaPago()?->format('Y-m-d'),
                'medio' => $pago->getMedioPago()->value,
                // Convertido a la moneda de la cabecera, como el `total_pagos`
                // que se muestra debajo. Ver PmsInformacionFinanciera::aMonedaBase().
                'monto' => $finanzas->convertirAMonedaBase(
                    (float) $pago->getMonto(),
                    $pago->getMoneda()?->getId(),
                    $pago->getTipoCambio(),
                ),
            ];
        }

        usort($pagos, static fn (array $a, array $b): int => ($a['fecha'] ?? '') <=> ($b['fecha'] ?? ''));

        return $pagos;
    }
}
