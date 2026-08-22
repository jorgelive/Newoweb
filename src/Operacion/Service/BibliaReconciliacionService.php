<?php

declare(strict_types=1);

namespace App\Operacion\Service;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Operacion\ApiPlatform\Dto\AplicarPlanInput;
use App\Operacion\ApiPlatform\Dto\CambioPropuesto;
use App\Operacion\ApiPlatform\Dto\CampoPropuesto;
use App\Operacion\ApiPlatform\Dto\PlanReconciliacion;
use App\Operacion\ApiPlatform\Dto\ResultadoAplicacion;
use App\Operacion\Entity\OperacionServicio;
use App\Operacion\Enum\EstadoReservaProveedorEnum;
use DomainException;
use App\Travel\Entity\TravelOrganizacion;
use App\Travel\Entity\TravelOrganizacionServicio;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Reconcilia La Biblia con la cotización SIN borrarla.
 *
 * Sustituye al «borrar y regenerar» del comando original, que era correcto mientras las
 * filas no contenían nada propio: hoy contienen hora real pactada por teléfono,
 * prestador, teléfono del recojo y —cuando se rellenen— costo real. Un merge automático,
 * aunque sea conservador, es tan peligroso como un borrado: el daño simplemente tarda
 * más en descubrirse.
 *
 * Por eso son DOS operaciones separadas:
 *
 *   planificar()  →  calcula el diff. NO escribe. Devuelve un plan firmado.
 *        ↓  una persona revisa, marca y confirma
 *   aplicar()     →  ejecuta SÓLO lo aprobado, y sólo si la firma sigue siendo válida.
 *
 * La pregunta que lo hace posible —«si la fila y la cotización difieren, ¿quién se
 * movió?»— la responde `OperacionServicio::$snapshotOrigen`, la foto de lo que escribió
 * el snapshot la última vez. Ver docs/Operacion.md §3.5.
 */
class BibliaReconciliacionService
{
    /**
     * Campos que la cotización gobierna, con su etiqueta para el diff.
     *
     * Los que NO están aquí pertenecen al operador y la reconciliación no los toca
     * jamás: `estadoReservaProveedor`, `estadoOperacion`, `costoNegociado`, `horaRecojo`, `montoVenta`,
     * `monedaNegociada` y `ordenServicio`. Esa lista corta es media razón de ser del módulo.
     */
    private const ETIQUETAS = [
        'fechaServicio'         => 'Fecha',
        'horaComponente'        => 'Hora (como se vendió)',
        'descripcionServicio'   => 'Servicio (nombre para el proveedor)',
        'tarifaNombre'          => 'Tarifa (nombre interno)',
        'contextoServicio'      => 'Día del itinerario',
        'prestadorNombre'       => 'Prestador (cotizado)',
        'prestadorServicioNombre' => 'Servicio del prestador (cotizado)',
        'prestadorServicioMaestroId' => 'Servicio del prestador (catálogo)',
        'compradorNombre'       => 'Comprador cotizado (a quién se le encarga)',
        'compradorMaestroId'    => 'Comprador (catálogo)',
        'prestadorMaestroId'    => 'Prestador (catálogo)',
        'tipoComponente'        => 'Tipo',
        'modoComponente'        => 'Modo',
        'estadoComponente'      => 'Estado en la cotización',
        'cantidadComponente'    => 'Cantidad (noches/días)',
        'cantidadPax'           => 'Pax',
        'costoCotizado'         => 'Costo cotizado',
        'monedaCotizadaId'      => 'Moneda',
        'cotizacionTarifaId'    => 'Tarifa',
    ];

    /**
     * Identificadores internos: no se listan con su valor porque son UUID ilegibles.
     *
     * ⚠️ Que no se listen NO significa que se ignoren. Antes se descartaban en
     * `compararCampos()` y eso abría un punto ciego permanente: sustituir una tarifa por
     * otra del mismo importe, o corregir la divisa manteniendo la cifra, dejaba el plan
     * diciendo «sin cambios» mientras la fila seguía apuntando a la tarifa y la moneda
     * viejas **para siempre** — con la agrupación de OS por moneda equivocada y sin que
     * nada pudiera detectarlo nunca. Ahora se comparan igual y salen como una línea con
     * texto legible: ver `describirTecnico()`.
     */
    private const CAMPOS_TECNICOS = [
        'compradorMaestroId',
        'prestadorMaestroId',
        // ⚠️ Faltaba, y por eso el plan enseñaba el UUID pelado —
        // «019f68bd-4f13-7750-bb37-2cafacd3489b»— en la línea «Servicio del prestador
        // (catálogo)». `describirTecnico()` ya sabía tratarlo; nunca se le llamaba.
        'prestadorServicioMaestroId',
        'cotizacionTarifaId',
    ];

    /** Campo técnico → campo visible que lo explica; si ese ya salió, no se repite. */
    private const TECNICO_ACOMPANA_A = [
        'compradorMaestroId'  => 'compradorNombre',
        'prestadorMaestroId'  => 'prestadorNombre',
        'prestadorServicioMaestroId' => 'prestadorServicioNombre',
        'cotizacionTarifaId'  => 'costoCotizado',
    ];

    /**
     * Nombres del catálogo ya resueltos, por id.
     *
     * Se cachea por petición: un plan de 40 filas repite el mismo proveedor decenas de veces y
     * sin esto serían decenas de consultas para escribir la misma palabra.
     *
     * @var array<string, string|null>
     */
    private array $nombresDelCatalogo = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BibliaSnapshotService $snapshot,
    ) {
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PLANIFICAR — no escribe nada
    // ─────────────────────────────────────────────────────────────────────────

    public function planificar(Cotizacion $cotizacion): PlanReconciliacion
    {
        $cambios     = [];
        $sinCambios  = 0;
        $cantidadPax = $cotizacion->getNumPax();

        $filasPorComponente = $this->filasPorComponente($cotizacion);
        $componentesVivos   = [];

        foreach ($cotizacion->getCotservicios() as $cotservicio) {
            foreach ($cotservicio->getCotcomponentes() as $componente) {
                $idComponente = (string) $componente->getId();
                $valores      = $this->snapshot->calcularValores($componente, $cotservicio, $cantidadPax);

                // El componente dejó de generar fila (le quitaron la fecha, se quedó
                // sólo con alternativas...). Si tiene fila, saldrá como huérfana abajo.
                if ($valores === null) {
                    continue;
                }

                $componentesVivos[$idComponente] = true;
                $fila = $filasPorComponente[$idComponente] ?? null;

                if ($fila === null) {
                    $cambios[] = new CambioPropuesto(
                        id: $idComponente,
                        tipo: CambioPropuesto::TIPO_CREAR,
                        descripcion: (string) $valores['descripcionServicio'],
                        contexto: $this->comoTexto($valores['contextoServicio']),
                        fecha: $this->comoTexto($valores['fechaServicio']),
                        // Crear no destruye nada: es el único caso que llega marcado.
                        aprobadoPorDefecto: true,
                    );
                    continue;
                }

                $campos = $this->compararCampos($fila, $valores);
                if ($campos === []) {
                    ++$sinCambios;
                    continue;
                }

                $hayConflicto = false;
                foreach ($campos as $campo) {
                    if ($campo->enConflicto) {
                        $hayConflicto = true;
                        break;
                    }
                }

                $enOs = $fila->getOrdenServicio() !== null;

                $cambios[] = new CambioPropuesto(
                    id: $idComponente,
                    tipo: CambioPropuesto::TIPO_ACTUALIZAR,
                    descripcion: $fila->getDescripcionServicio(),
                    contexto: $fila->getContextoServicio(),
                    fecha: $fila->getFechaServicio()?->format('Y-m-d'),
                    campos: $campos,
                    motivo: $this->motivoActualizar($hayConflicto, $enOs),
                    enOrdenServicio: $enOs,
                    // Sólo se marca solo cuando nadie tocó la fila y no ha viajado al
                    // proveedor. En cualquier otro caso lo decide una persona.
                    aprobadoPorDefecto: !$hayConflicto && !$enOs,
                );
            }
        }

        // Filas cuyo componente ya no produce valores: sobran en el cuadro.
        foreach ($filasPorComponente as $idComponente => $fila) {
            if (isset($componentesVivos[$idComponente])) {
                continue;
            }

            $bloqueo   = $this->motivoBloqueoBorrado($fila);
            $cambios[] = new CambioPropuesto(
                id: $idComponente,
                tipo: CambioPropuesto::TIPO_HUERFANO,
                descripcion: $fila->getDescripcionServicio(),
                contexto: $fila->getContextoServicio(),
                fecha: $fila->getFechaServicio()?->format('Y-m-d'),
                bloqueado: $bloqueo !== null,
                motivo: $bloqueo ?? 'El componente ya no existe en la cotización. Borrar siempre se confirma a mano.',
                // Borrar NUNCA llega marcado.
                aprobadoPorDefecto: false,
            );
        }

        return new PlanReconciliacion(
            firma: $this->firmar($cotizacion),
            cambios: $cambios,
            sinCambios: $sinCambios,
            mensaje: $this->redactarPlan($cambios, $sinCambios),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // APLICAR — sólo lo aprobado, y sólo si el plan sigue vigente
    // ─────────────────────────────────────────────────────────────────────────

    public function aplicar(Cotizacion $cotizacion, AplicarPlanInput $entrada): ResultadoAplicacion
    {
        // La vigilancia que hace real la aprobación: si el estado se movió entre
        // calcular y aprobar, lo revisado ya no es lo que se aplicaría.
        if ($entrada->firma !== $this->firmar($cotizacion)) {
            throw new DomainException(
                'La operación cambió mientras revisabas los cambios. Vuelve a calcular el plan para no aplicar decisiones sobre datos viejos.'
            );
        }

        $plan = $this->planificar($cotizacion);

        $creados = $actualizados = $borrados = $omitidos = 0;
        $filasPorComponente = $this->filasPorComponente($cotizacion);
        $componentes        = $this->componentesPorId($cotizacion);
        $cantidadPax        = $cotizacion->getNumPax();

        foreach ($plan->cambios as $cambio) {
            $aprobado = $entrada->aprobados[$cambio->id] ?? null;

            // Ausente = no aprobado. No hay «aplicar todo» implícito, y un cambio
            // bloqueado no se aplica aunque venga marcado en el body.
            if ($aprobado === null || $cambio->bloqueado) {
                ++$omitidos;
                continue;
            }

            switch ($cambio->tipo) {
                case CambioPropuesto::TIPO_CREAR:
                    $componente = $componentes[$cambio->id] ?? null;
                    if ($componente === null) {
                        ++$omitidos;
                        break;
                    }
                    $valores = $this->snapshot->calcularValores($componente, $componente->getCotservicio(), $cantidadPax);
                    if ($valores === null) {
                        ++$omitidos;
                        break;
                    }
                    $fila = new OperacionServicio();
                    // Mismo resolutor que el generador: el file NOT NULL de la fila no
                    // puede depender de lo que traiga el objeto en memoria. Ver
                    // BibliaSnapshotService::resolverFile().
                    $fila->setFile($this->snapshot->resolverFile($cotizacion));
                    $fila->setCotizacionServicio($componente->getCotservicio());
                    $fila->setCotizacionComponente($componente);
                    $this->snapshot->aplicarValores($fila, $valores, $componente);
                    $fila->setMontoVenta('0.00');
                    $fila->setCostoNegociado('0.00');
                    $fila->setMonedaNegociada($fila->getMonedaCotizada());
                    $this->em->persist($fila);
                    ++$creados;
                    break;

                case CambioPropuesto::TIPO_ACTUALIZAR:
                    $componente = $componentes[$cambio->id] ?? null;
                    $fila       = $filasPorComponente[$cambio->id] ?? null;
                    if ($componente === null || $fila === null) {
                        ++$omitidos;
                        break;
                    }
                    $valores = $this->snapshot->calcularValores($componente, $componente->getCotservicio(), $cantidadPax);
                    if ($valores === null) {
                        ++$omitidos;
                        break;
                    }
                    // Los campos técnicos viajan pegados al campo visible que los explica
                    // (la moneda con el costo, la tarifa con el proveedor): sin esto se
                    // aprobaría un costo nuevo dejando la moneda vieja.
                    $campos = $this->expandirTecnicos($aprobado);
                    if ($campos === []) {
                        ++$omitidos;
                        break;
                    }
                    $this->snapshot->aplicarValores($fila, $valores, $componente, $campos);
                    ++$actualizados;
                    break;

                case CambioPropuesto::TIPO_HUERFANO:
                    $fila = $filasPorComponente[$cambio->id] ?? null;
                    if ($fila === null) {
                        ++$omitidos;
                        break;
                    }
                    $this->em->remove($fila);
                    ++$borrados;
                    break;
            }
        }

        $this->em->flush();

        return new ResultadoAplicacion(
            creados: $creados,
            actualizados: $actualizados,
            borrados: $borrados,
            omitidos: $omitidos,
            mensaje: sprintf(
                '%d creados, %d actualizados, %d borrados. %d cambio(s) del plan no se aprobaron.',
                $creados,
                $actualizados,
                $borrados,
                $omitidos
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Interno
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Compara la fila con lo que la cotización dice hoy, y decide de quién es cada cambio.
     *
     * @param array<string, string|int|null> $valores
     *
     * @return CampoPropuesto[]
     */
    private function compararCampos(OperacionServicio $fila, array $valores): array
    {
        $origen = $fila->getSnapshotOrigen();
        $actual = $this->valoresActuales($fila);
        $campos = [];

        // Primera pasada: los campos visibles.
        $visiblesCambiados = [];

        foreach ($valores as $campo => $propuesto) {
            if (\in_array($campo, self::CAMPOS_TECNICOS, true)) {
                continue; // Segunda pasada
            }

            $valorActual    = $this->normalizar($actual[$campo] ?? null);
            $valorPropuesto = $this->normalizar($propuesto);

            if ($valorActual === $valorPropuesto) {
                continue;
            }

            $visiblesCambiados[$campo] = true;

            // Sin foto de referencia (filas anteriores a snapshot_origen, o `{}`) todo
            // se trata como conflicto. Es lo conservador: si no se sabe quién se movió,
            // no se decide en automático.
            $valorOrigen  = \array_key_exists($campo, $origen) ? $this->normalizar($origen[$campo]) : null;
            $sinReferencia = $origen === [] || !\array_key_exists($campo, $origen);
            $enConflicto  = $sinReferencia || $valorActual !== $valorOrigen;

            $campos[] = new CampoPropuesto(
                campo: $campo,
                etiqueta: self::ETIQUETAS[$campo] ?? $campo,
                valorActual: $valorActual,
                valorPropuesto: $valorPropuesto,
                enConflicto: $enConflicto,
            );
        }

        // Segunda pasada: los identificadores internos. Sólo salen cuando cambian SIN
        // que su campo visible haya cambiado — que es justo el caso invisible: misma
        // cifra, mismo nombre, otra tarifa u otro proveedor por debajo.
        foreach (self::CAMPOS_TECNICOS as $campo) {
            if (!\array_key_exists($campo, $valores)) {
                continue;
            }

            $valorActual    = $this->normalizar($actual[$campo] ?? null);
            $valorPropuesto = $this->normalizar($valores[$campo]);

            if ($valorActual === $valorPropuesto) {
                continue;
            }

            // Sin `?? null`: las dos listas declaran las MISMAS tres claves, así que el
            // acceso siempre acierta. El `?? null` que había fingía una rama que no
            // existe — y si algún día se añade un técnico sin acompañante, lo que hace
            // falta es que reviente aquí, no que se cuele en silencio.
            $acompana = self::TECNICO_ACOMPANA_A[$campo];
            if (isset($visiblesCambiados[$acompana])) {
                continue;   // ya se ve, viajará pegado a él en expandirTecnicos()
            }

            $origen       = $fila->getSnapshotOrigen();
            $sinReferencia = $origen === [] || !\array_key_exists($campo, $origen);

            $campos[] = new CampoPropuesto(
                campo: $campo,
                etiqueta: self::ETIQUETAS[$campo],
                // El UUID no dice nada a nadie; lo que importa es que cambió por debajo.
                valorActual: $this->describirTecnico($campo, $valorActual),
                valorPropuesto: $this->describirTecnico($campo, $valorPropuesto),
                enConflicto: $sinReferencia || $valorActual !== $this->normalizar($origen[$campo] ?? null),
            );
        }

        return $campos;
    }

    /**
     * Texto para un identificador interno. Se evita enseñar el UUID: al operador no le
     * dice nada y lo único que necesita saber es que la pieza de debajo es otra.
     */
    private function describirTecnico(string $campo, ?string $valor): string
    {
        if ($valor === null) {
            return 'sin asignar';
        }

        // ── El NOMBRE, no el identificador ──────────────────────────────────
        // Esto devolvía «prestador 019f68bd» — ocho caracteres de un UUID, que no dicen más que
        // el UUID entero. Quien aprueba un cambio del catálogo necesita saber **de qué empresa a
        // qué empresa**, y eso es lo único que le permite decidir.
        //
        // El id se conserva detrás como respaldo: si la ficha se borró del maestro, el nombre ya
        // no existe y enseñar el identificador sigue siendo mejor que un hueco.
        $nombre = $this->nombreDelCatalogo($campo, $valor);

        if ($nombre !== null) {
            return $nombre;
        }

        return match ($campo) {
            'cotizacionTarifaId' => 'tarifa ' . substr($valor, 0, 8),
            'compradorMaestroId' => 'comprador ' . substr($valor, 0, 8),
            'prestadorServicioMaestroId' => 'servicio ' . substr($valor, 0, 8),
            'prestadorMaestroId' => 'prestador ' . substr($valor, 0, 8),
            default              => $valor,
        };
    }

    /**
     * El nombre que el catálogo le da a este identificador, o `null` si no se puede resolver.
     *
     * `cotizacionTarifaId` se queda fuera: una tarifa no tiene un nombre corto que valga como
     * etiqueta —la explica su importe, que ya sale en la línea de al lado (`costoCotizado`)—.
     */
    private function nombreDelCatalogo(string $campo, string $id): ?string
    {
        $clave = $campo . ':' . $id;

        if (array_key_exists($clave, $this->nombresDelCatalogo)) {
            return $this->nombresDelCatalogo[$clave];
        }

        $nombre = null;

        if (Uuid::isValid($id)) {
            $uuid = Uuid::fromString($id);

            $nombre = match ($campo) {
                'compradorMaestroId', 'prestadorMaestroId' =>
                    $this->em->getRepository(TravelOrganizacion::class)->find($uuid)?->getNombreComercial(),
                'prestadorServicioMaestroId' =>
                    $this->em->getRepository(TravelOrganizacionServicio::class)->find($uuid)?->getNombre(),
                default => null,
            };
        }

        $nombre = $nombre !== null && trim($nombre) !== '' ? trim($nombre) : null;

        return $this->nombresDelCatalogo[$clave] = $nombre;
    }

    /** Estado actual de la fila en el mismo formato escalar que calcularValores(). */
    private function valoresActuales(OperacionServicio $fila): array
    {
        $tarifa = $fila->getCotizacionTarifa();

        return [
            'fechaServicio'         => $fila->getFechaServicio()?->format('Y-m-d'),
            'horaComponente'        => $fila->getHoraComponente(),
            'compradorMaestroId'    => $fila->getCompradorMaestroId(),
            'compradorNombre'       => $fila->getCompradorNombre(),
            'prestadorMaestroId'    => $fila->getPrestadorMaestroId(),
            'prestadorNombre'       => $fila->getPrestadorNombre(),
            'prestadorServicioNombre' => $fila->getPrestadorServicioNombre(),
            'prestadorServicioMaestroId' => $fila->getPrestadorServicioMaestroId(),
            'descripcionServicio'   => $fila->getDescripcionServicio(),
            'tarifaNombre'          => $fila->getTarifaNombre(),
            'contextoServicio'      => $fila->getContextoServicio(),
            'tipoComponente'        => $fila->getTipoComponente(),
            'modoComponente'        => $fila->getModoComponente(),
            'estadoComponente'      => $fila->getEstadoComponente(),
            'cantidadComponente'    => $fila->getCantidadComponente(),
            'cantidadPax'           => $fila->getCantidadPax(),
            'costoCotizado'         => $fila->getCostoCotizado(),
            'monedaCotizadaId'      => $fila->getMonedaCotizada()?->getId(),
            'cotizacionTarifaId'    => $tarifa !== null ? (string) $tarifa->getId() : null,
        ];
    }

    /**
     * Añade los identificadores internos que acompañan a cada campo visible.
     *
     * @param string[] $aprobados
     *
     * @return string[]
     */
    private function expandirTecnicos(array $aprobados): array
    {
        $campos = $aprobados;

        // `monedaCotizadaId` ya NO es técnico: sus valores ('USD', 'PEN') son legibles y
        // sale como línea propia, así que se aprueba por sí mismo. Pero sigue viajando
        // con el costo: aceptar un importe nuevo dejando la moneda vieja convierte 150
        // soles en 150 dólares sin que nadie lo note.
        if (\in_array('costoCotizado', $campos, true)) {
            $campos[] = 'monedaCotizadaId';
            $campos[] = 'cotizacionTarifaId';
        }
        if (\in_array('compradorNombre', $campos, true)) {
            $campos[] = 'compradorMaestroId';
        }
        if (\in_array('prestadorNombre', $campos, true)) {
            $campos[] = 'prestadorMaestroId';
        }

        return array_values(array_unique($campos));
    }

    private function motivoActualizar(bool $hayConflicto, bool $enOs): ?string
    {
        if ($enOs && $hayConflicto) {
            return 'Tiene ediciones manuales y además pertenece a una Orden de Servicio ya emitida.';
        }
        if ($enOs) {
            return 'Pertenece a una Orden de Servicio: cambiarlo altera lo que ya se le pidió al proveedor.';
        }
        if ($hayConflicto) {
            return 'Alguien editó estos campos en Operaciones. Gana lo que ya está, salvo que lo apruebes.';
        }

        return null;
    }

    private function motivoBloqueoBorrado(OperacionServicio $fila): ?string
    {
        if ($fila->getOrdenServicio() !== null) {
            return 'No se puede borrar: pertenece a una Orden de Servicio y se perdería el rastro de lo que se pidió al proveedor.';
        }
        if ((float) $fila->getCostoNegociado() !== 0.0) {
            return 'No se puede borrar: tiene costo real registrado y no está en ningún otro sitio.';
        }

        // La OS no es la única forma de haber comprometido algo. En la práctica media
        // agencia reserva por teléfono o WhatsApp y lo anota aquí sin emitir orden: si
        // el estado de reserva ya salió de `sin-solicitar`, hay un proveedor esperando.
        // Borrar la fila deja esa reserva viva y sin rastro — y el no-show se factura.
        if ($fila->getEstadoReservaProveedor() !== EstadoReservaProveedorEnum::SIN_SOLICITAR) {
            return sprintf(
                'No se puede borrar: está «%s» con el proveedor. Cancélalo con él y pon el estado en Sin Solicitar antes de quitarlo.',
                $fila->getEstadoReservaProveedor()->value
            );
        }

        return null;
    }

    /**
     * Hash del estado que se leyó al planificar. Cubre las filas existentes (para
     * detectar ediciones ajenas) y los componentes de la cotización (para detectar que
     * alguien la editó en paralelo).
     */
    private function firmar(Cotizacion $cotizacion): string
    {
        $partes = [];

        foreach ($this->filasPorComponente($cotizacion) as $id => $fila) {
            $partes[] = $id . '|' . json_encode($this->valoresActuales($fila)) . '|' . ($fila->getOrdenServicio() !== null ? '1' : '0');
        }

        $cantidadPax = $cotizacion->getNumPax();
        foreach ($cotizacion->getCotservicios() as $cotservicio) {
            foreach ($cotservicio->getCotcomponentes() as $componente) {
                $valores  = $this->snapshot->calcularValores($componente, $cotservicio, $cantidadPax);
                $partes[] = $componente->getId() . '|' . json_encode($valores);
            }
        }

        sort($partes);

        return hash('sha256', implode("\n", $partes));
    }

    /** @return array<string, OperacionServicio> indexado por UUID de componente */
    private function filasPorComponente(Cotizacion $cotizacion): array
    {
        $cotservicios = $cotizacion->getCotservicios()->toArray();
        if ($cotservicios === []) {
            return [];
        }

        // findBy() y no DQL: el id es un Uuid binario y la comparación en DQL no lo
        // convierte, así que devolvería 0 filas en silencio (§8 de docs/Operacion.md).
        /** @var OperacionServicio[] $filas */
        $filas  = $this->em->getRepository(OperacionServicio::class)->findBy(['cotizacionServicio' => $cotservicios]);
        $mapa   = [];

        foreach ($filas as $fila) {
            $componente = $fila->getCotizacionComponente();
            if ($componente !== null) {
                $mapa[(string) $componente->getId()] = $fila;
            }
        }

        return $mapa;
    }

    /** @return array<string, CotizacionCotcomponente> */
    private function componentesPorId(Cotizacion $cotizacion): array
    {
        $mapa = [];
        foreach ($cotizacion->getCotservicios() as $cotservicio) {
            foreach ($cotservicio->getCotcomponentes() as $componente) {
                $mapa[(string) $componente->getId()] = $componente;
            }
        }

        return $mapa;
    }

    private function normalizar(string|int|float|null $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    private function comoTexto(string|int|null $valor): ?string
    {
        return $valor === null ? null : (string) $valor;
    }

    /** @param CambioPropuesto[] $cambios */
    private function redactarPlan(array $cambios, int $sinCambios): string
    {
        if ($cambios === []) {
            return sprintf('La Biblia ya coincide con la cotización (%d servicios). No hay nada que aplicar.', $sinCambios);
        }

        $conteo = [CambioPropuesto::TIPO_CREAR => 0, CambioPropuesto::TIPO_ACTUALIZAR => 0, CambioPropuesto::TIPO_HUERFANO => 0];
        $conflictos = 0;
        foreach ($cambios as $cambio) {
            ++$conteo[$cambio->tipo];
            foreach ($cambio->campos as $campo) {
                if ($campo->enConflicto) {
                    ++$conflictos;
                }
            }
        }

        $mensaje = sprintf(
            '%d por crear, %d por actualizar, %d sobrantes. %d sin cambios.',
            $conteo[CambioPropuesto::TIPO_CREAR],
            $conteo[CambioPropuesto::TIPO_ACTUALIZAR],
            $conteo[CambioPropuesto::TIPO_HUERFANO],
            $sinCambios
        );

        if ($conflictos > 0) {
            $mensaje .= sprintf(' %d campo(s) los editó alguien en Operaciones: llegan sin marcar.', $conflictos);
        }

        return $mensaje;
    }
}
