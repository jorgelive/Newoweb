<?php

declare(strict_types=1);

namespace App\Operacion\Service;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Cotizacion\Entity\CotizacionCotservicio;
use App\Cotizacion\Entity\CotizacionCottarifa;
use App\Cotizacion\Entity\CotizacionFile;
use App\Operacion\Entity\OperacionServicio;
use App\Travel\Enum\TarifaRolEnum;
use DateTimeImmutable;
use DomainException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Construye las filas de La Biblia a partir de una cotización confirmada.
 *
 * Vive fuera del listener porque estas reglas tienen tres consumidores que no pueden
 * divergir: el flujo automático al confirmar (CotizacionConfirmadaEventListener), la
 * reconciliación (BibliaReconciliacionService) y el comando de consola. Duplicarlas
 * era garantía de que las copias se separaran.
 *
 * El reparto: aquí se decide QUÉ dice la cotización (`calcularValores()`) y cómo se
 * vuelca sobre una fila (`aplicarValores()`); en BibliaReconciliacionService se decide
 * qué se cambia de lo que ya existe y quién lo autoriza.
 */
class BibliaSnapshotService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Genera los OperacionServicio que falten para una cotización.
     *
     * No hace flush: el listener necesita persistir dentro del flush en curso y el comando
     * cierra la transacción por su cuenta.
     *
     * @return OperacionServicio[] Las entidades nuevas, ya persistidas pero sin flush.
     */
    public function generarParaCotizacion(Cotizacion $cotizacion): array
    {
        // Tours de catálogo: producto de exhibición con fechas nominales, sin expediente
        // real — nunca deben generar operación en La Biblia.
        if ($cotizacion->getCatalogo() !== null) {
            return [];
        }

        $file        = $this->resolverFile($cotizacion);
        $cantidadPax = $cotizacion->getNumPax();
        $osRepo      = $this->em->getRepository(OperacionServicio::class);
        $creados     = [];

        foreach ($cotizacion->getCotservicios() as $cotservicio) {
            foreach ($cotservicio->getCotcomponentes() as $cotcomponente) {
                // Idempotencia: saltar si ya existe un OperacionServicio para este componente
                if ($osRepo->findOneBy(['cotizacionComponente' => $cotcomponente]) !== null) {
                    continue;
                }

                $valores = $this->calcularValores($cotcomponente, $cotservicio, $cantidadPax);
                if ($valores === null) {
                    continue; // Excluido: ver calcularValores()
                }

                $ops = new OperacionServicio();
                $ops->setFile($file);
                $ops->setCotizacionServicio($cotservicio);
                $ops->setCotizacionComponente($cotcomponente);

                $this->aplicarValores($ops, $valores, $cotcomponente);

                // Campos que nacen vacíos y pertenecen al operador, no a la cotización:
                // la reconciliación nunca los toca (ver BibliaReconciliacionService).
                $ops->setMontoVenta('0.00');
                $ops->setCostoRealOperativo('0.00');
                $ops->setMonedaReal($ops->getMonedaCotizada());

                $this->em->persist($ops);
                $creados[] = $ops;
            }
        }

        return $creados;
    }

    /**
     * El expediente del que cuelga cada fila de La Biblia.
     *
     * `OperacionServicio::$file` es NOT NULL, así que un file nulo aquí no produce una
     * fila incompleta: mata el flush entero con un «Column 'file_id' cannot be null»
     * lanzado desde el fondo de Doctrine, sin una sola línea de `src/` en la traza y sin
     * mencionar qué cotización era. Le costó a un operador el guardado completo de una
     * cotización el 14/08/2026.
     *
     * El fallback a base de datos es deliberado: esto corre dentro de `onFlush`, con la
     * cotización a medio escribir, y lo que el objeto en memoria diga del padre puede ser
     * lo que trajo el payload y no lo que hay guardado. El expediente de una cotización no
     * cambia por confirmarla, así que ante la duda manda la fila persistida. Es una
     * consulta escalar a propósito: un `find()` pasaría por el UnitOfWork que estamos
     * atravesando y devolvería el mismo objeto en memoria que ya sabemos sospechoso.
     */
    public function resolverFile(Cotizacion $cotizacion): CotizacionFile
    {
        $file = $cotizacion->getFile();
        if ($file !== null) {
            return $file;
        }

        $id = $cotizacion->getId();
        if ($id !== null) {
            $fileId = $this->em->getConnection()->fetchOne(
                'SELECT file_id FROM cotizacion_cotizacion WHERE id = :id',
                ['id' => $id->toBinary()]
            );

            if ($fileId) {
                $file = $this->em->getRepository(CotizacionFile::class)
                    ->find(Uuid::fromBinary($fileId));

                if ($file !== null) {
                    return $file;
                }
            }
        }

        // Sin expediente no hay operación posible. Se dice en voz alta y con el número de
        // cotización delante, que es lo que el 1048 nunca dijo.
        throw new DomainException(sprintf(
            'La cotización %s no cuelga de ningún expediente, así que no se pueden generar '
            . 'sus registros de operación. Vuelve a abrirla desde el expediente y guarda de nuevo.',
            $cotizacion->getId() ?? 'nueva'
        ));
    }

    /**
     * Calcula, en forma ESCALAR, lo que la cotización dice hoy sobre un componente.
     *
     * Devuelve `null` si el componente no debe generar fila (§3.3 de docs/Operacion.md).
     *
     * Existe separado de la construcción de la entidad porque tiene dos consumidores que
     * no pueden divergir: quien **crea** las filas y quien **reconcilia** las existentes.
     * Si el reconciliador reimplementara estas reglas, empezaría a proponer cambios que
     * el generador nunca habría hecho.
     *
     * Escalar y no objetos a propósito: este mismo array se congela en
     * `OperacionServicio::$snapshotOrigen` para poder responder después «¿quién movió
     * este campo?». Las relaciones viajan como identificador.
     *
     * @return array<string, string|int|null>|null
     */
    public function calcularValores(
        CotizacionCotcomponente $cotcomponente,
        CotizacionCotservicio $cotservicio,
        int $cantidadPax,
    ): ?array {
        $tarifa = $this->resolverTarifaPrimaria($cotcomponente);

        // Sin tarifa se distingue de "sólo alternativas", aunque las dos den null:
        //
        //  - Sin NINGUNA tarifa → entra igual, como referencia. Es el hotel que
        //    reservó el pasajero: no se compra, pero el transportista lo necesita
        //    para el recojo. Excluirlo dejaba el tráfico sin el dato del día.
        //  - Con tarifas pero todas ALTERNATIVA → sigue fuera. Es venta opcional
        //    que nadie ha comprado; meterla al cuadro sería programar un servicio
        //    que quizá no se vendió.
        if ($tarifa === null && !$cotcomponente->getCottarifas()->isEmpty()) {
            return null;
        }

        $fechaServicio = $this->resolverFechaServicio($cotcomponente, $cotservicio);
        if ($fechaServicio === null) {
            return null; // Sin fecha: no se puede ubicar en La Biblia
        }

        // Operativo: quién presta. En el caso normal coincide con el comercial —la
        // cascada acaba en la tarifa—, y en un no incluido es el único de los dos que
        // existe: el hotel que reservó el pasajero.
        $prestador = $cotcomponente->resolverPrestador();

        // Gestión: a quién se le encarga la compra. Cae al proveedor cuando nadie lo
        // encargó, que es el caso normal — así una fila sin comprador se comporta como
        // antes de que el campo existiera.
        $comprador = $cotcomponente->resolverComprador();

        return [
            'fechaServicio'         => $fechaServicio->format('Y-m-d'),
            'horaRecojoReal'        => $this->resolverHoraRecojo($cotcomponente),
            // A quién se le MANDA el encargo. Es lo que agrupa y firma la OS.
            //
            // Sustituye al antiguo `proveedorMaestroId` de la tarifa, que era la
            // misma idea con el nombre equivocado: la orden no va a quien pone el precio,
            // va a quien la ejecuta. Cuando nadie encargó nada la cascada cae al
            // proveedor, así que en el caso normal sale exactamente lo mismo que antes.
            'compradorMaestroId'    => $comprador?->maestroId,
            'compradorNombre'       => $comprador?->nombre,
            'prestadorMaestroId'    => $prestador['maestroId'] ?? null,
            'prestadorNombre'       => $prestador['nombre'] ?? null,
            // Nulos A PROPÓSITO, y son el único par de campos que se deja así.
            //
            // El nombre se congela porque es la identidad con la que se vendió; el teléfono
            // es lo contrario: cuando el conductor no aparece sirve el número de HOY, no el
            // del día que se cotizó. Un teléfono caducado con apariencia de bueno es peor en
            // el cuadro de tráfico que no tener ninguno.
            //
            // Quien los rellena es `operacionStore.resolverContactoDeProveedores()`, en lote
            // contra el maestro al cargar el cuadro. Las columnas siguen existiendo como
            // respaldo de los proveedores sin maestro. Ver docs/Operacion.md §3.8.
            'prestadorTelefono'     => null,
            'prestadorDireccion'    => null,
            'descripcionServicio'   => $this->resolverDescripcion($tarifa, $cotcomponente),
            'contextoServicio'      => $this->textoEspanol($cotservicio->getNombreSnapshot()),
            // Clasificación del componente: hoy no filtra nada — entra todo a La Biblia —
            // pero viaja en el snapshot para que la vista pueda agrupar, priorizar y
            // filtrar, y para poder definir después qué tipos son realmente despachables.
            'tipoComponente'        => $cotcomponente->getTipo(),
            'modoComponente'        => $cotcomponente->getModo()->value,
            'estadoComponente'      => $cotcomponente->getEstado()->value,
            'cantidadPax'           => $cantidadPax,
            // Sin tarifa el costo es 0 y no null: la fila es referencia, no una compra
            // de importe desconocido. Un null aquí obligaría a comprobarlo en cada suma.
            //
            // 🔥 El TOTAL del componente, no el precio unitario de una tarifa. Ver
            // `calcularCostoCotizado()`: multiplica por las cantidades y suma todas las
            // tarifas, que es lo que la Orden de Servicio le va a pedir al proveedor.
            'costoCotizado'         => $this->calcularCostoCotizado($cotcomponente),
            'monedaCotizadaId'      => $tarifa?->getMoneda()?->getId(),
            'cotizacionTarifaId'    => $tarifa !== null ? (string) $tarifa->getId() : null,
        ];
    }

    /**
     * Vuelca sobre la fila los valores calculados, resolviendo las relaciones.
     *
     * `$soloCampos` permite aplicar un subconjunto: es lo que necesita la
     * reconciliación cuando el operador aprueba unos campos y rechaza otros.
     *
     * @param array<string, string|int|null> $valores
     * @param string[]|null                  $soloCampos  null = todos
     */
    public function aplicarValores(
        OperacionServicio $ops,
        array $valores,
        CotizacionCotcomponente $cotcomponente,
        ?array $soloCampos = null,
    ): void {
        $aplica = static fn (string $campo): bool => $soloCampos === null || \in_array($campo, $soloCampos, true);

        if ($aplica('fechaServicio')) {
            $ops->setFechaServicio(new DateTimeImmutable((string) $valores['fechaServicio']));
        }
        if ($aplica('horaRecojoReal')) {
            $ops->setHoraRecojoReal($this->comoTexto($valores['horaRecojoReal']));
        }
        if ($aplica('compradorMaestroId')) {
            $ops->setCompradorMaestroId($this->comoTexto($valores['compradorMaestroId']));
        }
        if ($aplica('compradorNombre')) {
            $ops->setCompradorNombre($this->comoTexto($valores['compradorNombre']));
        }
        if ($aplica('prestadorMaestroId')) {
            $ops->setPrestadorMaestroId($this->comoTexto($valores['prestadorMaestroId']));
        }
        if ($aplica('prestadorNombre')) {
            $ops->setPrestadorNombre($this->comoTexto($valores['prestadorNombre']));
        }
        if ($aplica('prestadorTelefono')) {
            $ops->setPrestadorTelefono($this->comoTexto($valores['prestadorTelefono']));
        }
        if ($aplica('prestadorDireccion')) {
            $ops->setPrestadorDireccion($this->comoTexto($valores['prestadorDireccion']));
        }
        if ($aplica('descripcionServicio')) {
            $ops->setDescripcionServicio((string) $valores['descripcionServicio']);
        }
        if ($aplica('contextoServicio')) {
            $ops->setContextoServicio($this->comoTexto($valores['contextoServicio']));
        }
        if ($aplica('tipoComponente')) {
            $ops->setTipoComponente($this->comoTexto($valores['tipoComponente']));
        }
        if ($aplica('modoComponente')) {
            $ops->setModoComponente($this->comoTexto($valores['modoComponente']));
        }
        if ($aplica('estadoComponente')) {
            $ops->setEstadoComponente($this->comoTexto($valores['estadoComponente']));
        }
        if ($aplica('cantidadPax')) {
            $ops->setCantidadPax((int) $valores['cantidadPax']);
        }
        if ($aplica('costoCotizado')) {
            $ops->setCostoCotizado((string) $valores['costoCotizado']);
        }

        // Las dos relaciones se resuelven desde el componente, no desde el id guardado:
        // así la fila apunta siempre a la instancia gestionada por Doctrine.
        $tarifa = $this->resolverTarifaPrimaria($cotcomponente);
        if ($aplica('cotizacionTarifaId')) {
            $ops->setCotizacionTarifa($tarifa);
        }
        if ($aplica('monedaCotizadaId')) {
            $ops->setMonedaCotizada($tarifa?->getMoneda());
        }

        // La foto de referencia se actualiza SIEMPRE y entera, aunque sólo se hayan
        // aplicado unos campos. Si conservara los valores viejos de los campos
        // rechazados, el siguiente plan volvería a proponer exactamente lo mismo y el
        // operador tendría que rechazarlo una y otra vez. Guardar lo propuesto
        // significa: «esto ya te lo pregunté».
        $ops->setSnapshotOrigen($valores);
    }

    /** Los valores del plan son escalares; los campos de texto pueden venir en null. */
    private function comoTexto(string|int|null $valor): ?string
    {
        return $valor === null ? null : (string) $valor;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Reglas de resolución
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Elige la tarifa que se opera cuando el componente tiene varias.
     *
     * Sólo estandar y operativo entran a La Biblia; alternativa es venta opcional y no
     * se despacha. Entre las candidatas gana el menor grupoTarifa.
     */
    public function resolverTarifaPrimaria(CotizacionCotcomponente $componente): ?CotizacionCottarifa
    {
        $tarifas = array_filter(
            $componente->getCottarifas()->toArray(),
            static fn (CotizacionCottarifa $t): bool =>
                TarifaRolEnum::tryFrom($t->getRolSnapshot() ?? '') !== TarifaRolEnum::ALTERNATIVA
        );

        if (empty($tarifas)) {
            return null;
        }

        usort($tarifas, static fn (CotizacionCottarifa $a, CotizacionCottarifa $b): int =>
            ($a->getGrupoTarifa() ?? PHP_INT_MAX) <=> ($b->getGrupoTarifa() ?? PHP_INT_MAX)
        );

        return array_values($tarifas)[0];
    }

    /**
     * Lo que cuesta el componente ENTERO: todas sus tarifas, con sus cantidades.
     *
     * ── El fallo que corrige ────────────────────────────────────────────────────
     * Antes era `tarifaPrimaria->getMontoCosto()` a secas, y eso es el precio UNITARIO de
     * UNA de las tarifas. Dos errores encima del mismo campo:
     *
     *  · No multiplicaba. Un hotel de 2 noches a 106 se guardaba como 106. Medido en
     *    producción: 5 filas con `componente.cantidad <> 1`, todas cortas.
     *  · Sólo miraba una tarifa. Un componente con «Individual ×1» y «Doble ×8» declaraba
     *    el precio de la individual y se comía el resto.
     *
     * Y ese número es el que suma el total de la Orden de Servicio, así que se le pedía al
     * proveedor un importe menor que lo cotizado sin que nada lo delatara.
     *
     * ── La fórmula es la del cotizador, no una nueva ────────────────────────────
     * `monto × cantidad_de_la_tarifa × cantidad_del_componente`, sumado sobre las tarifas que
     * no son ALTERNATIVA. Es exactamente lo que hace `cotizacionEditorStore` al calcular el
     * costo de una línea — si algún día cambia allí, tiene que cambiar aquí.
     *
     * ⚠️ **SIEMPRE los dos factores, no uno u otro.** No es que los hoteles multipliquen por
     * noches y el resto por pax: es una sola fórmula con tres términos. Que en los datos de
     * hoy uno de los dos multiplicadores valga casi siempre 1 es casualidad del expediente,
     * no una regla — un hotel de 3 noches con 2 habitaciones da ×6.
     *
     * Y ésa es la razón de que el fallo viejo (`montoCosto` a secas) aguantara tanto sin que
     * nadie lo viera: acertaba **siempre que los dos factores fuesen 1**, que es el caso
     * mayoritario. Parecía funcionar la mayor parte del tiempo.
     *
     * ⚠️ Suma sin convertir, y puede hacerlo porque un componente NO mezcla monedas
     * (verificado en producción: cero casos). Si algún día los mezclara, esto sumaría peras
     * con manzanas: la moneda se toma de la tarifa primaria y sería la de una sola de ellas.
     */
    public function calcularCostoCotizado(CotizacionCotcomponente $componente): string
    {
        $unidadesComponente = max(1, $componente->getCantidad());
        $total = 0.0;

        foreach ($componente->getCottarifas() as $tarifa) {
            if (TarifaRolEnum::tryFrom($tarifa->getRolSnapshot() ?? '') === TarifaRolEnum::ALTERNATIVA) {
                continue;   // venta opcional que nadie compró: no se encarga ni se paga
            }

            $total += (float) $tarifa->getMontoCosto()
                * max(1, $tarifa->getCantidad())
                * $unidadesComponente;
        }

        return number_format($total, 2, '.', '');
    }

    public function resolverFechaServicio(
        CotizacionCotcomponente $componente,
        CotizacionCotservicio $cotservicio,
    ): ?DateTimeImmutable {
        $inicio = $componente->getFechaHoraInicio();
        if ($inicio !== null) {
            // Normalizar a solo fecha (medianoche UTC) para el campo date_immutable
            return new DateTimeImmutable($inicio->format('Y-m-d'));
        }

        // Fallback: fecha base del servicio padre
        return $cotservicio->getFechaInicioAbsoluta();
    }

    public function resolverHoraRecojo(CotizacionCotcomponente $componente): ?string
    {
        $inicio = $componente->getFechaHoraInicio();
        if ($inicio === null || $componente->isSinHorario()) {
            return null;
        }

        return $inicio->format('H:i');
    }

    /**
     * $tarifa es nullable: los componentes de referencia (hotel del pasajero, vuelo no
     * incluido) no la tienen, y entonces manda el nombre del componente.
     */
    public function resolverDescripcion(
        ?CotizacionCottarifa $tarifa,
        CotizacionCotcomponente $componente,
    ): string {
        // Prioridad 1: cómo llama el PROVEEDOR a ese servicio.
        //
        // Es el sentido entero de `nombreParaProveedor`: tu tarifa se llama «Pool City Lima
        // CT002 (Base 1-4)» y él la conoce como «Del Origen Al Presente de Lima». La OS es
        // un documento que se le manda a él, así que tiene que hablar su idioma — pedirle
        // un código interno que no reconoce es pedirle que adivine.
        //
        // Estuvo escrito y sin conectar: el campo se llenaba en el editor, viajaba al
        // snapshot y no lo leía nadie, así que la OS salía siempre con el nombre interno.
        $paraProveedor = trim($tarifa?->getNombreParaProveedorSnapshot() ?? '');
        if ($paraProveedor !== '') {
            return $paraProveedor;
        }

        // Prioridad 2: nombre interno de la tarifa (campo operativo)
        $descripcion = trim($tarifa?->getNombreInternoSnapshot() ?? '');
        if ($descripcion !== '') {
            return $descripcion;
        }

        // Prioridad 3: nombre del componente en español (snapshot i18n)
        return $this->textoEspanol($componente->getNombreSnapshot()) ?? 'Servicio sin nombre';
    }

    /**
     * Extrae el contenido en español de un snapshot i18n ([{language, content}, ...]).
     *
     * @param array<int, array{language?: string, content?: string}> $snapshot
     */
    public function textoEspanol(array $snapshot): ?string
    {
        foreach ($snapshot as $item) {
            if (($item['language'] ?? '') !== 'es') {
                continue;
            }

            $texto = trim(strip_tags($item['content'] ?? ''));
            if ($texto !== '') {
                return $texto;
            }
        }

        return null;
    }
}
