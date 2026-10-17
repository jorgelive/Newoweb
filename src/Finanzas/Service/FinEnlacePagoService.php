<?php

declare(strict_types=1);

namespace App\Finanzas\Service;

use App\Entity\Maestro\MaestroMoneda;
use App\Entity\User;
use App\Finanzas\Service\Aviso\FinAvisoDeCobro;
use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Enum\FinEnlacePagoEstado;
use App\Finanzas\Enum\FinOrigenCobro;
use App\Finanzas\Enum\FinPasarela;
use App\Finanzas\Repository\FinEnlacePagoRepository;
use DateTimeImmutable;
use DomainException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use App\Finanzas\Dto\TransaccionDePasarela;

/**
 * Emisión y cierre de enlaces de pago. Es el único sitio que cambia su estado.
 *
 * El servicio no conoce ningún módulo de negocio: todo lo que necesita saber del documento
 * que se cobra se lo da `FinOrigenCobroRegistry`.
 */
final class FinEnlacePagoService
{
    /** Días de vigencia si el operador no dice otra cosa. */
    private const VIGENCIA_DIAS_DEFECTO = 7;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FinEnlacePagoRepository $repository,
        private readonly FinOrigenCobroRegistry $registry,
        private readonly FinPasarelaRegistry $pasarelas,
        private readonly LoggerInterface $logger,
        // Quién le cuenta al equipo que entró dinero. No lanza: ver `confirmarPago()`.
        private readonly FinAvisoDeCobro $avisoDeCobro,
        #[Autowire('%pax_host_url%')]
        private readonly string $paxHostUrl,
        #[Autowire('%finanzas.recargo_tarjeta_porcentaje%')]
        private readonly string $recargoTarjetaPorcentaje,
        /** @var array<string, string> Tope antifraude por divisa: ver `services_finanzas.yaml`. */
        #[Autowire('%finanzas.limite_por_cargo%')]
        private readonly array $limitePorCargo,
    ) {}

    /**
     * Emite un enlace de pago contra un documento de cualquier módulo.
     *
     * @param string|null $montoNeto  Importe a cobrar SIN recargo. Null = el saldo pendiente completo.
     * @param bool        $conRecargo Si se le traslada al cliente el coste de la pasarela.
     * @param int|null    $vigenciaDias Null usa el defecto; 0 emite un enlace sin caducidad.
     * @param FinPasarela|null $pasarela Null usa la del registry. Se CONGELA en la fila: las
     *                                   pasarelas conviven y el enlace tiene que recordar
     *                                   por cuál se emitió aunque cambie el defecto.
     *
     * @throws DomainException si el documento no existe o el importe no tiene sentido.
     */
    public function crear(
        FinOrigenCobro $origenTipo,
        Uuid $origenId,
        ?string $montoNeto = null,
        bool $conRecargo = true,
        ?int $vigenciaDias = null,
        ?string $concepto = null,
        ?User $creadoPor = null,
        ?FinPasarela $pasarela = null,
        // En qué moneda se cobra. `null` = la de mayor saldo, que es lo que respondía antes de
        // que un documento pudiera deber en dos. Una pasarela cobra un enlace en UNA divisa.
        // ⚠️ Y desde el 10/10/2026 un documento tiene UN enlace vivo: con deuda en dos monedas se
        // cobra una y, pagada, la otra. Ver `construir()`.
        ?string $moneda = null,
    ): FinEnlacePago {
        $origen = $this->registry->resolver($origenTipo, $origenId, $moneda);

        if ($origen === null) {
            throw new DomainException('El documento que se quiere cobrar ya no existe.');
        }

        // 🔒 Una PERSONA no puede pedir más de lo que se debe (10/10/2026). Hasta hoy sólo se
        // comprobaba que fuera mayor que cero: BGD96K acabó saldada con un enlace manual de
        // 83.31 todavía pagable, porque nació cuando ya había otro enlace pidiendo el adelanto.
        // Es la protección que no depende de que nadie lea un aviso, y por eso va aquí, por donde
        // pasan el panel y el agente.
        //
        // Sólo con autor: el adelanto automático va en la moneda de la CABECERA aunque la deuda
        // esté en otra —lo convierte el calculador—, y ése lo decide la regla, no una persona.
        $pedido = $montoNeto ?? $origen->saldoPendiente;
        if ($creadoPor !== null && (float) $pedido > (float) $origen->saldoPendiente + 0.005) {
            throw new DomainException(sprintf(
                'El huésped debe %s %s y este enlace pediría %s. No se puede pedir más de lo que se debe: '
                . 'si hay algo más que cobrar, regístralo antes como cargo.',
                $origen->moneda,
                $origen->saldoPendiente,
                $pedido,
            ));
        }

        return $this->construir(
            origenTipo: $origenTipo,
            origenId: $origenId,
            montoNeto: $montoNeto ?? $origen->saldoPendiente,
            monedaId: $origen->moneda,
            concepto: $concepto ?: $origen->descripcion,
            referencia: $origen->referencia,
            clienteNombre: $origen->clienteNombre,
            clienteApellido: $origen->clienteApellido,
            clienteEmail: $origen->clienteEmail,
            clienteTelefono: $origen->clienteTelefono,
            conRecargo: $conRecargo,
            vigenciaDias: $vigenciaDias,
            creadoPor: $creadoPor,
            pasarela: $pasarela,
        );
    }

    /**
     * Como {@see self::crear()}, pero si el cobro no cabe en el tope de la pasarela lo PARTE en el
     * mínimo de enlaces que quepan, y todos quedan vivos a la vez (16/10/2026).
     *
     * ── La excepción a «un documento, un enlace vivo» ───────────────────────────
     * Esa regla existe para que el cliente no tenga delante dos cobros DISTINTOS y pague el que no
     * toca o los dos. Los tramos de un mismo cobro no son eso: son partes, y pagarlas todas es
     * justo lo correcto («parte 1 de 2», «parte 2 de 2»). Por eso conviven entre sí —el primero
     * anula lo que hubiera vivo, los demás no—, y un cobro NUEVO sigue anulándolos a todos.
     *
     * Culqi admite como mucho 3000 USD por operación, comisión incluida: el adelanto de un viaje de
     * grupo no cabía en un enlace y el operador no tenía forma de emitir dos (Jorge).
     *
     * El reparto es en céntimos exactos: los tramos suman el neto y los primeros llevan el céntimo
     * que sobre. Si el cobro cabe, devuelve un solo enlace, idéntico al de `crear()`.
     *
     * @return non-empty-list<FinEnlacePago> Ordenados: parte 1, parte 2…
     *
     * @throws DomainException como `crear()`.
     */
    public function crearEnTramos(
        FinOrigenCobro $origenTipo,
        Uuid $origenId,
        ?string $montoNeto = null,
        bool $conRecargo = true,
        ?int $vigenciaDias = null,
        ?string $concepto = null,
        ?User $creadoPor = null,
        ?FinPasarela $pasarela = null,
        ?string $moneda = null,
    ): array {
        $origen = $this->registry->resolver($origenTipo, $origenId, $moneda);

        if ($origen === null) {
            throw new DomainException('El documento que se quiere cobrar ya no existe.');
        }

        $neto = $this->normalizarImporte($montoNeto ?? $origen->saldoPendiente);
        $partes = $this->tramos($neto, $origen->moneda, $conRecargo);

        if (\count($partes) === 1) {
            return [$this->crear($origenTipo, $origenId, $neto, $conRecargo, $vigenciaDias, $concepto, $creadoPor, $pasarela, $moneda)];
        }

        // La misma protección que `crear()`: una persona no puede pedir más de lo que se debe.
        if ($creadoPor !== null && (float) $neto > (float) $origen->saldoPendiente + 0.005) {
            throw new DomainException(sprintf(
                'Se deben %s %s y estos enlaces pedirían %s. No se puede pedir más de lo que se debe.',
                $origen->moneda,
                $origen->saldoPendiente,
                $neto,
            ));
        }

        $base = $concepto ?: $origen->descripcion;
        $enlaces = [];

        // 1. Construir y validar TODOS antes de tocar nada: un tramo que no puede existir no puede
        //    llevarse por delante los enlaces vivos (misma regla que `construir()`).
        foreach ($partes as $i => $parte) {
            $enlaces[] = $this->construir(
                origenTipo: $origenTipo,
                origenId: $origenId,
                montoNeto: $parte,
                monedaId: $origen->moneda,
                concepto: sprintf('%s · parte %d de %d', $base, $i + 1, \count($partes)),
                referencia: $origen->referencia,
                clienteNombre: $origen->clienteNombre,
                clienteApellido: $origen->clienteApellido,
                clienteEmail: $origen->clienteEmail,
                clienteTelefono: $origen->clienteTelefono,
                conRecargo: $conRecargo,
                vigenciaDias: $vigenciaDias,
                creadoPor: $creadoPor,
                pasarela: $pasarela,
                persistir: false,
            );
        }

        // 2. Anular lo vivo y guardar los N tramos JUNTOS: una transacción, un candado, un flush.
        //    Por partes, un fallo en el segundo tramo dejaba los anteriores anulados y el cliente con
        //    medio cobro (revisión del 16/10/2026).
        if (!$this->tomarDocumento($origenId)) {
            throw new DomainException('Se está emitiendo otro enlace para este documento. Inténtalo en unos segundos.');
        }

        try {
            $this->em->wrapInTransaction(function () use ($origenTipo, $origenId, $enlaces): void {
                foreach ($this->repository->porOrigen($origenTipo, $origenId) as $vivo) {
                    if ($vivo->estaVigente()) {
                        $vivo->setEstado(FinEnlacePagoEstado::ANULADO);
                    }
                }

                foreach ($enlaces as $enlace) {
                    $this->em->persist($enlace);
                }

                $this->em->flush();
            });
        } finally {
            $this->soltarDocumento($origenId);
        }

        return $enlaces;
    }

    /**
     * Qué tramo es un enlace —`[parte, partes]`— leyendo el concepto que le puso `crearEnTramos()`
     * («… · parte 2 de 3»), o `null` si no es un tramo.
     *
     * Se lee del enlace y no se cuenta lo que queda vivo: pagada la parte 1, contar los vivos
     * renombraba la parte 2 como «única» y quitaba el aviso de que había más (revisión del
     * 16/10/2026). El concepto lo escribe este servicio, así que el formato es suyo.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function tramoDe(FinEnlacePago $enlace): ?array
    {
        if (preg_match('/ · parte (\d+) de (\d+)$/u', $enlace->getConcepto(), $m) !== 1) {
            return null;
        }

        return [(int) $m[1], (int) $m[2]];
    }

    /** @return non-empty-list<string> */
    private function tramos(string $neto, string $monedaId, bool $conRecargo): array
    {
        return self::partirEnTramos(
            $neto,
            $this->limitePorCargo[$monedaId] ?? null,
            $conRecargo ? (float) $this->recargoTarjetaPorcentaje : 0.0,
        );
    }

    /**
     * El neto partido en el mínimo de tramos cuyo TOTAL (con comisión) quepa en el tope. Sin tope
     * para esa moneda, un solo tramo: el límite es del proveedor, no nuestro.
     *
     * Céntimos exactos: los tramos suman el neto y los primeros llevan el céntimo que sobra. Se
     * comprueba con el redondeo de verdad —el total de cada enlace se normaliza a céntimos—, porque
     * un céntimo de más lo dejaría fuera del tope. Pura y estática para poder probarla sola.
     *
     * @return non-empty-list<string>
     */
    public static function partirEnTramos(string $neto, string|int|float|null $tope, float $recargoPorcentaje): array
    {
        $dos = static fn (float $v): string => number_format($v, 2, '.', '');
        $factor = 1 + $recargoPorcentaje / 100;
        $centimos = (int) round((float) $neto * 100);

        if ($tope === null || $centimos <= 0 || (float) $dos($centimos / 100 * $factor) <= (float) $tope) {
            return [$dos($centimos / 100)];
        }

        for ($n = max(2, (int) ceil($centimos / 100 * $factor / (float) $tope)); ; ++$n) {
            $cada = intdiv($centimos, $n);
            $sobra = $centimos - $cada * $n;
            $mayor = ($cada + ($sobra > 0 ? 1 : 0)) / 100;

            if ((float) $dos($mayor * $factor) <= (float) $tope) {
                $parte = static fn (int $i): string => $dos(($cada + ($i < $sobra ? 1 : 0)) / 100);
                $partes = [$parte(0)];
                for ($i = 1; $i < $n; ++$i) {
                    $partes[] = $parte($i);
                }

                return $partes;
            }
        }
    }

    /**
     * Construcción común a los dos caminos.
     *
     * Lo único que cambia entre un cobro contra documento y uno manual es **de dónde salen
     * los datos**: del resolver o del formulario. Todo lo demás —token, recargo congelado,
     * `orderId`, vigencia, validación de pasarela— es idéntico, y tenerlo en un solo sitio
     * evita que el camino manual se quede sin alguna de esas garantías al evolucionar.
     */
    private function construir(
        ?FinOrigenCobro $origenTipo,
        ?Uuid $origenId,
        string $montoNeto,
        string $monedaId,
        string $concepto,
        ?string $referencia,
        ?string $clienteNombre,
        ?string $clienteApellido,
        ?string $clienteEmail,
        ?string $clienteTelefono,
        bool $conRecargo,
        ?int $vigenciaDias,
        ?User $creadoPor,
        ?FinPasarela $pasarela,
        // `false` = construir y validar SIN guardar: `crearEnTramos()` valida todos los tramos y
        // después anula y guarda todo de una vez, en una transacción y con un solo candado.
        bool $persistir = true,
    ): FinEnlacePago {
        $neto = $this->normalizarImporte($montoNeto);

        if ((float) $neto <= 0) {
            throw new DomainException('No hay nada que cobrar: el importe debe ser mayor que cero.');
        }

        // `find` y no `getReference`: con una referencia, una moneda inexistente no falla
        // aquí sino en el flush, como un error de foreign key que no dice nada del problema.
        $moneda = $this->em->find(MaestroMoneda::class, $monedaId);

        if ($moneda === null) {
            throw new DomainException(sprintf('La moneda "%s" no está en el maestro.', $monedaId));
        }

        // El porcentaje se congela en la fila: si mañana la pasarela sube su comisión, los
        // enlaces ya enviados tienen que seguir cobrando lo que prometieron.
        $porcentaje = $conRecargo ? $this->recargoTarjetaPorcentaje : '0';
        $total = $this->normalizarImporte((string) ((float) $neto * (1 + (float) $porcentaje / 100)));

        // `para()` valida que tenga credenciales: mejor fallar aquí, al emitir, que dejar un
        // enlace que revienta cuando el cliente ya lo abrió.
        $pasarelaElegida = $pasarela ?? $this->pasarelas->porDefecto();
        $this->pasarelas->para($pasarelaElegida);

        $this->comprobarTope($total, $monedaId);

        $enlace = new FinEnlacePago();
        $enlace
            ->setToken($this->generarToken())
            ->setPasarela($pasarelaElegida)
            ->setOrigenTipo($origenTipo)
            ->setOrigenId($origenId)
            ->setMoneda($moneda)
            ->setMontoNeto($neto)
            ->setRecargoPorcentaje($this->normalizarImporte($porcentaje))
            ->setMontoTotal($total)
            ->setConcepto(substr($concepto, 0, 255))
            ->setOrigenReferencia($referencia)
            ->setClienteNombre($clienteNombre)
            ->setClienteApellido($clienteApellido)
            ->setClienteEmail($clienteEmail)
            ->setClienteTelefono($clienteTelefono)
            ->setCreadoPor($creadoPor)
            ->setExpiraEn($this->calcularExpiracion($vigenciaDias));

        $enlace->setOrdenId($this->generarOrdenId($enlace));

        if (!$persistir) {
            return $enlace;
        }

        if ($origenTipo === null || $origenId === null) {
            $this->em->persist($enlace);
            $this->em->flush();

            return $enlace;
        }

        // 🔒 UN DOCUMENTO, UN ENLACE VIVO (10/10/2026). «En ningún caso deben estar dos enlaces»
        // (Jorge): el huésped que ve dos pagables por importes distintos puede pagar el que no
        // toca, o los dos. Se impone aquí, que es por donde pasan TODOS —el panel, la skill, el
        // emisor automático—, en vez de confiar en que cada camino se acuerde de anular.
        //
        // Va DESPUÉS de las validaciones —credenciales, tope—: un enlace nuevo que no puede
        // existir no puede llevarse por delante al que había. Y con el documento bloqueado,
        // porque anular-y-crear desde dos peticiones a la vez dejaría dos vivos igual.
        if (!$this->tomarDocumento($origenId)) {
            throw new DomainException('Se está emitiendo otro enlace para este documento. Inténtalo en unos segundos.');
        }

        try {
            foreach ($this->repository->porOrigen($origenTipo, $origenId) as $vivo) {
                // Se anula, no se borra: el enlace que se mandó existió.
                if ($vivo->estaVigente()) {
                    $vivo->setEstado(FinEnlacePagoEstado::ANULADO);
                }
            }

            $this->em->persist($enlace);
            $this->em->flush();
        } finally {
            $this->soltarDocumento($origenId);
        }

        return $enlace;
    }

    /**
     * El turno sobre un documento mientras se le cambia el enlace vivo.
     *
     * `GET_LOCK` de sesión, como el turno del prepago (`PmsPrepagoEnlaceService::tomarElTurno()`),
     * y con la base en el nombre por lo mismo: su espacio de nombres es el servidor entero. Es
     * otro nombre que aquél, así que el emisor automático puede tener los dos a la vez.
     */
    private function tomarDocumento(Uuid $origenId): bool
    {
        /** @var int|string|null $resultado 1 = turno ganado, 0 = ocupado, NULL = error interno. */
        $resultado = $this->em->getConnection()->fetchOne('SELECT GET_LOCK(?, 5)', [$this->nombreDelTurno($origenId)]);

        return $resultado !== null && (int) $resultado === 1;
    }

    private function soltarDocumento(Uuid $origenId): void
    {
        try {
            $this->em->getConnection()->executeStatement('SELECT RELEASE_LOCK(?)', [$this->nombreDelTurno($origenId)]);
        } catch (\Throwable $e) {
            // MySQL lo suelta al cerrar la sesión; que no se pueda aquí no tumba el cobro.
            $this->logger->warning('[finanzas] no se pudo soltar el turno del documento.', [
                'origen' => (string) $origenId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function nombreDelTurno(Uuid $origenId): string
    {
        return substr('enlace-' . $this->em->getConnection()->getDatabase() . '-' . $origenId, 0, 64);
    }

    /**
     * El tope antifraude de la pasarela, comprobado **al emitir**.
     *
     * 🔥 **Mismo criterio que la validación de credenciales de dos líneas más arriba, y por el
     * mismo motivo:** un cargo por encima del tope no falla al crear el enlace — falla cuando el
     * cliente ya está pagando, con su tarjeta puesta, delante de un «rechazado» que no explica
     * nada. Y quien tiene que decidir qué hacer —partir el cobro, usar transferencia— es el
     * operador, que en ese momento ya no está mirando.
     *
     * ⚠️ **Se mira el TOTAL, no el neto.** Es lo que la pasarela ve. Un neto de 2 900 USD con el
     * 5,5 % de recargo son 3 059,50: por debajo del tope y aun así rechazado.
     *
     * ⚠️ **Una divisa sin tope configurado NO se bloquea.** Es un límite del proveedor, no una
     * política nuestra: inventarnos uno para una moneda que no conocemos impediría cobros que la
     * pasarela sí acepta.
     */
    private function comprobarTope(string $total, string $monedaId): void
    {
        $tope = $this->limitePorCargo[$monedaId] ?? null;

        if ($tope === null || (float) $total <= (float) $tope) {
            return;
        }

        // El mensaje dice el importe QUE SE COBRARÍA —con recargo— y no el neto que se tecleó:
        // es el que la pasarela compara, y decir el otro haría dudar de la cuenta.
        throw new DomainException(sprintf(
            'La pasarela no acepta un cargo de %s %s —su tope por operación es %s %s—. '
            . 'Ese importe ya lleva el recargo de tarjeta, que es lo que se cobra. '
            . 'Parte el cobro en varios enlaces o usa otro medio.',
            $total,
            $monedaId,
            $tope,
            $monedaId,
        ));
    }

    /**
     * Cobro MANUAL: sin documento de origen.
     *
     * El operador teclea importe, concepto y cliente. No hay saldo que leer ni módulo al
     * que imputar el dinero — al cobrarse, el registro se queda sólo en Finanzas.
     *
     * `$modulo` es opcional y es **sólo una etiqueta**: dice a qué negocio pertenece el
     * cobro para poder filtrarlo, no crea vínculo con ningún documento. Por eso se admite
     * incluso un módulo sin resolver (Cotizaciones hoy): etiquetar no requiere saber leer
     * saldos.
     *
     * @throws DomainException si el importe o la moneda no valen.
     */
    public function crearManual(
        string $montoNeto,
        string $moneda,
        string $concepto,
        bool $conRecargo = true,
        ?int $vigenciaDias = null,
        ?FinOrigenCobro $modulo = null,
        ?string $clienteNombre = null,
        ?string $clienteApellido = null,
        ?string $clienteEmail = null,
        ?string $clienteTelefono = null,
        ?string $referencia = null,
        ?User $creadoPor = null,
        ?FinPasarela $pasarela = null,
    ): FinEnlacePago {
        if (trim($concepto) === '') {
            throw new DomainException('Un cobro manual necesita un concepto: es lo que verá el cliente.');
        }

        return $this->construir(
            origenTipo: $modulo,
            origenId: null,
            montoNeto: $montoNeto,
            monedaId: $moneda,
            concepto: $concepto,
            referencia: $referencia,
            clienteNombre: $clienteNombre,
            clienteApellido: $clienteApellido,
            clienteEmail: $clienteEmail,
            clienteTelefono: $clienteTelefono,
            conRecargo: $conRecargo,
            vigenciaDias: $vigenciaDias,
            creadoPor: $creadoPor,
            pasarela: $pasarela,
        );
    }

    /** URL que se le manda al cliente. Vive en `pax`, no en `util`: la abre el huésped. */
    public function urlPublica(FinEnlacePago $enlace): string
    {
        return rtrim($this->paxHostUrl, '/') . '/pago/' . $enlace->getToken();
    }

    /**
     * Cierra el enlace como pagado e imputa el dinero en el módulo de origen.
     *
     * **Idempotente por diseño**: si el enlace ya está PAGADO se sale sin tocar nada. Los
     * IPN se reintentan —Izipay reenvía si no respondemos 200 a tiempo— y sin esta guarda
     * un reintento crearía un segundo `PmsPagoFinanciero` y dejaría la reserva con el
     * doble de pagos. Ese duplicado no se detecta hasta que alguien cuadra la caja.
     *
     * ⚠️ La guarda mira SÓLO `PAGADO`, no los otros estados finales. Un IPN sobre un enlace
     * `ANULADO` lo cobraría igual. **No es un descuido pendiente de arreglar aquí**: hoy no
     * es alcanzable —Culqi no llega por IPN, el cargo lo crea `culqiCobrar()` y ahí
     * `estaVigente()` ya devuelve 410— y el único camino que lo alcanzaría es el de Izipay,
     * que está PARADA (ver `docs/Pendientes.md`, «Izipay: PARADA hasta que se implemente»).
     *
     * Y si algún día se cierra, no se cierra rechazando el pago: si el dinero se movió en la
     * pasarela, el cliente tiene el cargo en su tarjeta. Lo que faltaría es dejar rastro de
     * la contradicción, no negarla.
     *
     * @param array<string, mixed> $respuesta `kr-answer` ya decodificado y con la firma validada.
     * @param array<string, mixed> $respuesta Lo que contestó la pasarela.
     * @param array<string, mixed> $respuesta Lo que contestó la pasarela.
     */
    public function confirmarPago(FinEnlacePago $enlace, array $respuesta): void
    {
        // ⚠️ La guarda va ANTES de tocar `respuestaPasarela`, y no al revés.
        //
        // Estaba después, y sobre un enlace REEMBOLSADO destruía la prueba: el archivo
        // `{cobro, devolucion}` que escribe `reembolsar()` se pisaba con la respuesta nueva
        // antes de que el `return` la salvara. Se perdía justo lo que se cotejaría contra el
        // extracto el día que alguien discutiera la devolución.
        if ($enlace->getEstado() === FinEnlacePagoEstado::PAGADO) {
            $enlace->setRespuestaPasarela($respuesta);
            $this->logger->info('[finanzas] IPN repetido sobre un enlace ya pagado; se ignora', [
                'enlace' => (string) $enlace->getId(),
            ]);
            $this->em->flush();

            return;
        }

        // ⚠️ Y un enlace ya DEVUELTO no vuelve a cobrarse por un aviso tardío.
        //
        // El cargo de Culqi sigue existiendo después del reembolso —conserva su `amount` y
        // anota `amount_refunded` aparte—, así que `cargoPagaElEnlace()` lo daba por bueno y
        // un `charge.update` posterior habría marcado PAGADO otra vez y creado un SEGUNDO
        // `PmsPagoFinanciero`. La reserva aparecería cobrada sobre un dinero ya devuelto, y
        // encima sonaría el aviso al equipo. La devolución puede disparar ese aviso ella
        // misma: el flujo podía deshacerse solo.
        //
        // No se toca `respuestaPasarela` en este camino, por lo mismo de arriba.
        if ($enlace->getEstado() === FinEnlacePagoEstado::REEMBOLSADO) {
            $this->logger->warning('[finanzas] aviso de cobro sobre un enlace ya REEMBOLSADO; se ignora', [
                'enlace' => (string) $enlace->getId(),
            ]);

            return;
        }

        $enlace->setRespuestaPasarela($respuesta);

        // La forma común a las dos pasarelas, leída una vez: ver `TransaccionDePasarela`.
        $transaccion = TransaccionDePasarela::primeraDe($respuesta);

        $enlace
            ->setEstado(FinEnlacePagoEstado::PAGADO)
            ->setPagadoEn(new DateTimeImmutable())
            ->setTransaccionUuid($this->comoTexto($transaccion->uuid, 64))
            ->setAutorizacionCodigo($this->comoTexto($transaccion->autorizacion, 32))
            ->setMedioDetalle($this->describirMedio($transaccion));

        // El módulo dueño crea su propio asiento (el PmsPagoFinanciero, aquí). Va DESPUÉS
        // de marcar el estado para que el resolver pueda leer el enlace ya cerrado.
        //
        // Un cobro MANUAL no tiene documento al que imputar: el dinero entró y queda
        // registrado sólo en Finanzas. No es un caso degradado, es el caso normal de una
        // venta suelta — por eso no se avisa ni se registra como incidencia.
        $enlace->setMovimientoGeneradoId(
            $enlace->getEsManual() ? null : $this->registry->registrarCobro($enlace)
        );

        $this->em->flush();

        // 🔔 Y recién ahora, con el cobro cerrado y persistido, se avisa al equipo.
        //
        // DESPUÉS del flush a propósito, y no antes: avisar de un pago que todavía podría no
        // guardarse es peor que no avisar. Y el aviso no puede volverse contra el cobro —el
        // cliente ya pagó—, así que `notificar()` no lanza nunca; lo que falle queda en el log.
        //
        // Va aquí, en el embudo, y no en cada camino: por `confirmarPago()` pasan los tres
        // (el navegador del cliente y los webhooks de las dos pasarelas), y la guarda de
        // idempotencia de arriba ya devolvió antes si el enlace estaba pagado — así que un IPN
        // repetido no vuelve a hacer sonar los teléfonos.
        $this->avisoDeCobro->notificar($enlace);

        // 🧾 Y al cliente, su confirmación: que el pago entró y dónde ver su cuenta al día. Mismo
        // sitio y mismas dos razones que el aviso al equipo —después del flush, y una sola vez
        // por la guarda de arriba—. Lo que falle se queda en el log: el cobro ya está hecho.
        try {
            $this->registry->confirmarAlCliente($enlace);
        } catch (Throwable $e) {
            $this->logger->error('[finanzas] no se pudo confirmar el pago al cliente', [
                'enlace' => (string) $enlace->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Marca el intento fallido sin cerrar el enlace: el cliente puede reintentar.
     *
     * @param array<string, mixed> $respuesta Lo que contestó la pasarela.
     */
    public function registrarFallo(FinEnlacePago $enlace, array $respuesta): void
    {
        $enlace->setRespuestaPasarela($respuesta);

        if (!$enlace->getEstado()->esFinal()) {
            $enlace->setEstado(FinEnlacePagoEstado::FALLIDO);
        }

        $this->em->flush();
    }

    /**
     * Devuelve el dinero de un enlace cobrado y deshace su asiento en el módulo dueño.
     *
     * ── El orden es la garantía, y no se puede invertir ──────────────────────────
     *   1. La pasarela devuelve.        ← si falla aquí, no se ha escrito NADA
     *   2. El enlace pasa a REEMBOLSADO.
     *   3. El módulo deshace su asiento (el PMS pone el cobro a cero con una nota).
     *   4. Un solo flush cierra 2 y 3.
     *
     * Primero el dinero y después el registro, nunca al revés: un estado que se adelanta a la
     * pasarela es una devolución anotada que puede no haber ocurrido — y el saldo diría que le
     * debemos algo a alguien que sigue teniendo su dinero. Si la pasarela falla, esto lanza y
     * la ficha se queda exactamente como estaba.
     *
     * Los pasos 2 y 3 comparten flush por lo contrario: si el enlace quedara reembolsado y el
     * cobro siguiera vivo, el saldo de la reserva mentiría en la otra dirección.
     *
     * ── Sólo desde el panel de Finanzas ──────────────────────────────────────────
     * La acción vive en `/finanzas`, no en el panel de la reserva. En el PMS esto **se ve**
     * —el enlace en «Reembolsado» y el cobro en cero con su nota— pero no se decide: devolver
     * dinero es una operación de caja, no de recepción.
     *
     * @param string $motivo Lo que escribió el operador. Va a la nota del asiento del módulo;
     *                       a la pasarela viaja su enum cerrado (`CulqiClient::reembolsar()`).
     * @throws DomainException si el enlace no está PAGADO.
     * @throws RuntimeException si la pasarela rechaza la devolución.
     */
    public function reembolsar(FinEnlacePago $enlace, string $motivo = ''): void
    {
        if ($enlace->getEstado() === FinEnlacePagoEstado::REEMBOLSADO) {
            throw new DomainException('Este cobro ya se devolvió.');
        }

        if ($enlace->getEstado() !== FinEnlacePagoEstado::PAGADO) {
            throw new DomainException(
                'Sólo se puede devolver un cobro que se llegó a pagar. Un enlace pendiente o '
                . 'fallido se anula, que no mueve dinero.'
            );
        }

        // 1 · El dinero primero. Lanza si la pasarela dice que no —o si no sabe devolver,
        // como Izipay, que lo declara y lo dice en vez de fingir que trabajó.
        $respuesta = $this->pasarelas->para($enlace->getPasarela())->reembolsar($enlace, $motivo);

        // ⚠️ Se loguea el ID DEL REFUND y el importe, no sólo «se devolvió».
        //
        // Es el único rastro que sobrevive si el flush de abajo falla: en ese momento el
        // dinero está fuera y la base no se ha enterado, y la respuesta cruda vive en una
        // entidad que no llegó a persistirse. Con el id se puede reconciliar contra Culqi;
        // sin él, la única salida es cotejar a mano el extracto.
        $refundId = is_string($respuesta['id'] ?? null) ? $respuesta['id'] : '(sin id)';

        $this->logger->info('[finanzas] devolución aceptada por la pasarela', [
            'enlace' => (string) $enlace->getId(),
            'pasarela' => $enlace->getPasarela()->value,
            'refund' => $refundId,
            'montoNeto' => $enlace->getMontoNeto(),
        ]);

        // 2 · El estado. La respuesta cruda se GUARDA junto a la del cobro, no encima: las dos
        // son parte de la historia de este enlace y la del cobro es la que se cotejará contra
        // el extracto el día que alguien discuta la operación.
        $enlace
            ->setEstado(FinEnlacePagoEstado::REEMBOLSADO)
            ->setRespuestaPasarela([
                'cobro' => $enlace->getRespuestaPasarela(),
                'devolucion' => $respuesta,
            ]);

        // 3 · Y el módulo dueño deshace lo suyo. Un cobro manual no tiene a quién avisar.
        $this->registry->registrarDevolucion($enlace, $motivo);

        // 4 · Los dos juntos.
        //
        // ⚠️ Si esto falla, el dinero YA SALIÓ y la base no se ha enterado: el enlace se queda
        // PAGADO con su cobro vivo, y la reserva dice que cobró algo que ya devolvimos. Es la
        // única ventana irreparable del flujo —no se puede «des-devolver»— así que se grita en
        // el log con todo lo necesario para reconciliar a mano, y se relanza para que el
        // operador vea un error en vez de creer que salió bien.
        try {
            $this->em->flush();
        } catch (Throwable $e) {
            $this->logger->critical(
                '[finanzas] DINERO DEVUELTO Y NO ANOTADO — reconciliar a mano contra la pasarela',
                [
                    'enlace' => (string) $enlace->getId(),
                    'refund' => $refundId,
                    'montoNeto' => $enlace->getMontoNeto(),
                    'moneda' => $enlace->getMonedaCodigo(),
                    'error' => $e->getMessage(),
                ],
            );

            throw $e;
        }
    }

    /**
     * Cierra un enlace que todavía NO se cobró.
     *
     * ⚠️ Anular es sólo para lo que no movió dinero. Lo cobrado se devuelve (`reembolsar()`),
     * y lo devuelto ya está cerrado: sin la segunda guarda, anular reescribía el estado
     * terminal `REEMBOLSADO` a `ANULADO` y borraba de la vista que hubo una devolución —con
     * el cobro del módulo en cero y nadie capaz de explicar por qué.
     */
    public function anular(FinEnlacePago $enlace): void
    {
        if ($enlace->getEstado() === FinEnlacePagoEstado::REEMBOLSADO) {
            throw new DomainException(
                'Este cobro ya se devolvió, así que no hay nada que anular. El enlace queda '
                . 'como está para que la devolución no desaparezca del historial.'
            );
        }

        if ($enlace->getEstado() === FinEnlacePagoEstado::PAGADO) {
            throw new DomainException(
                'Este enlace ya se cobró: anularlo no le devolvería el dinero a nadie. Usa '
                . 'la devolución desde el panel de Finanzas.'
            );
        }

        $enlace->setEstado(FinEnlacePagoEstado::ANULADO);
        $this->em->flush();
    }

    /**
     * Sincroniza el estado de los pendientes caducados.
     *
     * Se llama al listar, no desde un cron: el estado real ya lo decide `estaVigente()` en
     * el momento de pagar, esto es sólo cosmética del listado.
     */
    public function marcarCaducados(): void
    {
        $caducados = $this->repository->pendientesCaducados();

        if ($caducados === []) {
            return;
        }

        foreach ($caducados as $enlace) {
            $enlace->setEstado(FinEnlacePagoEstado::EXPIRADO);
        }

        $this->em->flush();
    }

    // =========================================================================
    // INTERNOS
    // =========================================================================

    /**
     * 32 bytes de entropía en base64url.
     *
     * `random_bytes` y no `uniqid()`/`rand()`: este token ES la credencial de la página de
     * pago. Un generador predecible convierte la URL de cobro de un cliente en la de otro.
     */
    private function generarToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * `orderId` para la pasarela: referencia legible + sufijo único.
     *
     * Lleva sufijo porque una misma reserva puede tener varios enlaces (un adelanto y el
     * resto) y la pasarela rechaza repetir `orderId`. Sólo `[A-Za-z0-9_-]`, máx. 64.
     */
    private function generarOrdenId(FinEnlacePago $enlace): string
    {
        $base = preg_replace('/[^A-Za-z0-9-]/', '', (string) $enlace->getOrigenReferencia()) ?: 'COBRO';
        $sufijo = substr((string) $enlace->getId(), -8);

        return substr($base, 0, 40) . '-' . $sufijo;
    }

    private function calcularExpiracion(?int $vigenciaDias): ?DateTimeImmutable
    {
        $dias = $vigenciaDias ?? self::VIGENCIA_DIAS_DEFECTO;

        // 0 = sin caducidad, decisión explícita del operador.
        if ($dias <= 0) {
            return null;
        }

        return (new DateTimeImmutable())->modify(sprintf('+%d days', $dias));
    }

    /** Dos decimales, punto decimal y sin separador de miles: el formato de las columnas decimal. */
    private function normalizarImporte(string $valor): string
    {
        return number_format((float) $valor, 2, '.', '');
    }


    private function describirMedio(TransaccionDePasarela $transaccion): ?string
    {
        $partes = array_filter([$transaccion->marca, $transaccion->pan]);

        return $partes === [] ? null : substr(implode(' ', $partes), 0, 60);
    }

    private function comoTexto(?string $valor, int $maximo): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim($valor);

        return $texto === '' ? null : substr($texto, 0, $maximo);
    }
}
