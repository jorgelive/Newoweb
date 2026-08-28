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
    /**
     * Símbolos de moneda ya resueltos, por código ISO. `null` = el maestro no la tiene.
     *
     * @var array<string, string|null>
     */
    private array $simbolos = [];

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
            // Agrupados POR IMPORTE, no por medio: seis líneas diciendo dos cifras es el
            // mismo abrumamiento que el resumen viene a evitar. Ver `mediosPorImporte()`.
            //
            // Y con las FICHAS de cada uno —titular, banco, número, CCI—, que la app enseña
            // detrás de un clic. Estuvieron excluidas mientras la idea era pintarlas: volcarlas
            // en la primera pantalla de todo el mundo es otra cosa. Tras una «i» que hay que
            // pulsar, son lo que el huésped necesita para ejecutar el pago que ya eligió.
            //
            // ⚠️ Viajan en el payload aunque nadie pulse, y es una decisión: esta vista se abre
            // con el localizador, así que están al alcance de quien tenga el enlace. Son
            // cuentas para RECIBIR dinero, no credenciales — el mismo criterio por el que la
            // guía del huésped ya las publica.
            'medios' => $this->conFichas($situacion),
        ];
    }

    /**
     * Los grupos de importe, con la ficha de cada medio para el desplegable de la app.
     *
     * La ficha se toma del objeto de dominio (`PmsSituacionDeCobro::$medios`) y se cruza por
     * código con los grupos, que es donde ya está resuelto el «qué cuesta cada cosa».
     *
     * ⚠️ Se serializa **campo a campo y no la entidad**: `FinMedioCobro` lleva además
     * audiencia, días y orden, que son reglas nuestras y no le dicen nada al huésped. Lo que
     * viaja es lo que hace falta para pagar.
     *
     * @return list<array<string, mixed>>
     */
    private function conFichas(PmsSituacionDeCobro $situacion): array
    {
        /** @var array<string, array<string, mixed>> $porCodigo */
        $porCodigo = [];

        foreach ($situacion->medios as $medio) {
            foreach ($medio->fichas as $ficha) {
                $datos = array_filter([
                    'titular' => $ficha->getTitular(),
                    'titularAlterno' => $this->alternoSiAporta($ficha->getTitular(), $ficha->getTitularAlterno()),
                    'banco' => $ficha->getBanco(),
                    'numero' => $ficha->getNumero(),
                    'cci' => $ficha->getCci(),
                    // El SÍMBOLO, no el código ISO. «BCP PEN» pide traducir mentalmente en
                    // la única pantalla donde el huésped compara cuentas para elegir la suya;
                    // «BCP S/.» es lo que lee en su propia banca. Sale de `MaestroMoneda`,
                    // que es de donde ya salen todas las demás cifras de esta tarjeta —el
                    // conmutador, los importes, el cuadre—: la ficha era la única que mandaba
                    // el código crudo.
                    'moneda' => $this->simboloMoneda($ficha->getMoneda()),
                    // El ARRAY i18n entero, y lo traduce el cliente — mismo trato que
                    // `PmsGuiaHuespedProvider::mediosPago()` le da a esta misma nota.
                    //
                    // ⚠️ Resolverlo aquí con `getNotaEn($reserva->getIdioma())` parece más
                    // limpio y **da el idioma equivocado**: ese campo es el que dedujimos al
                    // crear la reserva, y la tarjeta se pinta en el que el huésped eligió en
                    // el selector. Hay reservas con `idioma = en` que se están leyendo en
                    // castellano; con la nota resuelta en servidor, esa tarjeta saldría en
                    // español con un párrafo en inglés dentro.
                    //
                    // ⚠️ Y NO vale `getNotaEsVisual()`: es un getter señuelo para que
                    // EasyAdmin encuentre la propiedad en el listado y **siempre devuelve
                    // cadena vacía** —quien pinta la celda es el `formatValue()` del CRUD—.
                    // Usarlo filtraba la nota entera sin dar un solo error: el huésped de
                    // Western Union veía un nombre y una ciudad, y ninguna instrucción.
                    'nota' => $ficha->getNota() !== [] ? $ficha->getNota() : null,
                ], static fn ($v): bool => $v !== null && $v !== '');

                // ⚠️ Una ficha SIN NINGÚN campo no viaja. Existe —«efectivo» tiene la suya en
                // el catálogo, para llevar audiencia y ventana de días— pero no tiene nada que
                // enseñar: se paga en recepción, no hay número que copiar. Si viajara, la app
                // le pintaría su «i» y el huésped abriría un cuadro en blanco, que es peor que
                // no tener icono: enseña que los iconos de esta pantalla no llevan a nada.
                if ($datos === []) {
                    continue;
                }

                $porCodigo[$medio->codigo][] = $datos;
            }
        }

        return array_map(
            static function (array $grupo) use ($porCodigo): array {
                $grupo['fichas'] = [];

                foreach ($grupo['codigos'] as $codigo) {
                    $grupo['fichas'][$codigo] = $porCodigo[$codigo] ?? [];
                }

                return $grupo;
            },
            $situacion->mediosPorImporte(),
        );
    }

    /**
     * El símbolo de una moneda por su código ISO, con caída al propio código.
     *
     * `FinMedioCobro::$moneda` es una columna de texto suelta, no una relación: el catálogo de
     * cobro se tecleó antes de que existiera `MaestroMoneda` y guarda «PEN» a pelo. La caída
     * cubre que alguien teclee una moneda que el maestro no tenga —mejor «CLP» que un hueco
     * donde debería decir en qué moneda es la cuenta.
     *
     * Se cachea en memoria porque esto se pregunta una vez por ficha y hay ocho sólo en las
     * transferencias.
     */
    private function simboloMoneda(?string $codigo): ?string
    {
        if ($codigo === null || $codigo === '') {
            return null;
        }

        if (!array_key_exists($codigo, $this->simbolos)) {
            $this->simbolos[$codigo] = $this->em->getRepository(MaestroMoneda::class)
                ->find($codigo)?->getSimbolo();
        }

        return $this->simbolos[$codigo] ?? $codigo;
    }

    /**
     * El titular alterno, sólo cuando dice algo que el principal no dice.
     *
     * Casi siempre es el MISMO nombre sin tildes —«Susan Acuña Romero» / «Susan Acuna
     * Romero»— porque hay bancos que no las aceptan en el campo de destinatario. Al huésped
     * eso no le sirve: le enseña dos nombres casi iguales y le hace dudar de cuál copiar,
     * cuando la app de su banco va a aceptar cualquiera de los dos.
     *
     * Se compara sin tildes ni mayúsculas. Si de verdad son dos personas distintas —una
     * cuenta a nombre de otro titular—, el alterno sale.
     */
    private function alternoSiAporta(?string $titular, ?string $alterno): ?string
    {
        if ($alterno === null || $alterno === '' || $titular === null) {
            return $alterno;
        }

        $normalizar = static fn (string $v): string => mb_strtolower(
            (string) preg_replace('/[^a-z ]/i', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT', $v))
        );

        return $normalizar($titular) === $normalizar($alterno) ? null : $alterno;
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
