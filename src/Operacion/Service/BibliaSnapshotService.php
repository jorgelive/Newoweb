<?php

declare(strict_types=1);

namespace App\Operacion\Service;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Cotizacion\Entity\CotizacionCotservicio;
use App\Cotizacion\Entity\CotizacionCottarifa;
use App\Operacion\Entity\OperacionServicio;
use App\Travel\Enum\TarifaRolEnum;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

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

        $file        = $cotizacion->getFile();
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
        $prestador = $cotcomponente->resolverPrestador($tarifa);

        return [
            'fechaServicio'         => $fechaServicio->format('Y-m-d'),
            'horaRecojoReal'        => $this->resolverHoraRecojo($cotcomponente),
            // Comercial: a quién se le compra. Es lo que agrupa y firma la OS.
            'proveedorMaestroId'    => $tarifa?->getProveedorMaestroId(),
            'proveedorNombreManual' => $tarifa?->getProveedorNombreSnapshot(),
            'prestadorMaestroId'    => $prestador?->maestroId,
            'prestadorNombre'       => $prestador?->nombre,
            'prestadorTelefono'     => $prestador?->telefono,
            'prestadorDireccion'    => $prestador?->direccion,
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
            'costoCotizado'         => $tarifa?->getMontoCosto() ?? '0.00',
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
        if ($aplica('proveedorMaestroId')) {
            $ops->setProveedorMaestroId($this->comoTexto($valores['proveedorMaestroId']));
        }
        if ($aplica('proveedorNombreManual')) {
            $ops->setProveedorNombreManual($this->comoTexto($valores['proveedorNombreManual']));
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
        // Prioridad 1: nombre interno de la tarifa (campo operativo)
        $descripcion = trim($tarifa?->getNombreInternoSnapshot() ?? '');
        if ($descripcion !== '') {
            return $descripcion;
        }

        // Prioridad 2: nombre del componente en español (snapshot i18n)
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
