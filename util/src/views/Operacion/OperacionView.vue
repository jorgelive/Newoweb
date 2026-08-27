<script setup lang="ts">
/**
 * Centro de Operaciones — La Biblia (cuadro de tráfico) y Órdenes de Servicio.
 *
 * Las filas provienen de OperacionServicio, el snapshot que genera
 * CotizacionConfirmadaEventListener al confirmar una cotización. Es un snapshot:
 * editar la cotización después NO actualiza estas filas. Ver docs/Operacion.md.
 *
 * El listener no filtra por tipo de componente: entra todo lo que tenga tarifa y
 * fecha, incluidos alojamientos, desayunos, cortesías y componentes cancelados. Por
 * eso la vista muestra tipo/modo/estado del componente y deja filtrar: primero se ve
 * qué está llegando, después se decide qué se deja de generar.
 */
import { ref, onMounted, computed, watch } from 'vue';
import { useRouter } from 'vue-router';
import SearchableSelect from '@/components/SearchableSelect.vue';
import EditorCostoNegociado from '@/components/operacion/EditorCostoNegociado.vue';
import { useOperacionStore, type ExpedienteOpcion, type CotizacionOpcion, type BitacoraEstado, type PagoProveedor, type ExpedienteDetalle, type ProveedorOpcion , type DocumentoDeOrden } from '@/stores/operacion/operacionStore';
import AppSwitcher from '@/components/common/AppSwitcher.vue';
import FechaHoraPicker from '@/components/common/FechaHoraPicker.vue';
import { getUrls } from '@/services/apiClient';
import { mensajeDeErrorApi } from '@/utils/errorApi';
import { extractIdStr } from '@/utils/recurso';
import {
    getEstadoOsConfig,
    getEstadoReservaProveedorConfig,
    getEstadoOperacionConfig,
    getTipoComponenteConfig,
    getModoComponenteConfig,
    getEstadoComponenteConfig,
    ESTADO_RESERVA_PROVEEDOR_CONFIG,
    ESTADO_OPERACION_CONFIG,
    ESTADO_OS_CONFIG,
    type EstadoOsValue,
    TIPOS_COMPONENTE,
    SIN_LUGAR,
    type FiltrosBiblia,
    type OperacionServicio,
    type OperacionOrdenServicio,
} from '@/types/operacionModel';
import { useRefrescoDelAsistente } from '@/composables/useRefrescoDelAsistente';
import { useCapasEnHistorial } from '@/composables/useCapasEnHistorial';

const operacionStore = useOperacionStore();
const router = useRouter();

const activeTab = ref<'biblia' | 'ordenes'>('biblia');

// ============================================================================
// FILTROS
//
// FechaHoraPicker trabaja con "YYYY-MM-DDTHH:mm"; la API espera "YYYY-MM-DD".
// El recorte se hace con slice y nunca con Date: no hay que aplicar zona horaria
// a una fecha de servicio (ver docs/UI_Componentes_Compartidos.md §1.3).
// ============================================================================
const hoyIso = (): string => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

const sumarDias = (iso: string, dias: number): string => {
    const [a, m, d] = iso.split('-').map(Number);
    const fecha = new Date(a, m - 1, d + dias);
    return `${fecha.getFullYear()}-${String(fecha.getMonth() + 1).padStart(2, '0')}-${String(fecha.getDate()).padStart(2, '0')}`;
};

// El rango de arranque es de 7 días y no de un solo día a propósito: con "hoy" la
// primera pantalla salía vacía casi siempre —los servicios de un expediente confirmado
// caen semanas después— y eso se leía como "el panel no funciona" en vez de como "no
// hay nada hoy". El preset "Hoy" sigue a un clic para el tráfico del día.
const desde = ref<string>(`${hoyIso()}T00:00`);
const hasta = ref<string>(`${sumarDias(hoyIso(), 6)}T00:00`);
const tiposSeleccionados = ref<string[]>([]);
const lugaresSeleccionados = ref<string[]>([]);

const filtroEstadoReservaProveedor = ref<string>('');
const filtroEstadoOperacion = ref<string>('');

/**
 * Qué grupo de filtros está desplegado. **Uno como mucho.**
 *
 * Los cuatro ejes —lugar, organización, tipo, estado— comparten UNA fila de rótulos y sólo el
 * abierto pinta sus opciones debajo. Antes cada uno tenía su franja propia siempre visible: entre
 * trece chips de lugar, los de organización y catorce de tipo, la barra se comía la pantalla de un
 * teléfono entera y el primer servicio —que es lo que se viene a mirar— quedaba bajo el pliegue.
 *
 * ⚠️ El rótulo lleva CONTADOR cuando su eje tiene algo puesto, y por eso es seguro arrancar con
 * todos cerrados. Un filtro activo escondido y sin contador es la forma de leer un cuadro
 * recortado creyéndolo entero — el fallo que ya costó las filas de tipo `contacto`.
 */
type GrupoFiltro = 'lugar' | 'organizacion' | 'tipo' | 'estado';
const grupoAbierto = ref<GrupoFiltro | null>(null);

const alternarGrupo = (g: GrupoFiltro): void => {
    grupoAbierto.value = grupoAbierto.value === g ? null : g;
};

/**
 * Cómo se ordena el día: por ITINERARIO o por reloj.
 *
 * Por itinerario es el defecto porque es como se lee un viaje: el alojamiento y la comida —que no
 * tienen hora— quedan junto al servicio al que pertenecen, en vez de caer al final del día lejos
 * de su contexto. El reloj sigue estando, para cuando lo que importa es a qué hora sale cada cosa.
 */
const ordenPorHora = ref<boolean>(false);

/**
 * Si la fila ya está en una Orden de Servicio. `''` = todas.
 *
 * Se filtra EN LOCAL sobre lo ya cargado y no en el servidor: es un interruptor de lectura
 * —«qué me falta por encargar»— y pedir de nuevo por él haría esperar para esconder filas
 * que ya están en pantalla. Ojo: actúa sobre la página cargada, así que con el aviso de
 * «hay más servicios de los que caben» el recuento sigue siendo el del servidor.
 */
const filtroOs = ref<'' | 'sin' | 'con'>('');

// ── FILTRO POR EMPRESA (prestador o comprador) ──────────────────────────────
//
// Es el filtro con el que se compone una Orden de Servicio: la orden agrupa por comprador, así
// que «enséñame todo lo de Futurismo Jonathan» es el paso previo a marcar y generar.
//
// ⚠️ Filtra **lo ya cargado**, como `filtroOs`, y sus opciones salen de las propias filas: son
// las organizaciones que hay en el cuadro, no el catálogo entero. Un catálogo de proveedores da una
// lista larguísima de la que la mitad no aparece en estas fechas.
//
// Mira los DOS papeles y los combina con OR —una fila tiene prestador y comprador, y no siempre
// son la misma—: quien busca una organización quiere sus filas, le toque el papel que le
// toque. Y mira el EFECTIVO, que es lo que vale (`docs/Operacion.md` §3.3.b).
const organizacionesSeleccionadas = ref<string[]>([]);

/** Chip «Sin asignar». Sólo vive en el front: este filtro no viaja al servidor. */
const SIN_ORGANIZACION = '__sin_asignar__';

// Expediente / cotización
/**
 * ¿El desplegable tiene que abrirse HACIA ARRIBA?
 *
 * ⚠️ Un desplegable anclado siempre a `top-full` es inservible en la mitad baja de un teléfono: las
 * opciones caen fuera de la pantalla, y con el teclado abierto no hay ni scroll con el que
 * alcanzarlas — el campo está en el límite y lo que sale queda debajo del teclado. No es que se
 * vea mal: es que **no se puede elegir**.
 *
 * Se mide el hueco real bajo el ancla. `visualViewport` es lo que hace falta en móvil: `innerHeight`
 * no descuenta el teclado, así que con él la cuenta diría que hay sitio justo cuando no lo hay.
 */
const abreHaciaArriba = (ancla: HTMLElement | null | undefined, alto = 240): boolean => {
    if (!ancla) return false;

    const caja = ancla.getBoundingClientRect();
    const visible = window.visualViewport?.height ?? window.innerHeight;

    // Sólo se vuelca si arriba cabe MEJOR: en una pantalla diminuta puede no caber en ninguno de
    // los dos lados, y ahí es preferible el sitio de siempre a un salto que desconcierta.
    return (visible - caja.bottom) < alto && caja.top > (visible - caja.bottom);
};

const terminoExpediente = ref<string>('');
const inputExpediente = ref<HTMLInputElement | null>(null);
const expedienteArriba = ref(false);

// Se mide justo ANTES de que aparezcan las opciones, no después: medir con el desplegable ya
// pintado devuelve la posición con él dentro y la cuenta sale al revés.
watch(terminoExpediente, () => { expedienteArriba.value = abreHaciaArriba(inputExpediente.value); });
const resultadosExpediente = ref<ExpedienteOpcion[]>([]);
const expedienteSeleccionado = ref<ExpedienteOpcion | null>(null);
const cotizacionesDelExpediente = ref<CotizacionOpcion[]>([]);
const cotizacionSeleccionada = ref<string>('');
const buscandoExpediente = ref<boolean>(false);

// ── Persistencia de filtros en localStorage ──────────────────────────────────
// Entrar a la cotización y volver dejaba La Biblia en blanco (fechas de hoy, sin filtros). Se
// guardan las fechas y las selecciones para reconstruir el contexto. Va aquí, tras declararse
// todos los refs de filtro, y se restaura ANTES de la carga inicial de onMounted.
const FILTROS_STORAGE_KEY = 'biblia:filtros';

const restaurarFiltros = (): void => {
    try {
        const raw = localStorage.getItem(FILTROS_STORAGE_KEY);
        if (!raw) return;
        const f = JSON.parse(raw) as Record<string, unknown>;
        if (typeof f.desde === 'string') desde.value = f.desde;
        if (typeof f.hasta === 'string') hasta.value = f.hasta;
        // ⚠️ Si el CATÁLOGO de tipos cambió desde que se guardó, el filtro de tipos se descarta.
        //
        // Un filtro guardado no puede saber de un tipo que aún no existía, así que al añadir
        // `contacto` los servicios de ese tipo desaparecieron del cuadro — sin error, sin hueco y
        // sin nada que lo delatara: nueve filas en la base, ocho en pantalla. Se tarda en notar
        // porque un filtro que oculta de más se parece mucho a «no hay nada ese día».
        //
        // Se falla ABIERTO —se enseña de más, no de menos—: perder el filtro una vez es una
        // molestia; perder una fila es una orden que no se emite.
        //
        // Sólo para `tipos`, que es un enum del código y cambia una vez al año. Los lugares salen
        // de la base y se crean a menudo: reiniciar su filtro cada vez sería peor que el problema.
        const catalogoGuardado = Array.isArray(f.tiposCatalogo) ? (f.tiposCatalogo as string[]) : null;
        const catalogoActual = TIPOS_COMPONENTE.map((t) => t.value);
        const catalogoIgual = catalogoGuardado !== null
            && catalogoGuardado.length === catalogoActual.length
            && catalogoActual.every((t) => catalogoGuardado.includes(t));

        if (Array.isArray(f.tipos) && catalogoIgual) tiposSeleccionados.value = f.tipos as string[];
        if (Array.isArray(f.lugares)) lugaresSeleccionados.value = f.lugares as string[];
        if (typeof f.estadoReserva === 'string') filtroEstadoReservaProveedor.value = f.estadoReserva;
        if (typeof f.estadoOperacion === 'string') filtroEstadoOperacion.value = f.estadoOperacion;
        if (typeof f.ordenPorHora === 'boolean') ordenPorHora.value = f.ordenPorHora;
        if (f.filtroOs === '' || f.filtroOs === 'sin' || f.filtroOs === 'con') filtroOs.value = f.filtroOs;
        if (f.expediente && typeof f.expediente === 'object') expedienteSeleccionado.value = f.expediente as ExpedienteOpcion;
        if (typeof f.cotizacion === 'string') cotizacionSeleccionada.value = f.cotizacion;
    } catch { /* storage corrupto: se ignora y arranca con los defaults */ }
};

restaurarFiltros();

watch(
    [desde, hasta, tiposSeleccionados, lugaresSeleccionados, filtroEstadoReservaProveedor,
     filtroEstadoOperacion, filtroOs, expedienteSeleccionado, cotizacionSeleccionada, ordenPorHora],
    () => {
        try {
            localStorage.setItem(FILTROS_STORAGE_KEY, JSON.stringify({
                desde: desde.value, hasta: hasta.value,
                tipos: tiposSeleccionados.value, lugares: lugaresSeleccionados.value,
                // La foto del catálogo con la que se guardó: si cambia, el filtro de tipos
                // se descarta al restaurar. Ver `restaurarFiltros()`.
                tiposCatalogo: TIPOS_COMPONENTE.map((t) => t.value),
                estadoReserva: filtroEstadoReservaProveedor.value,
                estadoOperacion: filtroEstadoOperacion.value,
                ordenPorHora: ordenPorHora.value,
                filtroOs: filtroOs.value,
                expediente: expedienteSeleccionado.value,
                cotizacion: cotizacionSeleccionada.value,
            }));
        } catch { /* sin storage disponible: no pasa nada */ }
    },
    { deep: true },
);

const filtrosActivos = computed<FiltrosBiblia>(() => ({
    desde: desde.value ? desde.value.slice(0, 10) : undefined,
    hasta: hasta.value ? hasta.value.slice(0, 10) : undefined,
    fileId: expedienteSeleccionado.value?.id,
    cotizacionId: cotizacionSeleccionada.value || undefined,
    tipos: tiposSeleccionados.value.length ? tiposSeleccionados.value : undefined,
    lugares: lugaresSeleccionados.value.length ? lugaresSeleccionados.value : undefined,
    estadoReservaProveedor: filtroEstadoReservaProveedor.value || undefined,
    estadoOperacion: filtroEstadoOperacion.value || undefined,
}));

/** El backend tiene más servicios en este rango de los que cabe pintar. */
const hayServiciosOcultos = computed(() =>
    operacionStore.totalServicios > operacionStore.servicios.length
);

/**
 * ¿Hay algo puesto en cualquiera de los cuatro ejes?
 *
 * Cubre TODOS, no sólo los del antiguo panel «Filtros»: es lo que decide si se ofrece «Limpiar»,
 * y un eje que no se contara aquí quedaría sin forma de vaciarse de un gesto.
 */
const hayFiltrosPuestos = computed(() =>
    tiposSeleccionados.value.length > 0
    || lugaresSeleccionados.value.length > 0
    || organizacionesSeleccionadas.value.length > 0
    || !!filtroEstadoReservaProveedor.value
    || !!filtroEstadoOperacion.value
    || !!filtroOs.value
    || !!expedienteSeleccionado.value
);

/** Cuántos filtros lleva puestos cada eje. Es el contador del rótulo con el grupo cerrado. */
const conteoPorGrupo = computed<Record<GrupoFiltro, number>>(() => ({
    lugar: lugaresSeleccionados.value.length,
    organizacion: organizacionesSeleccionadas.value.length,
    tipo: tiposSeleccionados.value.length,
    estado: (filtroOs.value ? 1 : 0)
        + (filtroEstadoReservaProveedor.value ? 1 : 0)
        + (filtroEstadoOperacion.value ? 1 : 0),
}));

// ============================================================================
// CARGA
// ============================================================================
/**
 * Mover el inicio ARRASTRA el fin, conservando el ancho de la ventana.
 *
 * Antes sólo se corregía el caso imposible (`hasta < desde`). El resultado era que adelantar
 * el inicio un mes dejaba el fin donde estaba y la consulta pasaba de una semana a cinco sin
 * que nadie lo pidiera. Un rango se mueve entero: es lo que uno espera al cambiar «desde».
 *
 * Y si el fin está vacío se copia el inicio, que es la lectura natural de «quiero ver ese día».
 */
const onCambiarDesde = (v: string): void => {
  const anterior = desde.value;
  desde.value = v;

  // 🔥 BORRAR la fecha es una acción legítima (la X del campo), y con `v` vacío el arrastre
  // hacía `new Date('')` → NaN, y salía a la API un `fechaServicio[before]=NaN-NaN-Na`.
  // El servidor lo aceptaba con un 200 y devolvía cualquier cosa, así que el síntoma era un
  // cuadro con datos que no correspondían al filtro. Visto en el access log al diagnosticar
  // otra cosa: nadie lo habría reportado, porque no falla — miente.
  if (!v) {
    cargarBiblia();   // no pide nada: `faltaAcotar` lo frena y la barra avisa
    return;
  }

  if (!hasta.value) {
    hasta.value = v;
  } else if (anterior && hasta.value >= anterior) {
    const dias = Math.round(
        (new Date(hasta.value.slice(0, 10)).getTime() - new Date(anterior.slice(0, 10)).getTime())
        / 86400000
    );
    hasta.value = `${sumarDias(v.slice(0, 10), dias)}T00:00`;
  } else if (hasta.value < v) {
    hasta.value = v;
  }

  cargarBiblia();
};

/**
 * Sin fechas sólo se busca si hay expediente. Es la única acotación que queda cuando no hay
 * rango: sin ninguna de las dos, la consulta trae la operación entera de la historia y el
 * cuadro deja de ser un cuadro de tráfico.
 */
const faltaAcotar = computed(() =>
    !expedienteSeleccionado.value && (!desde.value || !hasta.value));

const cargarBiblia = async () => {
    if (faltaAcotar.value) return;   // el aviso lo pinta la barra; aquí sólo no se pide

    seleccionados.value = [];
    await operacionStore.fetchServicios(filtrosActivos.value);
};

// `aplicar_cambio_horario` mueve servicios de La Biblia. Se recarga la pestaña que se está
// mirando, no las dos: pedir las órdenes mientras miras la Biblia es tráfico que nadie ve.
useRefrescoDelAsistente(() => { void (activeTab.value === 'biblia' ? cargarBiblia() : cargarOrdenes()); });

const cargarOrdenes = async () => {
    await operacionStore.fetchOrdenesServicio();
};

/**
 * Recargar, desde la cabecera y para la pestaña que se esté mirando.
 *
 * Estaba duplicado —uno en la barra de filtros de La Biblia y otro en la de Órdenes—, y en la de
 * La Biblia se comía el ancho donde ahora vive el buscador de expediente. Arriba está siempre en
 * el mismo sitio, que es lo que se busca a ciegas en un teléfono.
 */
const refrescar = async () => {
    if (activeTab.value === 'biblia') await cargarBiblia();
    else await cargarOrdenes();
};

const cambiarTab = async (tab: 'biblia' | 'ordenes') => {
    activeTab.value = tab;
    if (tab === 'biblia') await cargarBiblia();
    else await cargarOrdenes();
};

/**
 * Sólo «Hoy» y «Mañana»: los dos saltos que de verdad se piden desde un teléfono.
 *
 * El de «7 días» se retiró porque devolvía exactamente al rango de arranque —la vista ya nace con
 * la semana puesta—, y un botón que no lleva a ninguna parte nueva ocupa el sitio del expediente.
 */
const aplicarPreset = async (preset: 'hoy' | 'manana') => {
    const base = preset === 'hoy' ? hoyIso() : sumarDias(hoyIso(), 1);
    desde.value = `${base}T00:00`;
    hasta.value = `${base}T00:00`;
    await cargarBiblia();
};

const limpiarFiltros = async () => {
    tiposSeleccionados.value = [];
    lugaresSeleccionados.value = [];
    // Los dos que filtran EN LOCAL entran aquí igual: si «Limpiar» los dejaba puestos, el cuadro
    // seguía recortado después de limpiar y no había nada en pantalla que lo explicara.
    organizacionesSeleccionadas.value = [];
    filtroOs.value = '';
    filtroEstadoReservaProveedor.value = '';
    filtroEstadoOperacion.value = '';
    expedienteSeleccionado.value = null;
    cotizacionSeleccionada.value = '';
    cotizacionesDelExpediente.value = [];
    terminoExpediente.value = '';
    resultadosExpediente.value = [];
    await cargarBiblia();
};

const alternarTipo = async (tipo: string) => {
    const i = tiposSeleccionados.value.indexOf(tipo);
    if (i === -1) tiposSeleccionados.value.push(tipo);
    else tiposSeleccionados.value.splice(i, 1);
    await cargarBiblia();
};

const alternarLugar = async (id: string) => {
    const i = lugaresSeleccionados.value.indexOf(id);
    if (i === -1) lugaresSeleccionados.value.push(id);
    else lugaresSeleccionados.value.splice(i, 1);
    await cargarBiblia();
};

/**
 * ¿Se le puede pedir a un proveedor? **Espejo de `OperacionServicio::esComprable()` (PHP).**
 * Si cambia allá, cambia aquí.
 *
 * Es más estrecho que `!soloReferencia`: excluye también lo cancelado y lo reemplazado, que
 * conservan tarifa y por tanto pasaban todas las demás comprobaciones. La entidad lo vuelve a
 * validar al asignar la OS; esto sólo evita ofrecer en pantalla algo que acabaría en un 422.
 *
 * ⚠️ Va declarado AQUÍ, antes de `organizacionesDelCuadro`, y no junto a `conflictoSeleccion`,
 * que es su otro consumidor. El `watch` sobre ese computed lo evalúa durante el `setup`, así que
 * con la declaración más abajo reventaba con un `ReferenceError` — y el typecheck no lo ve.
 *
 * `conflictoSeleccion()` enumera estas tres condiciones por separado a propósito: necesita decir
 * CUÁL falla para explicárselo al operador, y un booleano no lo dice.
 */
const esComprable = (s: OperacionServicio): boolean =>
    !s.soloReferencia
    && s.estadoComponente !== 'cancelado'
    && s.modoComponente !== 'reemplazado';

/**
 * Las organizaciones que HAY en el cuadro cargado, en los dos papeles. Es la lista de chips.
 *
 * Sale de las filas y no del catálogo a propósito: el catálogo de proveedores es largo y la
 * mitad no aparece en estas fechas. Aquí cada chip que se ve trae al menos una fila.
 *
 * ⚠️ **Primero las que pueden RECIBIR una orden, y marcadas.** La Orden de Servicio agrupa por
 * comprador (`conflictoSeleccion`, `abrirModalOs`), así que una organización que en este cuadro
 * sólo aparece como prestador no va a recibir ninguna: su orden se la lleva quien le compra. Las
 * dos clases se leían igual en la lista y no había forma de distinguirlas —los dos papeles caen
 * en el mismo saco—, así que se elegía un prestador esperando poder emitirle y no salía nada.
 *
 * `recibeOrdenes` pide además que la fila sea COMPRABLE: si todo lo que compra esa organización
 * en este rango es referencia o está cancelado, la orden no llegaría a existir y la marca estaría
 * prometiendo algo que no se cumple.
 */
const organizacionesDelCuadro = computed<{ id: string; nombre: string; recibeOrdenes: boolean }[]>(() => {
    const nombres = new Map<string, string>();
    const compradoresConCompra = new Set<string>();

    for (const s of operacionStore.servicios) {
        const papeles: [string | null | undefined, string | null | undefined][] = [
            [s.prestadorEfectivoMaestroId, s.prestadorEfectivoNombre],
            [s.compradorEfectivoMaestroId, s.compradorEfectivoNombre],
        ];

        for (const [id, nombre] of papeles) {
            if (id && nombre) nombres.set(id, nombre);
        }

        if (s.compradorEfectivoMaestroId && esComprable(s)) {
            compradoresConCompra.add(s.compradorEfectivoMaestroId);
        }
    }

    return [...nombres.entries()]
        .map(([id, nombre]) => ({ id, nombre, recibeOrdenes: compradoresConCompra.has(id) }))
        // Las que reciben órdenes arriba; dentro de cada bloque, alfabético. Ordenar sólo por
        // nombre mezclaba las dos clases y la marca había que ir a buscarla chip por chip.
        .sort((a, b) => Number(b.recibeOrdenes) - Number(a.recibeOrdenes)
            || a.nombre.localeCompare(b.nombre, 'es'));
});

/** ¿Hay alguna fila sin ninguna de las dos organizaciones? Sólo entonces el chip «Sin asignar» dice algo. */
const hayServiciosSinOrganizacion = computed(() => operacionStore.servicios.some(
    s => !s.prestadorEfectivoMaestroId && !s.compradorEfectivoMaestroId));

/**
 * Los rótulos de la fila de ejes, en orden.
 *
 * ⚠️ LUGAR y ORGANIZACIÓN se esconden cuando no tienen nada que ofrecer. Un rótulo que abre a una
 * lista vacía se lee como una avería —«el filtro no carga»— cuando lo que pasa es que no hay de
 * qué filtrar: los lugares vienen del catálogo y las organizaciones, de las filas ya cargadas.
 * TIPO y ESTADO son enums del código y siempre tienen opciones.
 */
const gruposVisibles = computed<{ k: GrupoFiltro; label: string; icon: string }[]>(() => [
    // ⚠️ El `|| lugaresSeleccionados.length` NO sobra. Los lugares elegidos se restauran de
    // `localStorage` antes de que cargue el catálogo, y viajan AL SERVIDOR. Si `fetchLugares()`
    // falla —su `catch` se traga el error y deja la lista vacía—, el rótulo desaparecía con el
    // filtro puesto: cuadro recortado, sin contador y sin chips que quitar. Es exactamente el
    // fallo que costó las filas de tipo `contacto`, y aquí lo reintroducía esconder el rótulo.
    ...(operacionStore.lugares.length || lugaresSeleccionados.value.length
        ? [{ k: 'lugar' as const, label: 'Lugar', icon: 'fas fa-map-marker-alt' }] : []),
    ...(organizacionesDelCuadro.value.length || hayServiciosSinOrganizacion.value
        ? [{ k: 'organizacion' as const, label: 'Organización', icon: 'fas fa-truck' }] : []),
    { k: 'tipo', label: 'Tipo', icon: 'fas fa-layer-group' },
    { k: 'estado', label: 'Estado', icon: 'fas fa-flag' },
]);

/**
 * ⚠️ Si el eje abierto deja de existir, se cierra.
 *
 * Cambiar el rango puede vaciar la lista de organizaciones y llevarse su rótulo por delante. Con
 * el grupo abierto quedaba un bloque de opciones colgando sin rótulo que lo cerrara: había que
 * recargar para salir de ahí.
 */
watch(gruposVisibles, (grupos) => {
    if (grupoAbierto.value && !grupos.some(g => g.k === grupoAbierto.value)) grupoAbierto.value = null;
});

/**
 * ¿Conviven en el cuadro organizaciones que reciben órdenes con otras que no?
 *
 * Sólo entonces la leyenda de la marca dice algo: con todas iguales explicaría una distinción
 * que no se ve, que es ruido en la única franja que le queda al cuadro.
 */
const hayMezclaDePapeles = computed(() =>
    organizacionesDelCuadro.value.some(o => o.recibeOrdenes)
    && organizacionesDelCuadro.value.some(o => !o.recibeOrdenes));

const alternarOrganizacion = (id: string): void => {
    const i = organizacionesSeleccionadas.value.indexOf(id);
    if (i === -1) organizacionesSeleccionadas.value.push(id);
    else organizacionesSeleccionadas.value.splice(i, 1);
};

/**
 * ⚠️ Se sueltan las organizaciones que ya no están en el cuadro.
 *
 * El filtro se calcula sobre lo cargado, así que al cambiar el rango o los filtros del servidor
 * una organización seleccionada puede desaparecer de la lista. Su chip dejaría de pintarse pero
 * seguiría filtrando: el cuadro se quedaría vacío y **nada en pantalla diría por qué**, que es
 * justo el fallo que el chip «Sin etiqueta» de los lugares vino a evitar. Se falla ABIERTO.
 */
watch(organizacionesDelCuadro, (organizaciones) => {
    const vivas = new Set(organizaciones.map(e => e.id));

    organizacionesSeleccionadas.value = organizacionesSeleccionadas.value.filter(
        id => id === SIN_ORGANIZACION ? hayServiciosSinOrganizacion.value : vivas.has(id));
});

/** ¿Esta fila entra con el filtro de organización puesto? Los dos papeles, en OR. */
const coincideOrganizacion = (s: OperacionServicio): boolean => {
    const elegidas = organizacionesSeleccionadas.value;
    if (!elegidas.length) return true;

    const prestador = s.prestadorEfectivoMaestroId ?? null;
    const comprador = s.compradorEfectivoMaestroId ?? null;

    if (elegidas.includes(SIN_ORGANIZACION) && !prestador && !comprador) return true;

    return (prestador !== null && elegidas.includes(prestador))
        || (comprador !== null && elegidas.includes(comprador));
};

// ── Buscador de expediente (debounce manual, sin dependencias) ──────────────
let temporizadorBusqueda: ReturnType<typeof setTimeout> | null = null;

watch(terminoExpediente, (termino) => {
    if (temporizadorBusqueda) clearTimeout(temporizadorBusqueda);
    if (termino.trim().length < 2) {
        resultadosExpediente.value = [];
        return;
    }
    buscandoExpediente.value = true;
    temporizadorBusqueda = setTimeout(async () => {
        resultadosExpediente.value = await operacionStore.buscarExpedientes(termino);
        buscandoExpediente.value = false;
    }, 300);
});

const elegirExpediente = async (exp: ExpedienteOpcion) => {
    expedienteSeleccionado.value = exp;
    terminoExpediente.value = '';
    resultadosExpediente.value = [];
    cotizacionSeleccionada.value = '';
    cotizacionesDelExpediente.value = await operacionStore.fetchCotizacionesDeExpediente(exp.id);
    await cargarBiblia();
};

const quitarExpediente = async () => {
    expedienteSeleccionado.value = null;
    cotizacionSeleccionada.value = '';
    cotizacionesDelExpediente.value = [];
    await cargarBiblia();
};

// ============================================================================
// AGRUPACIÓN POR DÍA
//
// El backend ya ordena por fechaServicio y horaRecojo. Aquí sólo se parte en
// días y se empujan al final los servicios sin hora, ordenados por prioridad
// operativa (guiado/transporte antes que tickets): un cuadro de tráfico se lee
// de arriba abajo por hora, y lo que no tiene hora estorba en medio.
// ============================================================================
/** La hora con la que se ordena: la misma que pinta la fila. Vacío = no tiene hora ninguna. */
const horaDeOrden = (s: OperacionServicio): string => s.horaRecojo || s.horaComponente || '';

interface GrupoDia {
    fecha: string;
    servicios: OperacionServicio[];
}

const serviciosPorDia = computed<GrupoDia[]>(() => {
    const mapa = new Map<string, OperacionServicio[]>();

    for (const s of operacionStore.servicios) {
        // El toggle de OS filtra AQUÍ y no en el servidor: es lectura sobre lo ya cargado.
        if (filtroOs.value === 'sin' && s.ordenServicio) continue;
        if (filtroOs.value === 'con' && !s.ordenServicio) continue;

        // Organización: también sobre lo cargado, por lo mismo que el toggle de OS.
        if (!coincideOrganizacion(s)) continue;

        const fecha = (s.fechaServicio ?? '').slice(0, 10) || 'sin-fecha';
        if (!mapa.has(fecha)) mapa.set(fecha, []);
        mapa.get(fecha)!.push(s);
    }

    return [...mapa.entries()]
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([fecha, servicios]) => ({
            fecha,
            servicios: [...servicios].sort((a, b) => {
                // ── Por ITINERARIO (defecto) ────────────────────────────────
                // Es como se lee un viaje. Un cuadro ordenado sólo por reloj parte el relato: el
                // alojamiento y la comida, que no tienen hora, caen al final del día lejos del
                // servicio al que pertenecen.
                if (!ordenPorHora.value) {
                    const oa = a.ordenItinerario ?? Number.MAX_SAFE_INTEGER;
                    const ob = b.ordenItinerario ?? Number.MAX_SAFE_INTEGER;
                    if (oa !== ob) return oa - ob;
                }

                // ── Por RELOJ ───────────────────────────────────────────────
                // ⚠️ La MISMA hora que se pinta en la fila: la de recojo si la hay, y si no la
                // vendida. Ordenar sólo por `horaRecojo` mandaba al fondo del día a todo el que
                // no tuviera un recojo pactado —la mayoría— aunque su hora estuviera ahí
                // delante: el cuadro enseñaba «10:00» y lo colocaba después de las 08:30.
                const ha = horaDeOrden(a);
                const hb = horaDeOrden(b);
                if (ha && hb) return ha.localeCompare(hb);
                if (ha) return -1;
                if (hb) return 1;
                return (a.prioridadOperativa ?? 9) - (b.prioridadOperativa ?? 9);
            }),
        }));
});

const DIAS = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

const etiquetaDia = (iso: string): string => {
    if (iso === 'sin-fecha') return 'Sin fecha';
    const [a, m, d] = iso.split('-').map(Number);
    const fecha = new Date(a, m - 1, d);
    const sufijo = iso === hoyIso() ? ' · HOY' : '';
    return `${DIAS[fecha.getDay()]} ${d} ${MESES[m - 1]}${sufijo}`;
};

// ============================================================================
// EDICIÓN EN LÍNEA
// ============================================================================
const guardando = ref<string | null>(null);

const guardarCampo = async (servicio: OperacionServicio, payload: Record<string, unknown>) => {
    if (!servicio.id) return;
    guardando.value = servicio.id;
    try {
        await operacionStore.actualizarServicio(servicio.id, payload);
    } catch {
        // El store ya registra el error; se recarga para no dejar la fila mintiendo.
        await cargarBiblia();
    } finally {
        guardando.value = null;
    }
};

/**
 * La hora se edita con un input de texto y no con <input type="time">: el nativo
 * cambia a AM/PM según el idioma del sistema y aquí se trabaja siempre en 24 h,
 * el mismo motivo por el que las fechas usan FechaHoraPicker.
 */
const PATRON_HORA = /^([01]\d|2[0-3]):([0-5]\d)$/;

/**
 * Dónde recoge y dónde deja: el override del operador sobre lo que dice el catálogo.
 *
 * El campo va VACÍO cuando manda el catálogo, y su marcador de posición es el valor derivado —el
 * mismo patrón que la hora de recojo frente a la vendida. Así se ve de un vistazo qué filas se
 * tocaron a mano (texto negro) y cuáles siguen el maestro (texto gris), y vaciarlo devuelve el
 * control al catálogo en vez de dejar un hueco.
 */
/**
 * «Recoge en X → deja en Y» para la tarjeta de la orden.
 *
 * ⚠️ Espejo de `OperacionOrdenServicioItem::rutaParaLaOrden()`, y a propósito: aquí se pinta lo
 * que TODAVÍA no está congelado —la orden puede estar en borrador y no tener ítems—, así que no
 * hay de dónde leerlo. Si cambia la redacción allí, cambia también aquí.
 *
 * Si los dos extremos coinciden se dice una vez: repetirlo enseña a no leerlo.
 */
const rutaDe = (servicio: OperacionServicio): string | null => {
    const e = puntosDe(servicio)?.efectivo;
    if (!e) return null;

    const r = (e.recojo ?? '').trim();
    const d = (e.entrega ?? '').trim();

    if (r && d && r !== d) return `Recoge en ${r} → deja en ${d}`;
    if (r && d) return `Recoge y deja en ${r}`;
    if (r) return `Recoge en ${r}`;
    if (d) return `Deja en ${d}`;

    return null;
};

const puntosDe = (servicio: OperacionServicio) => operacionStore.puntosDerivados[servicio.id ?? ''] ?? null;

const editarHora = async (servicio: OperacionServicio, evento: Event) => {
    const input = evento.target as HTMLInputElement;
    const valor = input.value.trim();

    if (valor === '') {
        await guardarCampo(servicio, { horaRecojo: null });
        return;
    }
    if (!PATRON_HORA.test(valor)) {
        input.value = servicio.horaRecojo ?? '';
        return;
    }
    await guardarCampo(servicio, { horaRecojo: valor });
};

/**
 * Los tres papeles se editan sobre su columna REAL, nunca sobre la cotizada.
 *
 * Es lo que hace que la sincronización con la cotización deje de ser un problema: el snapshot
 * escribe sólo la cotizada y el operador sólo la real, así que nadie pisa a nadie y no hay
 * conflicto que resolver. Lo que vale es `…Efectivo…`, que el backend ya calcula.
 *
 * ⚠️ **Se guarda id + nombre, no el texto tecleado.** La Orden de Servicio agrupa por
 * comprador: «Futurismo» y «Futurismo Jonathan» serían dos órdenes distintas. Por eso el input
 * va contra un `<datalist>` del catálogo y, si lo escrito no casa con ninguna organización, se
 * revierte en vez de guardar un nombre suelto.
 */
const resolverOrganizacion = (texto: string): ProveedorOpcion | null =>
    operacionStore.proveedores.find(p => p.nombreComercial.toLowerCase() === texto.toLowerCase()) ?? null;

/**
 * Avisos efímeros de las ÓRDENES (actualizar, reemitir), en una franja flotante.
 *
 * Los nombres de prestador y proveedor ya no pasan por aquí: se escriben en el formulario de la
 * ficha, y si no están en el catálogo el error sale junto al botón de guardar, que es donde se
 * está mirando. Una franja flotante para un campo recién tocado da la noticia lejos.
 */
const avisoPapel = ref<string | null>(null);

/** Lo que dijo la cotización, para enseñarlo al lado cuando operaciones puso otra cosa. */
const cotizadoDe = (s: OperacionServicio): string | null => {
    if (!s.papelIntervenido) return null;

    const partes: string[] = [];
    if (s.prestadorOverrideNombre && s.prestadorNombre) partes.push(s.prestadorNombre);
    if (s.compradorOverrideNombre && s.compradorNombre) partes.push(s.compradorNombre);

    return partes.length ? `Cotizado: ${partes.join(' · ')}` : null;
};

/**
 * El proveedor comercial se oculta SÓLO cuando es redundante: cuando ya dice
 * exactamente lo mismo que el prestador. En la mayoría de filas ambos heredan de la
 * misma tarifa y repetirlo en cada línea convertiría el dato en ruido.
 *
 * ⚠️ Vacío NO es redundante, es pendiente. La primera versión pedía además que el campo
 * no estuviera vacío, y como salía de la misma tarifa que el prestador, cuando esa tarifa
 * no tenía proveedor **los dos** nacían nulos: el input no se pintaba nunca y el campo
 * quedaba fuera del alcance del operador. Justo el caso más común, y justo el que hace
 * falta para agrupar una Orden de Servicio.
 *
 * Con el comprador eso se da mucho menos: la cascada cae al proveedor del componente, así
 * que el campo llega lleno salvo que no haya proveedor en absoluto.
 */
const mostrarComprador = (s: OperacionServicio): boolean => {
    if (s.soloReferencia) return false;   // no se compra: no hay proveedor que fijar

    const comprador = (s.compradorEfectivoNombre ?? '').trim();
    return comprador === '' || comprador !== (s.prestadorEfectivoNombre ?? '').trim();
};

/** Teléfono en formato marcable: el operador llama desde el propio cuadro. */
const telHref = (telefono?: string | null): string => `tel:${(telefono ?? '').replace(/[^\d+]/g, '')}`;

/**
 * El contacto del recojo NO sale de la fila: sale del catálogo, resuelto en lote al cargar
 * el cuadro (`operacionStore.resolverContactoDeProveedores`). Se lee por aquí, y no
 * `servicio.prestadorTelefono` a pelo, porque ese campo hoy llega siempre vacío —el
 * snapshot dejó de congelarlo— y sólo sirve como respaldo de proveedores sin maestro.
 */
const telefonoDe = (s: OperacionServicio): string | null => operacionStore.contactoDePrestador(s).telefono;
const direccionDe = (s: OperacionServicio): string | null => operacionStore.contactoDePrestador(s).direccion;
// Mono-segmento sin plantilla: el nombre interno del segmento manda sobre el genérico del servicio.
const nombreSegmentoDe = (s: OperacionServicio): string | null => operacionStore.nombreSegmentoDeServicio(s);
// Nombre interno del componente (Guía, Transporte…): identifica la fila dentro de un servicio.
const nombreComponenteDe = (s: OperacionServicio): string | null => operacionStore.nombreComponenteDeServicio(s);

// ============================================================================
// COSTO REAL — lo que de verdad se pagó, frente a lo que decía la cotización
//
// `costoCotizado` viene del snapshot y lo gobierna la cotización: la reconciliación
// puede cambiarlo. `costoNegociado` es del operador y **nadie más lo toca** —
// ni el snapshot ni la reconciliación—, porque es el único sitio donde vive lo que
// el proveedor acabó cobrando. El delta entre ambos es el margen operativo.
//
// La MONEDA también se edita, desde 2026-08-17. Antes no, con el argumento de que
// mezclar divisas en la misma columna era invitar al error — pero el importe dejó de
// vivir en la cabecera de la orden y ahora se suma POR MONEDA sin convertir, así que
// mezclarlas es correcto: se cotiza en dólares y se cierra en soles con el mismo
// proveedor. Ver docs/Operacion.md §5.4.
//
// El editor en sí vive en `EditorCostoNegociado`, con estado local por fila. Tres copias de
// este bloque compartiendo dos Record globales fueron la causa del bug del importe replicado.
// ============================================================================



/** Diferencia real − cotizado. Positiva = costó más de lo previsto. */
const deltaOperativo = (s: OperacionServicio): number | null => {
    const real = Number(s.costoNegociado ?? 0);
    if (real === 0) return null;   // sin registrar: no hay delta que enseñar
    return real - Number(s.costoCotizado ?? 0);
};

const importe = (v?: string | null): string => Number(v ?? 0).toFixed(2);

// ============================================================================
// SELECCIÓN Y GENERACIÓN DE ORDEN DE SERVICIO
// ============================================================================
const seleccionados = ref<string[]>([]);

const alternarSeleccion = (id?: string | null) => {
    if (!id) return;
    const i = seleccionados.value.indexOf(id);
    if (i === -1) seleccionados.value.push(id);
    else seleccionados.value.splice(i, 1);
};

const serviciosSeleccionados = computed(() =>
    operacionStore.servicios.filter(s => s.id && seleccionados.value.includes(s.id))
);

/**
 * Una OS es una solicitud a UN proveedor sobre UN expediente: agrupar servicios de
 * expedientes o proveedores distintos produciría un documento que nadie puede firmar.
 */
const conflictoSeleccion = computed<string | null>(() => {
    const sel = serviciosSeleccionados.value;
    if (sel.length === 0) return null;

    // Sólo se compra lo que se opera y se compra. Lo calcula el backend
    // (OperacionServicio::esComprable) y lo vuelve a defender al asignar la OS; aquí
    // sólo se evita que el operador llegue hasta el modal para recibir un 422.
    if (sel.some(s => s.soloReferencia)) {
        return 'Hay servicios de referencia (no incluidos o sin tarifa) en la selección: no se compran a ningún proveedor.';
    }
    // Un componente cancelado o reemplazado conserva su tarifa, así que pasaba todas las
    // demás comprobaciones: la fila sale atenuada, pero atenuar no impide marcarla.
    if (sel.some(s => s.estadoComponente === 'cancelado')) {
        return 'Hay servicios cancelados en la cotización: pedirlos sería comprar algo que el cliente no quiere.';
    }
    if (sel.some(s => s.modoComponente === 'reemplazado')) {
        return 'Hay servicios reemplazados por otros en la cotización.';
    }

    const files = new Set(sel.map(s => s.file?.id ?? ''));
    if (files.size > 1) return 'Los servicios seleccionados son de expedientes distintos.';

    // Se agrupa por COMPRADOR, no por proveedor: la orden va a quien la ejecuta. Dos
    // servicios de proveedores distintos que compra Futurismo caben en la misma orden;
    // agrupar por proveedor los partía en dos sin motivo.
    // Por el id EFECTIVO, no por el texto: un nombre tecleado no agrupa con la ficha del
    // catálogo aunque se lea igual, y el efectivo es el que respeta lo que decidió operaciones.
    const compradores = new Set(sel.map(s => s.compradorEfectivoMaestroId ?? ''));
    if (compradores.size > 1) return 'Los servicios seleccionados tienen compradores distintos.';

    // 🔓 Las monedas distintas YA NO bloquean. Bloqueaban porque el total vivía en la
    // cabecera de la orden y mezclar dejaba una suma sin sentido; ahora el importe vive en
    // cada ítem con su moneda y la pantalla suma por moneda. Un proveedor que cobra unos
    // servicios en soles y otros en dólares es una sola gestión, no dos órdenes.

    if (sel.some(s => s.ordenServicio)) return 'Algún servicio ya pertenece a una Orden de Servicio.';

    return null;
});

const mostrarModalOs = ref<boolean>(false);
const guardandoOs = ref<boolean>(false);
const errorOs = ref<string | null>(null);
/** El catálogo, en la forma que pide el selector. */
const opcionesProveedores = computed(() =>
    operacionStore.proveedores.map(p => ({ value: p.id, label: p.nombreComercial })));

/**
 * Al elegir destinatario se guardan las DOS cosas: el id, que es lo que permite enviarle la
 * orden, y el nombre, que es lo que queda escrito si algún día se borra del catálogo.
 */
const onDestinatarioChange = (id: unknown): void => {
    const elegido = operacionStore.proveedores.find(p => p.id === String(id ?? ''));
    formOs.value.compradorNombre = elegido?.nombreComercial ?? '';
};

const formOs = ref({ numeroOs: '', compradorMaestroId: '' as string | null, compradorNombre: '' });

/**
 * Lo que suma la selección, POR MONEDA. Nunca un solo número.
 *
 * Sumar monedas distintas es el error que la conversión disimula: aquí no se convierte
 * —eso es criterio de la casa— así que salen tantas líneas como monedas haya.
 */
const totalesPorMoneda = computed<{ moneda: string; total: number }[]>(() => {
    const mapa = new Map<string, number>();

    for (const s of serviciosSeleccionados.value) {
        const m = s.monedaCotizada?.id ?? '—';
        mapa.set(m, (mapa.get(m) ?? 0) + Number(s.costoCotizado ?? 0));
    }

    return [...mapa.entries()]
        .map(([moneda, total]) => ({ moneda, total }))
        .sort((a, b) => a.moneda.localeCompare(b.moneda));
});

const abrirModalOs = () => {
    const sel = serviciosSeleccionados.value;
    if (sel.length === 0 || conflictoSeleccion.value) return;

    const hoy = hoyIso().replace(/-/g, '');

    formOs.value = {
        // Sugerencia: numeroOs es unique y no tiene generador en el backend.
        numeroOs: `OS-${hoy}-${String(Math.floor(Math.random() * 900) + 100)}`,
        // El EFECTIVO, que es por el que agrupa `conflictoSeleccion`. Con el cotizado, una
        // fila con override proponía la organización de la cotización mientras la comprobación de
        // «todos el mismo comprador» miraba otra: el modal se contradecía consigo mismo.
        compradorMaestroId: sel[0].compradorEfectivoMaestroId ?? '',
        compradorNombre: sel[0].compradorEfectivoNombre ?? '',
    };
    errorOs.value = null;
    abrirComoCapa('generar-os', () => { mostrarModalOs.value = false; });
    mostrarModalOs.value = true;
    void operacionStore.fetchProveedores();   // idempotente: sólo pega la primera vez
};

/**
 * Crea la orden. `emitir` decide si además **sale al proveedor**.
 *
 * ── Por qué ahora se puede elegir ───────────────────────────────────────────
 * Esto mandaba `soloBorrador: false` fijo, con el motivo escrito al lado: «el borrador existe
 * para componer, y aquí ya está compuesta». Era cierto **mientras no hubiera forma de emitir
 * después** —el estado vivía en un desplegable enterrado en el modal de edición—, así que crear
 * y emitir tenían que ser el mismo gesto.
 *
 * Con el botón «Emitir» en la tarjeta esa razón desapareció, y lo que quedaba era lo malo:
 * componer una orden obligaba a mandársela al proveedor en el acto, sin poder repasar el
 * destinatario, el número ni los importes negociados.
 */
/**
 * Las órdenes a las que se puede sumar lo que está marcado.
 *
 * Sólo BORRADORES —una emitida es un documento que el proveedor ya tiene— y sólo del mismo
 * expediente y el mismo comprador que la selección. Se filtra aquí para no ofrecer una opción que
 * el servidor va a rechazar: un desplegable con órdenes imposibles es un desplegable que se elige
 * mal. El servidor lo vuelve a comprobar de todos modos; esto es cortesía, no la guarda.
 */
const ordenesParaAgregar = computed(() => {
    const sel = serviciosSeleccionados.value;
    if (sel.length === 0 || conflictoSeleccion.value) return [];

    // ⚠️ Por ID EXTRAÍDO, no comparando los campos tal cual. `file` llega como objeto en el
    // servicio y como IRI en la orden, así que un `===` entre los dos no coincidía NUNCA y el
    // botón no aparecía jamás — con la orden compatible delante. Es la clase de fallo que no da
    // error: una condición que siempre es falsa se ve igual que «no hay nada que ofrecer».
    const comprador = sel[0].compradorEfectivoMaestroId ?? sel[0].compradorMaestroId ?? null;

    // Sale de `borradoresDelFile`, que el servidor ya devuelve acotados por estado y expediente
    // (`fetchBorradoresDeFile()`).
    //
    // ⚠️ **Se vuelve a comprobar el expediente AQUÍ, aunque el servidor ya lo filtre.** No es
    // redundancia inútil: entre marcar una fila del expediente A y otra del B salen dos peticiones
    // sin cancelar la primera, y si la de A llega la última, la lista es de A con la selección en
    // B. Filtrando sólo por comprador —que es el mismo proveedor en dos expedientes muy a menudo—
    // el botón ofrecía agregar filas de B a una orden de A. Lo paraba el 422 del servidor, pero
    // ofrecer algo imposible es la pantalla mintiendo. Esta línea cuesta nada y lo cierra. Antes se buscaba dentro de `ordenesServicio` —la primera página de la pestaña de
    // Órdenes—, así que el botón dependía de que el borrador cayera en esos 30 y de que alguien
    // hubiera abierto esa pestaña: recién entrado a La Biblia la lista estaba VACÍA y el botón
    // no aparecía nunca. Ver `fetchBorradoresDeFile()`.
    const fileId = fileDeLaSeleccion.value;

    return operacionStore.borradoresDelFile.filter(
        (o) => (o.compradorMaestroId ?? null) === comprador
            && idDeRecurso(o.file) === fileId
    );
});

/**
 * El expediente de lo seleccionado. Es la clave con la que se piden los borradores.
 *
 * `conflictoSeleccion` ya garantiza que la selección es de UN solo expediente, así que basta
 * mirar la primera fila.
 */
const fileDeLaSeleccion = computed<string | null>(() => {
    const sel = serviciosSeleccionados.value;

    return sel.length ? idDeRecurso(sel[0].file) : null;
});

// Se piden al marcar la primera fila, no al arrancar: quien sólo viene a mirar el cuadro no
// gasta una petición en ello. `fetchBorradoresDeFile` no repite si ya tiene los de ese
// expediente, así que marcar diez filas del mismo file es UNA llamada.
//
// ⚠️ Se vigila TAMBIÉN `fileDeBorradores`, no sólo la selección. Emitir o cancelar una orden
// invalida la caché desde el store, y si el único disparador fuera un cambio de selección, ese
// hueco no se rellenaba nunca: se emite un borrador desde la pestaña de Órdenes, se vuelve a La
// Biblia con lo mismo marcado y la selección no cambió. Con las dos fuentes, el refetch entra
// solo. No hay bucle: `fetchBorradoresDeFile` corta en seco si ya tiene los de ese expediente.
watch(
    [fileDeLaSeleccion, () => operacionStore.fileDeBorradores],
    ([fileId, cargado]) => {
        if (fileId && fileId !== cargado) void operacionStore.fetchBorradoresDeFile(fileId);
    },
    { immediate: true },
);

/**
 * El id de un recurso venga como venga: objeto con `id`, IRI («/platform/…/uuid») o el uuid pelado.
 *
 * La API devuelve la misma relación de las tres formas según el grupo de serialización, y
 * compararlas entre sí sin normalizar da siempre `false` — sin error y sin nada que lo delate.
 */
const idDeRecurso = (v: unknown): string | null => {
    if (typeof v === 'string') return v.split('/').pop() || null;

    if (v && typeof v === 'object') {
        const o = v as { id?: unknown; '@id'?: unknown };

        if (o.id !== undefined && o.id !== null) return String(o.id) || null;

        // ⚠️ CUARTA forma, y la que volvió a dejar el botón muerto: un objeto cuyo único
        // identificador es `@id`. Pasa cuando el recurso se serializa SIN el grupo que publica
        // `id`: el `file` de un OperacionServicio trae `id`, el de una OperacionOrdenServicio
        // no —sólo `@id`, `@type` y los timestamps—. Sin esta rama la función devolvía `null`
        // para las órdenes y `idDeRecurso(orden.file) === idDeRecurso(servicio.file)` era
        // `null === '019ec…'`: falso SIEMPRE, que es indistinguible de «no hay nada que ofrecer».
        if (typeof o['@id'] === 'string') return o['@id'].split('/').pop() || null;
    }

    return null;
};

const mostrarModalAgregar = ref(false);
const errorAgregar = ref<string | null>(null);
const agregandoA = ref<string | null>(null);

const agregarAOrden = async (ordenId?: string | null) => {
    if (!ordenId) return;
    const ids = serviciosSeleccionados.value.map(s => s.id!).filter(Boolean);
    if (ids.length === 0) return;

    agregandoA.value = ordenId;
    errorAgregar.value = null;
    try {
        await operacionStore.agregarAOrden(ordenId, ids);
        mostrarModalAgregar.value = false;
        seleccionados.value = [];
        await cargarBiblia();
    } catch (e) {
        // El servidor explica QUÉ falló —ya emitida, otro comprador, ya en otra orden—.
        errorAgregar.value = mensajeDeErrorApi(e, 'No se pudo agregar a la orden.');
    } finally {
        agregandoA.value = null;
    }
};

/**
 * Emitir pide DOS pulsaciones: la segunda es la confirmación.
 *
 * Emitir congela el contenido y no vuelve atrás —para cambiar algo hay que anular y reemitir—, y
 * el botón está justo al lado del seguro. Un diálogo aparte sería más ceremonioso; dos toques con
 * el botón diciendo «¿Seguro?» corta el error sin añadir una ventana más, y en el teléfono se
 * agradece.
 *
 * Se olvida sola a los 4 segundos: una confirmación que se queda armada indefinidamente es la
 * misma pulsación accidental, sólo que más tarde.
 */
const confirmandoEmitir = ref(false);
let tiempoConfirmarEmitir: ReturnType<typeof setTimeout> | null = null;

const pedirConfirmacionEmitir = () => {
    if (confirmandoEmitir.value) {
        if (tiempoConfirmarEmitir) clearTimeout(tiempoConfirmarEmitir);
        confirmandoEmitir.value = false;
        void confirmarOs(true);

        return;
    }

    confirmandoEmitir.value = true;
    tiempoConfirmarEmitir = setTimeout(() => { confirmandoEmitir.value = false; }, 4000);
};

const confirmarOs = async (emitir: boolean) => {
    const sel = serviciosSeleccionados.value;
    if (sel.length === 0) return;

    guardandoOs.value = true;
    errorOs.value = null;
    try {
        await operacionStore.emitirOrdenServicio({
            servicioIds: sel.map(s => s.id!).filter(Boolean),
            numeroOs: formOs.value.numeroOs,
            compradorMaestroId: formOs.value.compradorMaestroId || null,
            compradorNombre: formOs.value.compradorNombre || null,
            soloBorrador: !emitir,
        });
        // `capas.cerrar()` y no `= false`: el cierre a mano dejaría la entrada del historial
        // colgando, y el siguiente «atrás» se la comería sin hacer nada visible.
        capas.cerrar('generar-os');
        confirmandoEmitir.value = false;
        seleccionados.value = [];
        await cargarBiblia();
    } catch (e) {
        // El servidor explica QUÉ falló —expedientes distintos, un servicio ya en otra orden,
        // algo que no se compra—. Repetir un mensaje genérico encima de eso lo tapaba.
        errorOs.value = mensajeDeErrorApi(e, 'No se pudo emitir la Orden de Servicio.');
    } finally {
        guardandoOs.value = false;
    }
};

// ══ FICHA DEL SERVICIO (móvil) ═════════════════════════════════════════════
//
// ══ LA FICHA DE UN SERVICIO: LEER, Y LUEGO EDITAR ══════════════════════════
//
// El cuadro es una REJILLA DE FICHAS y la ficha es lo que se abre al tocar una. Antes esto era
// una tabla con seis columnas ocultas por debajo de `md`: en el teléfono no se convertía en
// ficha, se quedaba una tabla a la que le faltaban columnas y con todo apelotonado en una celda.
//
// ⚠️ **Tocar la ficha abre LEYENDO; la plumita entra a editar.** Recorrer cuarenta servicios es
// lo que más se hace en un día de tráfico, y en un formulario los datos están repartidos entre
// campos que hay que interpretar. Es el mismo reparto que el namelist del expediente.
//
// ⚠️ **Se edita sobre un BORRADOR y se guarda al confirmar.** En la tabla cada campo se mandaba
// al perder el foco y no había sitio para decir si había entrado; aquí «Guardar» es la respuesta
// a esa pregunta. Y guardar vuelve al detalle, no cierra: lo normal es mirar lo que acabas de
// cambiar.

const servicioFicha = ref<OperacionServicio | null>(null);
const guardandoFicha = ref(false);
const errorFicha = ref<string | null>(null);

/** `true` = detalle en lectura; `false` = formulario. */
const modoVistaFicha = ref(true);

interface BorradorFicha {
    horaRecojo: string;
    puntoRecojo: string;
    puntoEntrega: string;
    estadoReservaProveedor: string;
    estadoOperacion: string;
    visibilidadRecojo: string;
    visibilidadEntrega: string;
    notasPrestador: string;
    prestadorNombre: string;
    prestadorServicioNombre: string;
    compradorNombre: string;
}

const borradorFicha = ref<BorradorFicha>({
    horaRecojo: '', puntoRecojo: '', puntoEntrega: '',
    estadoReservaProveedor: '', estadoOperacion: '', visibilidadRecojo: 'auto', visibilidadEntrega: 'auto',
    notasPrestador: '', prestadorNombre: '', prestadorServicioNombre: '', compradorNombre: '',
});

/**
 * ¿Este servicio admite una hora propia?
 *
 * Se consulta **el flag del componente** (`CotizacionCotcomponente::$sinHorario`, que sale de
 * `ComponenteTipoEnum::sinHorario()`), no una copia de la regla en TypeScript: un ingreso al
 * Koricancha es un ticket de horario variable y no tiene hora que fijar, igual que un
 * alojamiento. La Biblia ofrecía el campo igualmente, y una hora escrita ahí es un dato que no
 * significa nada y que alguien acaba mandándole al proveedor.
 *
 * Si el flag no llegara —grupo de serialización caído—, se admite: esconder un campo que la
 * gente usa es peor que enseñar uno de más, y esto último se ve.
 */
const admiteHora = (servicio: OperacionServicio): boolean => {
    const componente = servicio.cotizacionComponente as { sinHorario?: boolean } | undefined;

    return componente?.sinHorario !== true;
};

/** Las fichas en el orden en que se ven, para saltar de una a otra con las flechas. */
const serviciosPlanos = computed<OperacionServicio[]>(() =>
    serviciosPorDia.value.flatMap(g => g.servicios));

const indiceDeFicha = computed(() => {
    const id = servicioFicha.value?.id;

    return id ? serviciosPlanos.value.findIndex(s => s.id === id) : -1;
});

const sembrarBorrador = (servicio: OperacionServicio): void => {
    borradorFicha.value = {
        horaRecojo: servicio.horaRecojo ?? '',
        puntoRecojo: servicio.puntoRecojo ?? '',
        puntoEntrega: servicio.puntoEntrega ?? '',
        estadoReservaProveedor: servicio.estadoReservaProveedor ?? 'sin-solicitar',
        estadoOperacion: servicio.estadoOperacion ?? 'pendiente',
        visibilidadRecojo: servicio.visibilidadRecojo ?? 'auto',
        visibilidadEntrega: servicio.visibilidadEntrega ?? 'auto',
        notasPrestador: (servicio.notasPrestador ?? []).join('\n'),
        prestadorNombre: servicio.prestadorEfectivoNombre ?? '',
        prestadorServicioNombre: servicio.prestadorServicioEfectivoNombre ?? '',
        compradorNombre: servicio.compradorEfectivoNombre ?? '',
    };
};

/**
 * Gira la ficha entre lectura y edición. **Siempre por el historial**, nunca a mano: así el
 * gesto atrás devuelve al detalle en vez de cerrar la ficha entera.
 */
const girarFicha = (aVista: boolean): void => {
    if (aVista) {
        capas.cerrar('servicio-edicion');   // el composable dispara el `alCerrar` que vuelve a lectura
        return;
    }

    capas.abrir('servicio-edicion', () => { modoVistaFicha.value = true; });
    modoVistaFicha.value = false;
};

/**
 * @param editar `true` sólo cuando se entra por la plumita. Abre las DOS capas —ficha y
 *               edición— para que atrás lleve al detalle y el siguiente atrás a la rejilla.
 */
const abrirFicha = (servicio: OperacionServicio, editar = false): void => {
    const yaAbierta = servicioFicha.value !== null;

    servicioFicha.value = servicio;
    errorFicha.value = null;
    sembrarBorrador(servicio);

    // Saltar de una ficha a otra con las flechas NO apila: es la misma capa cambiando de
    // contenido. Sin esto, recorrer diez servicios dejaba diez entradas de historial detrás.
    if (!yaAbierta) {
        modoVistaFicha.value = true;
        capas.abrir('servicio', () => { servicioFicha.value = null; modoVistaFicha.value = true; });
    }

    if (editar && modoVistaFicha.value) girarFicha(false);
};

/** Cierra la ficha RETROCEDIENDO: el cierre real lo hace el composable, como todo aquí. */
const cerrarFicha = (): void => capas.cerrar('servicio');

/** La ficha de al lado, en el orden en que se ven. Siempre en LECTURA. */
const irAFichaAdyacente = (paso: number): void => {
    const destino = serviciosPlanos.value[indiceDeFicha.value + paso];
    if (!destino) return;

    if (!modoVistaFicha.value) girarFicha(true);
    abrirFicha(destino);
};

/** Sólo lo que CAMBIÓ. Mandar el resto reescribiría campos que nadie tocó. */
const cambiosDeFicha = (): Record<string, unknown> => {
    const s = servicioFicha.value;
    if (!s) return {};

    const cambios: Record<string, unknown> = {};
    const b = borradorFicha.value;

    const texto = (v: string): string | null => (v.trim() === '' ? null : v.trim());

    if (texto(b.horaRecojo) !== (s.horaRecojo ?? null)) cambios.horaRecojo = texto(b.horaRecojo);
    if (texto(b.puntoRecojo) !== (s.puntoRecojo ?? null)) cambios.puntoRecojo = texto(b.puntoRecojo);
    if (texto(b.puntoEntrega) !== (s.puntoEntrega ?? null)) cambios.puntoEntrega = texto(b.puntoEntrega);
    if (b.estadoReservaProveedor !== s.estadoReservaProveedor) cambios.estadoReservaProveedor = b.estadoReservaProveedor;
    if (b.estadoOperacion !== s.estadoOperacion) cambios.estadoOperacion = b.estadoOperacion;
    if (b.visibilidadRecojo !== (s.visibilidadRecojo ?? 'auto')) cambios.visibilidadRecojo = b.visibilidadRecojo;
    if (b.visibilidadEntrega !== (s.visibilidadEntrega ?? 'auto')) cambios.visibilidadEntrega = b.visibilidadEntrega;

    if (texto(b.prestadorServicioNombre) !== (s.prestadorServicioEfectivoNombre ?? null)) {
        cambios.prestadorServicioOverrideNombre = texto(b.prestadorServicioNombre);
    }

    const notas = b.notasPrestador.split('\n').map(l => l.trim()).filter(l => l !== '');
    if (notas.join('\n') !== (s.notasPrestador ?? []).join('\n')) cambios.notasPrestador = notas.length ? notas : null;

    return cambios;
};

/**
 * Prestador y comprador, que NO son texto libre: son organizaciones del catálogo.
 *
 * Se resuelven aparte del resto porque pueden fallar —un nombre que no está dado de alta— y eso
 * corta el guardado entero con un motivo. En la tabla el input se revertía en silencio y nadie
 * se enteraba de por qué su cambio no había entrado.
 *
 * Vacío significa «vuelve a lo cotizado», que es distinto de «no hay nadie».
 *
 * @returns los cambios, o `null` si algún nombre no está en el catálogo.
 */
const cambiosDePapeles = (): Record<string, unknown> | null => {
    const s = servicioFicha.value;
    if (!s) return {};

    const b = borradorFicha.value;
    const cambios: Record<string, unknown> = {};

    const papeles = [
        { texto: b.prestadorNombre.trim(), actual: s.prestadorEfectivoNombre ?? '', rotulo: 'prestador',
          campoId: 'prestadorOverrideMaestroId', campoNombre: 'prestadorOverrideNombre' },
        { texto: b.compradorNombre.trim(), actual: s.compradorEfectivoNombre ?? '', rotulo: 'proveedor',
          campoId: 'compradorOverrideMaestroId', campoNombre: 'compradorOverrideNombre' },
    ];

    for (const papel of papeles) {
        if (papel.texto === papel.actual) continue;

        if (papel.texto === '') {
            cambios[papel.campoId] = null;
            cambios[papel.campoNombre] = null;
            continue;
        }

        const organizacion = resolverOrganizacion(papel.texto);

        if (!organizacion) {
            errorFicha.value = `«${papel.texto}» no está en el catálogo de organizaciones. `
                + `Dala de alta antes de ponerla como ${papel.rotulo}.`;

            return null;
        }

        cambios[papel.campoId] = organizacion.id;
        cambios[papel.campoNombre] = organizacion.nombreComercial;
    }

    return cambios;
};

const hayCambiosEnFicha = computed(() =>
    Object.keys(cambiosDeFicha()).length > 0
    || borradorFicha.value.prestadorNombre.trim() !== (servicioFicha.value?.prestadorEfectivoNombre ?? '')
    || borradorFicha.value.compradorNombre.trim() !== (servicioFicha.value?.compradorEfectivoNombre ?? ''));

const guardarFicha = async () => {
    const s = servicioFicha.value;
    if (!s?.id) return;

    errorFicha.value = null;

    const papeles = cambiosDePapeles();
    if (papeles === null) return;   // un nombre fuera del catálogo: ya lo dijo `cambiosDePapeles()`

    const cambios = { ...cambiosDeFicha(), ...papeles };

    if (Object.keys(cambios).length === 0) { girarFicha(true); return; }

    // La hora, si se escribe, tiene que ser una hora.
    if (typeof cambios.horaRecojo === 'string' && !PATRON_HORA.test(cambios.horaRecojo)) {
        errorFicha.value = 'La hora de recojo va en formato 24 h, por ejemplo 06:15.';
        return;
    }

    guardandoFicha.value = true;
    try {
        await operacionStore.actualizarServicio(s.id, cambios);
        await cargarBiblia();

        // Recargar reemplaza el array entero, así que la ficha apuntaba a un objeto muerto y el
        // detalle volvía con los datos de antes de guardar. Se vuelve a apuntar por id.
        const fresco = operacionStore.servicios.find(x => x.id === s.id);
        if (fresco) { servicioFicha.value = fresco; sembrarBorrador(fresco); }

        girarFicha(true);
    } catch (e) {
        errorFicha.value = mensajeDeErrorApi(e, 'No se pudo guardar.');
    } finally {
        guardandoFicha.value = false;
    }
};

// ══ ENVIAR LA ORDEN AL PROVEEDOR ═══════════════════════════════════════════
//
// ── Por qué se previsualiza ────────────────────────────────────────────────
// Porque es IRREVERSIBLE: un correo mandado no se retira. El documento sale de los ítems
// congelados y va a donde diga la identidad del proveedor, así que hay dos cosas que sólo se
// ven mirando —que las líneas son las pactadas y que va a la dirección correcta— y las dos se
// comprueban en dos segundos.
//
// ── Y por qué está separado de emitir ──────────────────────────────────────
// Emitir congela el contenido; enviar se lo pone delante a alguien de fuera. Separados,
// **reenviar no necesita nada nuevo**: es este mismo botón otra vez, que es lo normal cuando el
// proveedor perdió el correo o cambió de contacto.
const ordenAEnviar = ref<OperacionOrdenServicio | null>(null);
const documento = ref<DocumentoDeOrden | null>(null);

/**
 * El texto listo para pegar en WhatsApp, tal cual saldría por la API.
 *
 * 🪞 Espejo de `OperacionOrdenEnvio::enviar()`, que compone `cuerpo + "\n\n" + enlace`. Si allá
 * cambia la forma de pegarlos, aquí también: lo que se copia tiene que ser **lo mismo** que se
 * manda, o el proveedor del grupo recibe una versión y el del chat otra.
 *
 * ⚠️ Ya lleva el formato de WhatsApp puesto —`*negrita*` y los emoji— porque el cuerpo se compone
 * así en PHP. No se le añade nada aquí: un segundo juego de marcas encima del primero es lo que
 * convierte un mensaje en una ristra de asteriscos.
 */
const textoParaWhatsapp = computed<string>(() => {
    const doc = documento.value;

    if (doc === null) return '';

    return doc.enlace ? `${doc.cuerpo}\n\n${doc.enlace}` : doc.cuerpo;
});

/** Se apaga solo: un «copiado» que se queda fijo deja de significar «acaba de pasar». */
const copiado = ref<boolean>(false);
let temporizadorCopiado: ReturnType<typeof setTimeout> | null = null;

/**
 * Copiar para pegarlo a mano en un grupo.
 *
 * ⚠️ **No es un capricho: la API de Meta no puede escribir en los grupos que ya existen.** Desde
 * 2026 hay Groups API, pero sólo sobre grupos que crea el propio número por API, con invitación
 * —no se puede meter a nadie— y con un tope de 8 participantes. Un grupo que ya está montado con
 * un proveedor no se puede adoptar. Así que para ésos el camino es copiar y pegar, y lo que hay
 * que hacer bien es que el texto salga idéntico al que manda la API.
 */
/**
 * Escribe en el portapapeles por la vía que funcione. `true` si alguna lo consiguió.
 *
 * La segunda es `execCommand('copy')`, que está deprecada y sigue siendo la que funciona cuando
 * la moderna se niega: es síncrona y le basta el gesto del usuario. El `<textarea>` va fuera de
 * pantalla y no `display:none`, porque lo que no se pinta no se puede seleccionar.
 */
const escribirEnPortapapeles = async (texto: string): Promise<boolean> => {
    try {
        await navigator.clipboard.writeText(texto);

        return true;
    } catch { /* se prueba la siguiente */ }

    try {
        const caja = document.createElement('textarea');
        caja.value = texto;
        caja.setAttribute('readonly', '');
        caja.style.position = 'fixed';
        caja.style.left = '-9999px';
        document.body.appendChild(caja);
        caja.select();

        const bien = document.execCommand('copy');
        document.body.removeChild(caja);

        return bien;
    } catch {
        return false;
    }
};

const copiarParaWhatsapp = async (): Promise<void> => {
    const texto = textoParaWhatsapp.value;

    if (texto === '') return;

    // ⚠️ **Tres intentos, y no sobra ninguno.** `navigator.clipboard` es la vía buena pero se
    // niega en más casos de los que uno espera —sin contexto seguro, sin foco en el documento, o
    // sin gesto del usuario— y falla lanzando, no devolviendo `false`. Medido aquí mismo: con la
    // ventana sin foco tira `NotAllowedError` aunque el clic sea real.
    //
    // Un botón de copiar que a veces copia es peor que no tenerlo: el operador cree que lo lleva
    // y pega lo que hubiera antes en el portapapeles, que es un mensaje de otro proveedor.
    copiado.value = await escribirEnPortapapeles(texto);

    if (!copiado.value) {
        // Último recurso: se selecciona el bloque para que lo copie con el teclado. Peor que un
        // clic, mejor que un botón que no hace nada y no dice por qué.
        const bloque = document.getElementById('cuerpo-del-mensaje');

        if (bloque !== null) {
            const rango = document.createRange();
            rango.selectNodeContents(bloque);
            window.getSelection()?.removeAllRanges();
            window.getSelection()?.addRange(rango);
        }
    }

    if (temporizadorCopiado !== null) clearTimeout(temporizadorCopiado);
    temporizadorCopiado = setTimeout(() => { copiado.value = false; }, 2500);
};
const canalElegido = ref<string>('');
const cargandoDocumento = ref(false);
const enviandoOrden = ref(false);
const errorEnvio = ref<string | null>(null);

const abrirEnvio = async (orden: OperacionOrdenServicio): Promise<void> => {
    if (!orden.id) return;

    ordenAEnviar.value = orden;
    documento.value = null;
    canalElegido.value = '';
    errorEnvio.value = null;
    cargandoDocumento.value = true;

    const r = await operacionStore.documentoDeOrden(orden.id);

    cargandoDocumento.value = false;

    if ('error' in r) {
        errorEnvio.value = r.error;

        return;
    }

    documento.value = r;
    // Se preselecciona el primero disponible: con un solo canal, elegirlo es fricción sin
    // ganancia. Con varios, el operador lo cambia.
    canalElegido.value = r.canales.find(c => c.disponible)?.id ?? '';
};

const confirmarEnvio = async (): Promise<void> => {
    const orden = ordenAEnviar.value;
    if (!orden?.id || !canalElegido.value) return;

    enviandoOrden.value = true;
    errorEnvio.value = null;

    const motivo = await operacionStore.enviarOrdenAlProveedor(orden.id, canalElegido.value);

    enviandoOrden.value = false;

    if (motivo !== null) {
        errorEnvio.value = motivo;

        return;
    }

    ordenAEnviar.value = null;
    // La bitácora de esa orden cambió: si está abierta, que lo refleje.
    if (ordenActiva.value?.id === orden.id) await operacionStore.fetchMensajesPorOrden(orden.id);
};

// ══ ELIMINAR UN BORRADOR ═══════════════════════════════════════════════════
//
// Devuelve los servicios al pool, y eso NO lo hace este código:
// `operacion_servicio.orden_servicio_id` es `ON DELETE SET NULL`, así que se sueltan solos y
// quedan libres para entrar en otra orden. Los pagos y la bitácora sí caen en cascada — son de
// la orden, no del servicio.
//
// Confirmación en dos toques y no `window.confirm`: el mismo patrón que el catálogo de
// proveedores, y así el aviso puede decir la consecuencia («los servicios vuelven al pool») en
// vez de un «¿seguro?» pelado.
/**
 * Qué se puede hacer con una orden según dónde esté.
 *
 * ── Por qué botones y no el `<select>` que había ────────────────────────────
 * El estado se movía con un desplegable dentro del formulario de edición, y ese control no
 * distingue entre corregir un número de OS y **mandarle un documento a un proveedor**: las dos
 * cosas salían del mismo gesto, sin confirmación y a un desliz de distancia. Un `<select>`
 * además ofrece todos los destinos aunque el backend vaya a rechazar la mitad.
 *
 * Aquí sólo aparece lo que cabe desde el estado actual, y cada acción confirma antes.
 *
 * ⚠️ Es un ESPEJO de `OperacionOrdenEmision::validarTransicion()`, que es quien manda: una
 * anulada no vuelve atrás y una emitida no regresa a borrador. Esto sólo evita ofrecer botones
 * que iban a dar 422.
 */
const accionesDe = (estado?: string | null): Array<{ id: string; destino: string; etiqueta: string; icono: string; tono: 'normal' | 'grave' }> => {
    switch (estado) {
        case 'borrador':
            return [{ id: 'emitir', destino: 'emitida', etiqueta: 'Emitir', icono: 'fa-paper-plane', tono: 'normal' }];
        case 'emitida':
            return [
                { id: 'confirmar', destino: 'confirmada', etiqueta: 'Confirmar', icono: 'fa-circle-check', tono: 'normal' },
                { id: 'anular', destino: 'cancelada', etiqueta: 'Anular', icono: 'fa-ban', tono: 'grave' },
            ];
        case 'confirmada':
            return [
                { id: 'completar', destino: 'completada', etiqueta: 'Completar', icono: 'fa-flag-checkered', tono: 'normal' },
                { id: 'anular', destino: 'cancelada', etiqueta: 'Anular', icono: 'fa-ban', tono: 'grave' },
            ];
        case 'completada':
            return [{ id: 'anular', destino: 'cancelada', etiqueta: 'Anular', icono: 'fa-ban', tono: 'grave' }];
        // Cancelada es terminal: no vuelve. Se emite otra que la reemplace.
        default:
            return [];
    }
};

/** Lo que se le dice al operador ANTES de hacerlo. Cada una tiene su consecuencia propia. */
const consecuenciaDe = (accion: string): string => ({
    emitir: '¿Emitir? Se congela el contenido y ya no vuelve a borrador',
    confirmar: '¿Confirmar?',
    completar: '¿Completar?',
    anular: '¿Anular? No vuelve atrás, y sus servicios regresan al pool',
}[accion] ?? '¿Seguro?');

/** `orden.id + ':' + accion`, para que confirmar una no arme las demás. */
const confirmandoAccion = ref<string | null>(null);
const ejecutandoAccion = ref<string | null>(null);

const ejecutarAccion = async (
    orden: OperacionOrdenServicio,
    accion: { id: string; destino: string },
): Promise<void> => {
    if (!orden.id) return;

    const clave = `${orden.id}:${accion.id}`;
    errorOrden.value = null;

    if (confirmandoAccion.value !== clave) {
        confirmandoAccion.value = clave;

        return;
    }

    ejecutandoAccion.value = clave;

    try {
        await operacionStore.cambiarEstadoOrden(orden.id, accion.destino);
        await cargarBiblia();
    } catch (e) {
        errorOrden.value = { id: orden.id, motivo: mensajeDeErrorApi(e, 'No se pudo cambiar el estado de la orden.') };
    } finally {
        ejecutandoAccion.value = null;
        confirmandoAccion.value = null;
    }
};

const confirmandoOrden = ref<string | null>(null);
const borrandoOrden = ref<string | null>(null);
/**
 * El motivo del rechazo, PEGADO a su orden.
 *
 * No vale `errorOs`: ése vive dentro del modal de alta y en la lista no se ve. Y no vale un
 * aviso global arriba, porque con varias órdenes en pantalla no se sabría de cuál habla.
 */
const errorOrden = ref<{ id: string; motivo: string } | null>(null);

const eliminarOrden = async (orden: OperacionOrdenServicio): Promise<void> => {
    if (!orden.id) return;

    errorOrden.value = null;

    if (confirmandoOrden.value !== orden.id) {
        confirmandoOrden.value = orden.id;

        return;
    }

    borrandoOrden.value = orden.id;

    const motivo = await operacionStore.eliminarOrdenServicio(orden.id);

    borrandoOrden.value = null;
    confirmandoOrden.value = null;

    if (motivo !== null) {
        // El backend redacta el porqué —«está emitida: no se borra, anúlala»— y se pinta tal cual.
        errorOrden.value = { id: orden.id, motivo };

        return;
    }

    // La orden ya salió de la colección dentro del store. Esto es lo OTRO que cambió: sus
    // servicios volvieron al pool, así que La Biblia tiene que releerse o seguiría
    // enseñándolos comprometidos con una orden que ya no existe.
    await cargarBiblia();
};

// ============================================================================
// BITÁCORA DE MENSAJES DE UNA OS
// ============================================================================
const ordenActiva = ref<OperacionOrdenServicio | null>(null);

/** Qué orden tiene el detalle abierto. Sólo una a la vez. */
const ordenExpandida = ref<string | null>(null);

/** Los servicios de la orden desplegada. Se piden al abrirla, no antes. */

/**
 * Los servicios vienen EMBEBIDOS en la orden, no se piden aparte.
 *
 * La primera versión los pedía con `?ordenServicio=<iri>`: el endpoint devolvía 200 y una
 * colección vacía, así que el detalle decía «0 servicio(s)» con tres dentro. En vez de
 * perseguir el filtro se llevaron los campos al grupo `operacion:read`, que es lo que usa la
 * orden al serializar su colección. Sale más simple: una petición menos, ninguna dependencia
 * de un filtro, y el detalle abre instantáneo porque el dato ya está en memoria.
 *
 * El payload aguanta: son ~8 campos por servicio y el listado de órdenes es corto por
 * naturaleza —una orden por proveedor y expediente—.
 */
const alternarDetalle = (orden: OperacionOrdenServicio) => {
    ordenExpandida.value = ordenExpandida.value === orden.id ? null : (orden.id ?? null);

    // Sus servicios pueden no estar en el cuadro —otro rango, otro filtro—, así que sus puntos
    // tampoco. Sin esto la tarjeta se abría sin la línea de recojo justo cuando hay que revisarla.
    if (ordenExpandida.value) {
        void operacionStore.cargarPuntosDe(
            (orden.operacionServicios ?? [])
                .map((s) => (s as unknown as OperacionServicio).id)
                .filter((id): id is string => typeof id === 'string' && id !== '')
        );
    }
};

/** Los servicios de la orden abierta, tal como vinieron en el listado. */
/**
 * Qué ve el proveedor de cada extremo, y el ciclo del interruptor.
 *
 * `auto → oculto → siempre → auto`. Tres estados en un solo control porque son excluyentes y
 * caben en una pastilla; un desplegable por lado y por línea llenaría la tarjeta de cajas.
 */
const VISIBILIDAD_SIGUIENTE: Record<string, string> = { auto: 'oculto', oculto: 'siempre', siempre: 'auto' };

const etiquetaVisibilidad = (v?: string | null): string =>
    ({ auto: 'Auto', oculto: 'Oculto', siempre: 'Siempre' })[v ?? 'auto'] ?? 'Auto';

/**
 * El color va por el RESULTADO, no por el ajuste.
 *
 * Un «auto» que acaba oculto tiene que verse como oculto: al operador le importa qué sale, no cómo
 * se llama la regla que lo decidió.
 */
const claseVisibilidad = (v: string | null | undefined, sale: boolean): string => {
    if (v === 'siempre') return 'bg-[#376875] text-white border-[#376875]';
    if (!sale) return 'bg-slate-200 text-slate-400 border-slate-300';

    return 'bg-white text-slate-600 border-slate-300 hover:border-slate-500';
};

/** «Auto (visible)» / «Auto (oculto)»: el ajuste y su consecuencia, que no son lo mismo. */
const textoVisibilidad = (v: string | null | undefined, sale: boolean): string =>
    (v ?? 'auto') === 'auto' ? `Auto (${sale ? 'visible' : 'oculto'})` : etiquetaVisibilidad(v);

/** Lo que el backend ya calculó: si ese lado se imprime. */
const efectiva = (
    orden: OperacionOrdenServicio,
    item: { id?: string | null },
    lado: 'recojo' | 'entrega',
): boolean => {
    const mapa = (orden as unknown as { visibilidadEfectiva?: Record<string, { recojo?: boolean; entrega?: boolean }> }).visibilidadEfectiva;

    return Boolean(mapa?.[item.id ?? '']?.[lado]);
};

/**
 * Las líneas del documento agrupadas por día y **en orden cronológico**.
 *
 * ⚠️ Un `Record` conserva el orden de INSERCIÓN, no el de la clave: agrupar sin ordenar dejaba los
 * días como vinieran los ítems —04/09, 02/09, 31/08— y un documento que se lee de arriba abajo con
 * las fechas desordenadas no se puede seguir. Dentro de cada día, por hora.
 */
const lineasPorDia = (orden: OperacionOrdenServicio): Record<string, NonNullable<OperacionOrdenServicio['items']>> => {
    const mapa: Record<string, NonNullable<OperacionOrdenServicio['items']>> = {};

    for (const it of orden.items ?? []) {
        const dia = (it.fechaServicio ?? '').slice(0, 10) || 'Sin fecha';
        (mapa[dia] ??= []).push(it);
    }

    const ordenado: Record<string, NonNullable<OperacionOrdenServicio['items']>> = {};

    for (const dia of Object.keys(mapa).sort()) {
        ordenado[dia] = mapa[dia].sort((a, b) => (a.hora ?? '').localeCompare(b.hora ?? ''));
    }

    return ordenado;
};

/** Sólo sobre un documento vigente: en borrador se edita la fila viva; una anulada es terminal. */
const puedeAjustarRutas = (orden: OperacionOrdenServicio): boolean =>
    orden.estadoOs === 'emitida' || orden.estadoOs === 'confirmada';

const ajustandoRutas = ref(false);

const alternarVisibilidad = async (
    orden: OperacionOrdenServicio,
    item: { id?: string | null; visibilidadRecojo?: string | null; visibilidadEntrega?: string | null },
    lado: 'recojo' | 'entrega',
) => {
    if (!orden.id || !item.id) return;

    const actual = (lado === 'recojo' ? item.visibilidadRecojo : item.visibilidadEntrega) ?? 'auto';
    const siguiente = VISIBILIDAD_SIGUIENTE[actual] ?? 'auto';

    ajustandoRutas.value = true;
    try {
        await operacionStore.ajustarRutas(orden.id, { [item.id]: { [lado]: siguiente } });
    } finally {
        ajustandoRutas.value = false;
    }
};

const serviciosDeOrdenAbierta = computed<OperacionServicio[]>(() => {
    const orden = operacionStore.ordenesServicio.find(o => o.id === ordenExpandida.value);
    return (orden?.operacionServicios ?? []) as unknown as OperacionServicio[];
});

/**
 * Ajustar lo NEGOCIADO sin salir de la orden.
 *
 * Antes había que volver a La Biblia, encontrar la fila y editarla allí — ir y venir entre
 * dos pantallas justo cuando estás cerrando el precio con el proveedor, que es el momento en
 * que tienes la orden delante.
 *
 * Toca `costoNegociado` y `monedaNegociada`, que son campos DEL OPERADOR: la reconciliación no
 * los pisa jamás. El cotizado no se toca, que es la referencia con la que se concilia.
 */
/**
 * «4 noches · » o «» — la cantidad del componente en palabras, y sólo si aporta.
 *
 * Un `1` no se muestra: «1 noche» es ruido para un traslado o una entrada, donde la unidad es
 * implícita. La palabra depende del tipo: un alojamiento son noches, lo demás son «uds».
 */
const nochesTexto = (cantidad: number | undefined, tipo: string | null | undefined): string => {
    const n = cantidad ?? 1;
    if (n <= 1) return '';
    const palabra = tipo === 'alojamiento' ? 'noches' : 'uds';
    return `${n} ${palabra} · `;
};

/** «hace 3 h», «hace 2 días», «hace un momento» — legible, no una fecha ISO. */
const desdeHace = (iso: string | null | undefined): string => {
    if (!iso) return '';
    const ms = Date.now() - new Date(iso).getTime();
    const min = Math.floor(ms / 60000);
    if (min < 1) return 'hace un momento';
    if (min < 60) return `hace ${min} min`;
    const h = Math.floor(min / 60);
    if (h < 24) return `hace ${h} h`;
    const d = Math.floor(h / 24);
    return `hace ${d} día${d !== 1 ? 's' : ''}`;
};

const fechaHora = (iso: string): string =>
    new Date(iso).toLocaleString('es-PE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });

// ── EL GESTO «ATRÁS» CIERRA LA CAPA, NO SALE DE LA VISTA ─────────────────────
//
// Toda esta vista —fichas, modales, modo edición— cuelga de `useCapasEnHistorial`, que guarda
// las capas abiertas en la QUERY (`?capa=servicio.servicio-edicion`).
//
// ⚠️ Antes se empujaban entradas con `history.pushState` a mano, y fallaba de la forma que
// documenta el composable: vue-router lleva su propia contabilidad en `history.state`, así que
// una entrada empujada por fuera lo desincroniza y «atrás» acababa navegando en vez de cerrar.
// Con seis modales y ahora también la ficha, mantener dos mecanismos compitiendo por el
// historial era garantizar ese fallo.
const capas = useCapasEnHistorial();

/** La capa de modal abierta ahora mismo, para que la ✕ genérica sepa cuál cerrar. */
const capaModal = ref<string | null>(null);

/**
 * Abre un modal como capa. El cierre REAL lo hace siempre el composable —venga del gesto atrás
 * o de la ✕—, así que el `alCerrar` es el único sitio donde se vacía el ref.
 */
const abrirComoCapa = (nombre: string, alCerrar: () => void): void => {
    capaModal.value = nombre;
    capas.abrir(nombre, () => { alCerrar(); capaModal.value = null; });
};

/** Cierra el modal activo. NO vacía el ref: retrocede, y el composable hace el resto. */
const cerrarModal = (): void => {
    if (capaModal.value) capas.cerrar(capaModal.value);
};

// ── MODAL DE EXPEDIENTE (namelist + documentos + salto a cotización) ─────────
const expedienteAbierto = ref<{ fileId: string; nombre: string; cotizacionId: string | null; fileIdParaRuta: string; localizador: string | null; version: number | null } | null>(null);
const localizadorCopiado = ref(false);

// «HJDLDB-v1»: localizador del expediente + versión de la cotización. Es el identificador que
// el operador copia y comparte.
const localizadorVersion = computed(() => {
    const e = expedienteAbierto.value;
    if (!e?.localizador) return '';
    return e.version != null ? `${e.localizador}-v${e.version}` : e.localizador;
});

// Enlace a la vista del cliente (app pax, otro dominio). Abre en otra pestaña.
const linkPax = computed(() => {
    const e = expedienteAbierto.value;
    if (!e?.localizador || e.version == null) return '';
    return `${getUrls().pax}/file/${e.localizador}/v/${e.version}`;
});

const copiarLocalizador = async (): Promise<void> => {
    if (!localizadorVersion.value) return;
    try {
        await navigator.clipboard.writeText(localizadorVersion.value);
        localizadorCopiado.value = true;
        setTimeout(() => { localizadorCopiado.value = false; }, 1500);
    } catch { /* clipboard no disponible: sin feedback, no rompe nada */ }
};
const expedienteDetalle = ref<ExpedienteDetalle | null>(null);
const cargandoExpediente = ref(false);
const panelDocumentos = ref(true);
const panelNamelist = ref(true);

const abrirExpediente = async (servicio: OperacionServicio): Promise<void> => {
    const fileId = servicio.file?.id;
    if (!fileId) return;

    abrirComoCapa('expediente', () => { expedienteAbierto.value = null; });
    expedienteAbierto.value = {
        fileId,
        fileIdParaRuta: fileId,
        nombre: servicio.file?.nombreGrupo || 'Expediente',
        cotizacionId: (servicio as { cotizacionId?: string }).cotizacionId ?? null,
        localizador: (servicio.file as { localizador?: string | null } | undefined)?.localizador ?? null,
        version: (servicio as { cotizacionVersion?: number | null }).cotizacionVersion ?? null,
    };
    expedienteDetalle.value = null;
    cargandoExpediente.value = true;
    try {
        expedienteDetalle.value = await operacionStore.fetchExpedienteDetalle(fileId);
    } finally {
        cargandoExpediente.value = false;
    }
};

/** Salta al editor de la cotización de la que cuelga el servicio clicado. */
const irACotizacion = (): void => {
    const e = expedienteAbierto.value;
    if (!e?.cotizacionId) return;
    router.push({ name: 'cotizaciones_editor', params: { fileId: e.fileIdParaRuta, cotizacionId: e.cotizacionId } });
};

/** Abre el expediente completo (datos del cliente, versiones, bóveda de documentos). */
const irAExpediente = (): void => {
    const e = expedienteAbierto.value;
    if (!e?.fileIdParaRuta) return;
    router.push({ name: 'file_detalle', params: { id: e.fileIdParaRuta } });
};

// ── Subir documento al expediente desde el modal ─────────────────────────────
const docInputRef = ref<HTMLInputElement | null>(null);
const subiendoDoc = ref(false);

const onSubirDocumento = async (event: Event): Promise<void> => {
    const input = event.target as HTMLInputElement;
    const archivo = input.files?.[0];
    const e = expedienteAbierto.value;
    if (!archivo || !e?.fileId) return;

    subiendoDoc.value = true;
    try {
        const ok = await operacionStore.subirDocumentoExpediente(e.fileId, archivo);
        if (ok) {
            // Refresca la lista del modal para que el documento recién subido aparezca.
            expedienteDetalle.value = await operacionStore.fetchExpedienteDetalle(e.fileId);
        }
    } finally {
        subiendoDoc.value = false;
        if (docInputRef.value) docInputRef.value.value = ''; // permite resubir el mismo archivo
    }
};

/** Nombre completo de un pasajero del namelist, con lo que haya. */
const nombrePasajero = (p: Record<string, unknown>): string =>
    [p.nombre, p.apellido].filter(Boolean).join(' ') || 'Sin nombre';

// ── PAGOS A CUENTA AL PROVEEDOR ──────────────────────────────────────────────
const pagosOrden = ref<OperacionOrdenServicio | null>(null);
const pagos = ref<PagoProveedor[]>([]);
const cargandoPagos = ref(false);
const guardandoPago = ref(false);
const errorPago = ref<string | null>(null);
const formPago = ref({ monto: '', moneda: '', fecha: hoyIso(), medioPago: '', notas: '' });

/** Las monedas que la orden tiene, para no dejar pagar en una divisa que no le corresponde. */
const monedasDeOrden = computed<string[]>(() =>
    (pagosOrden.value?.totalesPorMoneda ?? [])
        .map(t => t.moneda ?? '')
        .filter((m): m is string => m !== '' && m !== '—'));

/**
 * El icono del medio, del catálogo del backend.
 *
 * El respaldo cubre la carrera real: el historial puede pintarse antes de que responda
 * `cargarMediosPago()`. No cubre «pago sin medio», que no existe — la columna es NOT NULL.
 */
const iconoMedioPago = (id: string): string =>
    operacionStore.mediosPago.find(m => m.id === id)?.icono ?? 'fa-money-check-dollar';

const abrirPagos = async (orden: OperacionOrdenServicio): Promise<void> => {
    abrirComoCapa('pagos', () => { pagosOrden.value = null; });
    pagosOrden.value = orden;
    pagos.value = [];
    errorPago.value = null;
    const primera = (orden.totalesPorMoneda ?? []).map(t => t.moneda ?? '').filter(m => m && m !== '—')[0] ?? '';
    // El medio NO se preselecciona: cómo se pagó es un hecho, y un valor por defecto lo
    // convierte en lo que había puesto al abrir el formulario. Mismo criterio que el cobro
    // rápido del panel financiero de la reserva.
    formPago.value = { monto: '', moneda: primera, fecha: hoyIso(), medioPago: '', notas: '' };
    void operacionStore.cargarMediosPago();

    if (!orden.id) return;
    cargandoPagos.value = true;
    try {
        pagos.value = await operacionStore.fetchPagos(orden.id);
    } finally {
        cargandoPagos.value = false;
    }
};

const registrarPago = async (): Promise<void> => {
    const orden = pagosOrden.value;
    if (!orden?.id) return;

    const monto = formPago.value.monto.trim().replace(',', '.');
    if (!/^\d+(\.\d{1,2})?$/.test(monto) || Number(monto) <= 0) {
        errorPago.value = 'El monto tiene que ser un número mayor que cero.';
        return;
    }
    if (!formPago.value.moneda) {
        errorPago.value = 'Elige la moneda del pago.';
        return;
    }
    if (!formPago.value.medioPago) {
        errorPago.value = 'Elige por qué medio se pagó.';
        return;
    }

    guardandoPago.value = true;
    errorPago.value = null;
    try {
        const motivo = await operacionStore.crearPago({
            ordenServicio: `/platform/ops/operacion_orden_servicios/${orden.id}`,
            monto: Number(monto).toFixed(2),
            moneda: `/platform/maestro/monedas/${formPago.value.moneda}`,
            fecha: formPago.value.fecha,
            medioPago: formPago.value.medioPago,
            notas: formPago.value.notas.trim() || null,
        });

        // El motivo lo redacta el backend —«esta orden se maneja en USD…»— y se pinta tal cual.
        if (motivo !== null) { errorPago.value = motivo; return; }

        // Recargar los pagos y la orden (su saldo lo recalcula el servidor).
        pagos.value = await operacionStore.fetchPagos(orden.id);
        await operacionStore.refrescarOrden(orden.id);
        pagosOrden.value = operacionStore.ordenesServicio.find(o => o.id === orden.id) ?? pagosOrden.value;
        // Se conservan moneda y medio: quien registra tres abonos seguidos los hace casi
        // siempre igual, y volver a elegirlos cada vez es fricción sin ganancia.
        formPago.value = {
            monto: '',
            moneda: formPago.value.moneda,
            fecha: hoyIso(),
            medioPago: formPago.value.medioPago,
            notas: '',
        };
    } finally {
        guardandoPago.value = false;
    }
};

const borrarPago = async (pago: PagoProveedor): Promise<void> => {
    const orden = pagosOrden.value;
    if (!pago.id || !orden?.id) return;

    const ok = await operacionStore.eliminarPago(pago.id);
    if (!ok) { errorPago.value = 'No se pudo eliminar el pago.'; return; }

    pagos.value = await operacionStore.fetchPagos(orden.id);
    await operacionStore.refrescarOrden(orden.id);
    pagosOrden.value = operacionStore.ordenesServicio.find(o => o.id === orden.id) ?? pagosOrden.value;
};

// ── HISTORIAL DE ESTADOS (bitácora) ──────────────────────────────────────────
const bitacoraServicio = ref<OperacionServicio | null>(null);
const bitacora = ref<BitacoraEstado[]>([]);
const cargandoBitacora = ref(false);

const abrirBitacora = async (servicio: OperacionServicio): Promise<void> => {
    abrirComoCapa('bitacora', () => { bitacoraServicio.value = null; });
    bitacoraServicio.value = servicio;
    bitacora.value = [];
    const id = idDe(servicio);
    if (!id) return;

    cargandoBitacora.value = true;
    try {
        bitacora.value = await operacionStore.fetchBitacoraEstado(id);
    } finally {
        cargandoBitacora.value = false;
    }
};

/** El label legible de un valor de estado, sea de reserva u operación. */
const etiquetaEstado = (campo: string, valor: string): string => {
    const cfg = campo === 'reserva'
        ? ESTADO_RESERVA_PROVEEDOR_CONFIG[valor as keyof typeof ESTADO_RESERVA_PROVEEDOR_CONFIG]
        : ESTADO_OPERACION_CONFIG[valor as keyof typeof ESTADO_OPERACION_CONFIG];
    return cfg?.label ?? valor;
};

/** El id de una fila, venga de donde venga: La Biblia trae `id`, la orden `servicioId`. */
const idDe = (s: OperacionServicio): string =>
    s.id ?? (s as { servicioId?: string }).servicioId ?? '';

/**
 * Referencias a los editores por fila, para reabrir el que falló al guardar.
 *
 * El estado de edición ya NO vive aquí: cada `EditorCostoNegociado` lleva el suyo. Lo único
 * que el padre necesita es poder avisar a uno concreto de que su PATCH falló.
 */
const editores = ref<Record<string, InstanceType<typeof EditorCostoNegociado> | null>>({});
const registrarEditor = (id: string, el: unknown): void => {
    editores.value[id] = el as InstanceType<typeof EditorCostoNegociado> | null;
};

/**
 * Guarda el costo negociado de una fila. Lo dispara el `@guardar` del editor, que ya trae el
 * importe validado y la moneda; aquí sólo se resuelve el id, se compone el IRI y se hace el
 * PATCH. Si falla, se le dice al editor que reabra con lo escrito.
 */
const onGuardarCosto = async (
    servicio: OperacionServicio,
    payload: { costoNegociado: string; monedaNegociada: string },
): Promise<void> => {
    const id = idDe(servicio);
    if (!id) return;   // el editor sólo se pinta sobre filas con id, pero por si acaso

    try {
        await operacionStore.actualizarServicio(id, {
            costoNegociado: payload.costoNegociado,
            monedaNegociada: `/platform/maestro/monedas/${payload.monedaNegociada}`,
        });

        // En Órdenes se refresca SÓLO la orden tocada —no la lista entera— para que
        // `totalesPorMoneda` (que calcula el servidor) se ponga al día sin parpadear toda la
        // pantalla. La Biblia no lo necesita: `actualizarServicio` reemplaza la fila viva.
        if (activeTab.value === 'ordenes' && ordenExpandida.value) {
            await operacionStore.refrescarOrden(ordenExpandida.value);
        }
    } catch {
        editores.value[id]?.marcarError('No se pudo guardar. Revisa la conexión.');
    }
};

// ── EDICIÓN DE LA CABECERA ───────────────────────────────────────────────────
const ordenEditando = ref<OperacionOrdenServicio | null>(null);
const formEdicion = ref({ numeroOs: '', compradorMaestroId: '' as string | null, compradorNombre: '', estadoOs: 'borrador' });
const guardandoEdicion = ref(false);
const errorEdicion = ref<string | null>(null);

const abrirEdicion = (orden: OperacionOrdenServicio) => {
    abrirComoCapa('orden-edicion', () => { ordenEditando.value = null; });
    ordenEditando.value = orden;
    formEdicion.value = {
        numeroOs: orden.numeroOs ?? '',
        compradorMaestroId: orden.compradorMaestroId ?? '',
        compradorNombre: orden.compradorNombre ?? '',
        estadoOs: orden.estadoOs ?? 'borrador',
    };
    errorEdicion.value = null;
    void operacionStore.fetchProveedores();
};

const onDestinatarioEdicion = (id: unknown): void => {
    const elegido = operacionStore.proveedores.find(p => p.id === String(id ?? ''));
    formEdicion.value.compradorNombre = elegido?.nombreComercial ?? '';
};

/**
 * Reemite: anula la orden y deja la sucesora **ya creada, en borrador**.
 *
 * ⚠️ **En una sola llamada, y ese detalle es el punto.** Si sólo se anulara, las filas quedarían
 * sueltas en La Biblia y el trabajo se perdería en cuanto el operador cerrara la pestaña o se
 * distrajera: nadie recuerda al día siguiente qué siete filas iban juntas. Aquí el servidor
 * anula, valida y vuelve a enlazar dentro de la misma transacción, así que en ningún momento
 * hay filas huérfanas.
 *
 * La sucesora nace **en borrador y no emitida**: reemitir no es mandar otro papel a ciegas, es
 * rehacerlo para revisarlo. Mientras es borrador sigue siendo una vista viva, así que refleja
 * lo que La Biblia diga ahora — que es justo lo que había cambiado.
 *
 * La anulada conserva sus líneas congeladas: el histórico no se toca. Y `reemplazaAId` deja
 * escrita la cadena «OS-014 → OS-021», que es lo que se consulta cuando un proveedor reclama.
 */
/**
 * Aplica los cambios menores: la orden se actualiza y **sigue emitida**.
 *
 * Botón aparte del de reemitir a propósito. Confirmar la hora que dio el proveedor y rehacer un
 * documento porque cambió la fecha son dos gestos con consecuencias muy distintas, y darles el
 * mismo botón invita a usar el destructivo por costumbre.
 */
const aplicarMenores = async (orden: OperacionOrdenServicio) => {
    if (!orden.id) return;

    try {
        await operacionStore.aplicarCambiosMenores(orden.id);
        await cargarBiblia();
    } catch (e) {
        avisoPapel.value = mensajeDeErrorApi(e, 'No se pudo actualizar la orden.');
        window.setTimeout(() => { avisoPapel.value = null; }, 8000);
    }
};

const anularParaReemitir = async (orden: OperacionOrdenServicio) => {
    const servicioIds = (orden.operacionServicios ?? [])
        .map(s => extractIdStr(s))
        .filter(Boolean);

    if (!orden.id || servicioIds.length === 0) return;

    const hoy = hoyIso().replace(/-/g, '');

    try {
        await operacionStore.emitirOrdenServicio({
            servicioIds,
            numeroOs: `OS-${hoy}-${String(Math.floor(Math.random() * 900) + 100)}`,
            compradorMaestroId: orden.compradorMaestroId ?? null,
            compradorNombre: orden.compradorNombre ?? null,
            reemplazaAId: orden.id,
            // Nace en borrador: se revisa y se emite aparte.
            soloBorrador: true,
        });
        await cargarBiblia();
    } catch (e) {
        avisoPapel.value = mensajeDeErrorApi(e, 'No se pudo reemitir la orden.');
        window.setTimeout(() => { avisoPapel.value = null; }, 8000);
    }
};

const guardarEdicion = async () => {
    const orden = ordenEditando.value;
    if (!orden?.id) return;

    guardandoEdicion.value = true;
    errorEdicion.value = null;
    try {
        await operacionStore.actualizarOrdenServicio(orden.id, {
            numeroOs: formEdicion.value.numeroOs,
            compradorMaestroId: formEdicion.value.compradorMaestroId || null,
            compradorNombre: formEdicion.value.compradorNombre || null,
        });

        // El estado ya no se toca aquí: este formulario corrige la cabecera —número y
        // destinatario— y nada más. Mover el estado es emitir o anular, y eso tiene sus
        // botones con confirmación. Emitir sigue congelando el contenido, así que el orden
        // sigue importando: primero se corrige la cabecera, después se emite.

        capas.cerrar('orden-edicion');
        await cargarBiblia();
    } catch (e) {
        errorEdicion.value = mensajeDeErrorApi(e, 'No se pudo guardar. Comprueba que el número de OS no esté repetido.');
    } finally {
        guardandoEdicion.value = false;
    }
};
/**
 * Qué estados de Orden se ven. **Las canceladas quedan fuera por defecto.**
 *
 * La pestaña se llama «Órdenes vigentes» y enseñaba las canceladas mezcladas con las demás: el
 * rótulo mentía y, peor, una orden anulada se lee igual de vigente que las otras a la velocidad
 * a la que se repasa una lista. Una anulada no se borra —es el rastro de lo que se le mandó a
 * alguien, ver `docs/Operacion.md`—, así que la respuesta no es quitarla sino no enseñarla salvo
 * que se pida.
 *
 * ⚠️ Se guardan las **visibles** y no las ocultas: el día que se añada un estado nuevo al enum,
 * aparece solo. Con una lista de excluidos, un estado nuevo se colaría sin que nadie lo decidiera.
 */
const ESTADOS_OS_POR_DEFECTO: EstadoOsValue[] = ['borrador', 'emitida', 'confirmada', 'completada'];
const estadosOsVisibles = ref<EstadoOsValue[]>([...ESTADOS_OS_POR_DEFECTO]);

const alternarEstadoOs = (estado: EstadoOsValue): void => {
    const i = estadosOsVisibles.value.indexOf(estado);

    if (i === -1) estadosOsVisibles.value.push(estado);
    else estadosOsVisibles.value.splice(i, 1);
};

/** Las órdenes que se pintan, ya filtradas. */
const ordenesVisibles = computed(() =>
    operacionStore.ordenesServicio.filter(
        (o) => estadosOsVisibles.value.includes((o.estadoOs || 'borrador') as EstadoOsValue)
    )
);

/** Cuántas hay de cada estado, para el contador de cada chip. */
const conteoPorEstadoOs = computed<Record<string, number>>(() => {
    const cuenta: Record<string, number> = {};

    operacionStore.ordenesServicio.forEach((o) => {
        const e = o.estadoOs || 'borrador';
        cuenta[e] = (cuenta[e] ?? 0) + 1;
    });

    return cuenta;
});

/**
 * ⚠️ Cuántas quedan ESCONDIDAS por el filtro.
 *
 * Se dice siempre que hay alguna. Una lista recortada sin avisar es el fallo que ya costó las
 * filas de tipo `contacto` en La Biblia: se lee como «no hay nada» y nadie echa de menos lo que
 * no sabía que existía.
 */
const ordenesOcultasPorFiltro = computed(() =>
    operacionStore.ordenesServicio.length - ordenesVisibles.value.length
);

const cuerpoMensaje = ref<string>('');
const enviandoMensaje = ref<boolean>(false);

const abrirMensajes = async (orden: OperacionOrdenServicio) => {
    abrirComoCapa('orden', () => { ordenActiva.value = null; });
    ordenActiva.value = orden;
    cuerpoMensaje.value = '';
    if (orden.id) await operacionStore.fetchMensajesPorOrden(orden.id);
};

const enviarMensaje = async () => {
    const texto = cuerpoMensaje.value.trim();
    if (!texto || !ordenActiva.value?.id) return;

    enviandoMensaje.value = true;
    try {
        await operacionStore.registrarMensaje({
            ordenServicio: `/platform/ops/operacion_orden_servicios/${ordenActiva.value.id}`,
            tipo: 'email',
            cuerpoHtml: texto,
        });
        cuerpoMensaje.value = '';
    } finally {
        enviandoMensaje.value = false;
    }
};

const formatearFecha = (iso?: string | null): string => {
    if (!iso) return '';
    return `${iso.slice(8, 10)}/${iso.slice(5, 7)} ${iso.slice(11, 16)}`;
};

onMounted(async () => {
    // El vocabulario primero: sin él los chips no existen y el operador no puede filtrar.
    // Las monedas van en paralelo: hacen falta para el selector de la moneda negociada.
    //
    // Y el catálogo de organizaciones, que antes sólo se pedía al abrir el modal de la Orden:
    // ahora alimenta el `<datalist>` de prestador y comprador de CADA fila, así que tiene que
    // estar antes de que el operador pueda escribir. Es idempotente.
    await Promise.all([
        operacionStore.fetchLugares(),
        operacionStore.fetchMonedas(),
        operacionStore.fetchProveedores(),
    ]);
    await cargarBiblia();
});

// El registro de escuchas de `popstate` y la limpieza al navegar fuera los hace ahora
// `useCapasEnHistorial`: las capas viven en la query y las mueve el router, no nosotros.
</script>

<template>
    <div class="h-screen bg-[#F8FAFC] flex flex-col font-sans overflow-hidden">

        <!-- UNA sola lista para todas las filas del cuadro. Con un selector por fila el DOM
             crecería a 99 opciones × cientos de filas; el `<datalist>` se declara una vez y
             cada input lo referencia por id. Es lo que hace que el papel quede ENLAZADO al
             catálogo sin cambiar la ergonomía de un cuadro tan denso. -->
        <datalist id="catalogo-organizaciones">
            <option v-for="p in operacionStore.proveedores" :key="p.id" :value="p.nombreComercial" />
        </datalist>

        <!-- Lo escrito no estaba en el catálogo: se revirtió y se dice por qué. -->
        <div
            v-if="avisoPapel"
            class="fixed bottom-4 left-1/2 -translate-x-1/2 z-50 px-4 py-2 rounded-lg bg-amber-50 border border-amber-300 text-amber-800 text-xs font-bold shadow-lg"
        >
            <i class="fas fa-triangle-exclamation mr-1.5"></i>{{ avisoPapel }}
        </div>

        <!-- ================================================================
             HEADER
             ================================================================ -->
        <!-- En móvil la cabecera va en DOS filas: con el título y las pestañas en
             la misma, el título se partía en varias líneas de dos palabras. Desde
             `md` vuelve a ser una sola fila. -->
        <header class="bg-slate-900 text-white px-4 md:px-6 py-3 flex flex-col md:flex-row md:items-center md:justify-between gap-2 md:gap-3 z-20 shadow-md shrink-0">
            <div class="flex items-center gap-3 min-w-0">
                <AppSwitcher />
                <div class="min-w-0">
                    <h1 class="font-black text-base md:text-xl tracking-tight leading-none truncate">
                        Centro de Operaciones
                    </h1>
                    <p class="text-[10px] md:text-[11px] font-bold text-slate-400 uppercase tracking-widest mt-1 truncate">
                        Tráfico · Órdenes de Servicio
                    </p>
                </div>
            </div>

            <!-- Pestañas + recargar. El botón vive aquí y no en cada barra de filtros: es
                 el mismo gesto en las dos pestañas y así no se mueve de sitio al cambiar. -->
            <div class="flex items-center gap-2 shrink-0 self-start md:self-auto">
                <div class="flex items-center bg-slate-800 rounded-lg p-1 gap-1">
                    <button
                        @click="cambiarTab('biblia')"
                        :class="activeTab === 'biblia' ? 'bg-[#376875] text-white shadow' : 'text-slate-400 hover:text-white'"
                        class="px-3 md:px-4 py-1.5 rounded text-[10px] md:text-xs font-black tracking-widest transition-all whitespace-nowrap"
                    >
                        <i class="fas fa-car-side mr-1"></i>
                        <span class="hidden sm:inline">La </span>Biblia
                    </button>
                    <button
                        @click="cambiarTab('ordenes')"
                        :class="activeTab === 'ordenes' ? 'bg-[#E07845] text-white shadow' : 'text-slate-400 hover:text-white'"
                        class="px-3 md:px-4 py-1.5 rounded text-[10px] md:text-xs font-black tracking-widest transition-all whitespace-nowrap"
                    >
                        <i class="fas fa-file-invoice mr-1"></i>
                        Órdenes
                    </button>
                </div>

                <button
                    @click="refrescar"
                    :disabled="operacionStore.isLoading"
                    class="flex items-center justify-center w-9 h-9 bg-[#376875] hover:bg-[#2d5660] disabled:opacity-50 text-white rounded-lg transition-colors shadow-sm"
                    title="Recargar"
                >
                    <i class="fas fa-rotate" :class="{ 'fa-spin': operacionStore.isLoading }"></i>
                </button>
            </div>
        </header>

        <!-- ================================================================
             CONTENIDO
             ================================================================ -->
        <main class="flex-1 overflow-y-auto">

            <!-- PESTAÑA: LA BIBLIA ---------------------------------------->
            <section v-if="activeTab === 'biblia'" class="flex flex-col min-h-full">

                <!-- Barra de filtros pegajosa -->
                <div class="sticky top-0 z-10 bg-[#F8FAFC]/95 backdrop-blur-sm border-b border-slate-200 px-3 md:px-6 py-2 md:py-3 shrink-0">

                    <!--
                      BARRA DE FILTROS — cuatro ejes plegados tras UNA fila de rótulos.

                      Mobile first, y la restricción manda: en un teléfono de 360 px lo que se
                      viene a ver es el cuadro, no los filtros. Antes cada eje tenía su franja
                      propia siempre desplegada —trece chips de lugar, los de organización, catorce
                      de tipo— y el primer servicio caía bajo el pliegue. Ahora la barra ocupa tres
                      líneas fijas (rango · atajos + expediente · ejes) y las opciones de un eje
                      sólo existen mientras ese eje está abierto.

                      Los ejes son cuatro y ninguno es «avanzado»: LUGAR y ORGANIZACIÓN acotan a
                      quién y dónde, TIPO qué clase de servicio, ESTADO en qué punto está. El
                      antiguo botón «Filtros» desapareció con el panel que abría: escondía tipo y
                      estado un toque más adentro sin que fueran menos de diario que los otros dos.
                    -->

                    <!-- Fila 1 — el rango. Las dos fechas SIEMPRE en la misma línea: son un
                         rango, y partido en dos renglones deja de leerse como tal. -->
                    <div class="grid grid-cols-2 gap-2">
                        <label class="flex flex-col gap-0.5">
                            <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Desde</span>
                            <FechaHoraPicker
                                :model-value="desde"
                                solo-fecha
                                @update:model-value="onCambiarDesde"
                            />
                        </label>
                        <label class="flex flex-col gap-0.5">
                            <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Hasta</span>
                            <FechaHoraPicker
                                :model-value="hasta"
                                solo-fecha
                                :min-date="desde"
                                @update:model-value="(v: string) => { hasta = v; cargarBiblia(); }"
                            />
                        </label>
                    </div>

                    <!--
                      Fila 2 — atajos de fecha + expediente, compartiendo línea.

                      El expediente ocupa justo el hueco que dejaron «7 días» (que devolvía al
                      rango de arranque, o sea a ninguna parte) y «Actualizar» (que se subió a la
                      cabecera). Se gana un renglón y el buscador queda en la barra fija, que es
                      donde tiene que estar: es lo único que consulta SIN rango de fechas, así que
                      esconderlo tras un desplegable era esconder la salida.
                    -->
                    <div class="mt-2 flex items-center gap-1.5">
                        <button
                            v-for="p in [{ k: 'hoy', l: 'Hoy' }, { k: 'manana', l: 'Mañana' }]"
                            :key="p.k"
                            @click="aplicarPreset(p.k as 'hoy' | 'manana')"
                            class="shrink-0 px-2.5 py-2 bg-white hover:bg-slate-100 border border-slate-200 rounded-lg text-[10px] font-black uppercase tracking-widest text-slate-600 transition-colors shadow-sm"
                        >
                            {{ p.l }}
                        </button>

                        <div class="relative flex-1 min-w-0">
                            <div v-if="expedienteSeleccionado" class="flex items-center gap-2 bg-white border border-slate-200 rounded-lg px-3 py-2 shadow-sm">
                                <i class="fas fa-folder-open text-[#376875] text-xs shrink-0"></i>
                                <span class="text-sm font-bold text-slate-700 truncate">{{ expedienteSeleccionado.nombreGrupo }}</span>
                                <button @click="quitarExpediente" class="ml-auto shrink-0 text-slate-400 hover:text-rose-600">
                                    <i class="fas fa-xmark"></i>
                                </button>
                            </div>

                            <template v-else>
                                <input
                                    ref="inputExpediente"
                                    v-model="terminoExpediente"
                                    type="text"
                                    placeholder="Expediente…"
                                    class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm font-medium text-slate-700 outline-none focus:ring-2 focus:ring-[#376875] shadow-sm"
                                />
                                <div
                                    v-if="resultadosExpediente.length || buscandoExpediente"
                                    class="absolute left-0 right-0 bg-white border border-slate-200 rounded-lg shadow-lg z-20 max-h-56 overflow-y-auto"
                                    :class="expedienteArriba ? 'bottom-full mb-1' : 'top-full mt-1'"
                                >
                                    <p v-if="buscandoExpediente" class="px-3 py-2 text-xs text-slate-400">
                                        <i class="fas fa-spinner fa-spin mr-1"></i> Buscando…
                                    </p>
                                    <button
                                        v-for="exp in resultadosExpediente"
                                        :key="exp.id"
                                        @click="elegirExpediente(exp)"
                                        class="w-full text-left px-3 py-2 hover:bg-slate-50 border-b border-slate-100 last:border-0"
                                    >
                                        <p class="text-sm font-bold text-slate-700">{{ exp.nombreGrupo }}</p>
                                        <p v-if="exp.pasajeroPrincipal" class="text-[10px] text-slate-400">{{ exp.pasajeroPrincipal }}</p>
                                    </button>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- La versión de la cotización cuelga del expediente, así que sale pegada a
                         él y sólo cuando hay alguna. Suelta en un panel de filtros no se entendía
                         de qué era versión. -->
                    <label v-if="cotizacionesDelExpediente.length" class="mt-2 flex items-center gap-2">
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest shrink-0">Versión</span>
                        <select
                            v-model="cotizacionSeleccionada"
                            @change="cargarBiblia"
                            class="flex-1 min-w-0 bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm font-medium text-slate-700 outline-none focus:ring-2 focus:ring-[#376875] shadow-sm"
                        >
                            <option value="">Todas las versiones</option>
                            <option v-for="c in cotizacionesDelExpediente" :key="c.id" :value="c.id">
                                v{{ c.version ?? '?' }} · {{ c.titulo || 'Sin título' }} ({{ c.estado }})
                            </option>
                        </select>
                    </label>

                    <!--
                      Fila 3 — los cuatro ejes.

                      El rótulo ES el interruptor y lleva CONTADOR: es lo que hace seguro tenerlos
                      todos cerrados de arranque. Un filtro activo escondido y sin contador es la
                      forma de leer un cuadro recortado creyéndolo entero, que es el fallo que ya
                      costó las filas de tipo `contacto`.

                      LUGAR y ORGANIZACIÓN sólo salen si hay de qué: un rótulo que abre a una lista
                      vacía es una promesa incumplida. Los de LUGAR vienen del catálogo; los de
                      ORGANIZACIÓN, de las propias filas cargadas.
                    -->
                    <!-- ⚠️ Los rótulos van APRETADOS (`px-1.5`, `gap-0.5`, `tracking-normal`) y no
                         es cosmética: con el espaciado normal sumaban 386 px y en un teléfono de
                         390 px sólo hay 366 útiles, así que ESTADO caía a una segunda línea. Medido
                         en el navegador, no estimado. Si se añade un quinto eje, vuelve a medir. -->
                    <div class="mt-2 flex flex-wrap items-center gap-1">
                        <button
                            v-for="g in gruposVisibles"
                            :key="g.k"
                            @click="alternarGrupo(g.k)"
                            :class="grupoAbierto === g.k ? 'bg-[#376875] text-white border-[#376875]'
                                : conteoPorGrupo[g.k] ? 'bg-white text-[#376875] border-[#376875]'
                                : 'bg-white text-slate-500 border-slate-200 hover:border-slate-400'"
                            class="flex items-center gap-0.5 px-1.5 py-1.5 border rounded-lg text-[9px] font-black uppercase tracking-normal transition-colors shadow-sm"
                        >
                            <i :class="g.icon"></i>
                            {{ g.label }}
                            <span
                                v-if="conteoPorGrupo[g.k]"
                                :class="grupoAbierto === g.k ? 'bg-white/25' : 'bg-[#376875] text-white'"
                                class="rounded-full px-1.5"
                            >{{ conteoPorGrupo[g.k] }}</span>
                            <i class="fas text-[8px]" :class="grupoAbierto === g.k ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                        </button>

                        <!-- El recuento vive aquí y no en su propia franja: una línea sólo para
                             «40 servicios» es cuadro de tráfico que no se ve. -->
                        <span class="ml-auto text-[10px] font-black text-slate-400 uppercase tracking-widest tabular-nums">
                            {{ operacionStore.servicios.length }}
                        </span>

                        <button
                            v-if="hayFiltrosPuestos"
                            @click="limpiarFiltros"
                            class="px-2 py-1.5 text-[9px] font-black uppercase tracking-wider text-slate-400 hover:text-rose-600 transition-colors"
                        >
                            Limpiar
                        </button>
                    </div>

                    <!-- Las opciones del eje abierto. Uno como mucho, y sólo mientras está
                         abierto: es lo que devuelve la pantalla al cuadro. -->
                    <div v-if="grupoAbierto" class="mt-2 flex flex-wrap gap-1.5">

                        <template v-if="grupoAbierto === 'lugar'">
                            <button
                                v-for="l in operacionStore.lugares"
                                :key="l.id"
                                @click="alternarLugar(l.id)"
                                :class="lugaresSeleccionados.includes(l.id) ? 'bg-[#376875] text-white border-[#376875]'
                                    : 'bg-white text-slate-500 border-slate-200 hover:border-slate-400'"
                                class="px-2.5 py-1 border rounded-lg text-[10px] font-black uppercase tracking-wider transition-colors"
                            >
                                {{ l.nombre }}
                            </button>

                            <!--
                                Los componentes tecleados a mano en la cotización no tienen maestro,
                                así que nunca llevan etiqueta. Sin este chip desaparecerían del cuadro
                                al filtrar por lugar sin ningún aviso — y con ellos, de la orden de
                                servicio. Ver docs/Operacion.md.
                            -->
                            <button
                                @click="alternarLugar(SIN_LUGAR)"
                                :class="lugaresSeleccionados.includes(SIN_LUGAR) ? 'bg-amber-500 text-white border-amber-500'
                                    : 'bg-white text-amber-600 border-amber-200 hover:border-amber-400'"
                                class="px-2.5 py-1 border rounded-lg text-[10px] font-black uppercase tracking-wider transition-colors"
                                title="Servicios sin etiqueta de lugar (componentes manuales o sin catalogar)"
                            >
                                <i class="fas fa-circle-question mr-1"></i>Sin etiqueta
                            </button>
                        </template>

                        <!--
                          ORGANIZACIÓN — prestador o comprador, en OR.

                          Es el eje con el que se compone una orden: la orden agrupa por comprador,
                          así que «todo lo de Futurismo Jonathan» es el paso previo a marcar y
                          generar. **Filtra lo ya cargado** y sus chips salen de las propias filas:
                          el catálogo entero daría una lista de la que la mitad no aparece en estas
                          fechas.
                        -->
                        <template v-else-if="grupoAbierto === 'organizacion'">
                            <!-- La leyenda no es adorno: en un teléfono no hay `title` que leer,
                                 y sin ella la marca naranja es un icono sin explicación. Sólo sale
                                 si hay de las dos clases; con una sola no hay nada que distinguir. -->
                            <p
                                v-if="hayMezclaDePapeles"
                                class="w-full text-[9px] font-bold text-slate-400 leading-snug"
                            >
                                <i class="fas fa-file-invoice text-[#E07845] mr-1"></i>
                                Reciben la orden. Las demás sólo prestan en este cuadro: su orden se la lleva quien les compra.
                            </p>

                            <button
                                v-for="e in organizacionesDelCuadro"
                                :key="e.id"
                                @click="alternarOrganizacion(e.id)"
                                :class="organizacionesSeleccionadas.includes(e.id) ? 'bg-[#376875] text-white border-[#376875]'
                                    : e.recibeOrdenes ? 'bg-white text-slate-600 border-slate-300 hover:border-slate-400'
                                    : 'bg-white text-slate-400 border-slate-200 hover:border-slate-400'"
                                class="flex items-center gap-1.5 px-2.5 py-1 border rounded-lg text-[10px] font-black uppercase tracking-wider transition-colors"
                                :title="e.recibeOrdenes
                                    ? 'Compra en este cuadro: puede recibir una Orden de Servicio'
                                    : 'Sólo presta en este cuadro: sirve de referencia, la orden va a quien le compra'"
                            >
                                <!-- Mismo icono y mismo naranja que «Generar OS» y la pestaña de
                                     Órdenes: si marca «esto acaba en una orden», tiene que ser el
                                     mismo signo en toda la pantalla. -->
                                <i
                                    v-if="e.recibeOrdenes"
                                    class="fas fa-file-invoice text-[9px]"
                                    :class="organizacionesSeleccionadas.includes(e.id) ? 'text-white/80' : 'text-[#E07845]'"
                                ></i>
                                {{ e.nombre }}
                            </button>

                            <!-- Las filas que nadie ha asignado son las que hay que resolver ANTES
                                 de emitir nada. Sin este chip no hay forma de pedirlas: no tienen
                                 nombre por el que buscarlas. -->
                            <button
                                v-if="hayServiciosSinOrganizacion"
                                @click="alternarOrganizacion(SIN_ORGANIZACION)"
                                :class="organizacionesSeleccionadas.includes(SIN_ORGANIZACION) ? 'bg-amber-500 text-white border-amber-500'
                                    : 'bg-white text-amber-600 border-amber-200 hover:border-amber-400'"
                                class="px-2.5 py-1 border rounded-lg text-[10px] font-black uppercase tracking-wider transition-colors"
                                title="Servicios sin prestador ni comprador: no se pueden pedir a nadie todavía"
                            >
                                <i class="fas fa-circle-question mr-1"></i>Sin asignar
                            </button>
                        </template>

                        <template v-else-if="grupoAbierto === 'tipo'">
                            <button
                                v-for="t in TIPOS_COMPONENTE"
                                :key="t.value"
                                @click="alternarTipo(t.value)"
                                :class="tiposSeleccionados.includes(t.value) ? 'bg-slate-900 text-white border-slate-900'
                                    : 'bg-white text-slate-500 border-slate-200 hover:border-slate-400'"
                                class="flex items-center gap-1.5 px-2.5 py-1 border rounded-lg text-[10px] font-black uppercase tracking-wider transition-colors"
                            >
                                <i :class="[t.icon, 'text-[10px]']"></i>
                                {{ t.label }}
                            </button>
                        </template>

                        <!--
                          ESTADO — los tres cortes «en qué punto está», juntos porque se leen
                          juntos: si ya está encargado (OS), qué dijo el proveedor (reserva) y
                          cómo va en la calle (operación). Estaban repartidos entre la barra fija
                          y el panel «Filtros», y nada decía que fueran la misma pregunta.
                        -->
                        <template v-else>
                            <div class="flex items-center gap-0.5 bg-white border border-slate-200 rounded-lg p-0.5 shadow-sm">
                                <button v-for="o in [{ k: '', l: 'Todas' }, { k: 'sin', l: 'Sin OS' }, { k: 'con', l: 'En OS' }]"
                                        :key="o.k"
                                        @click="filtroOs = o.k as '' | 'sin' | 'con'"
                                        :class="filtroOs === o.k ? 'bg-[#376875] text-white' : 'text-slate-500 hover:bg-slate-100'"
                                        class="px-2 py-1 rounded text-[9px] font-black uppercase tracking-widest transition-colors whitespace-nowrap">
                                    {{ o.l }}
                                </button>
                            </div>

                            <select
                                v-model="filtroEstadoReservaProveedor"
                                @change="cargarBiblia"
                                class="bg-white border border-slate-200 rounded-lg px-2 py-1 text-xs font-medium text-slate-700 outline-none focus:ring-2 focus:ring-[#376875] shadow-sm"
                            >
                                <option value="">Reserva: cualquiera</option>
                                <option v-for="(cfg, k) in ESTADO_RESERVA_PROVEEDOR_CONFIG" :key="k" :value="k">Reserva: {{ cfg.label }}</option>
                            </select>

                            <select
                                v-model="filtroEstadoOperacion"
                                @change="cargarBiblia"
                                class="bg-white border border-slate-200 rounded-lg px-2 py-1 text-xs font-medium text-slate-700 outline-none focus:ring-2 focus:ring-[#376875] shadow-sm"
                            >
                                <option value="">Operación: cualquiera</option>
                                <option v-for="(cfg, k) in ESTADO_OPERACION_CONFIG" :key="k" :value="k">Operación: {{ cfg.label }}</option>
                            </select>
                        </template>
                    </div>

                    <!-- Sin rango y sin expediente no se consulta: sería la operación entera. -->
                    <p v-if="faltaAcotar" class="mt-1.5 text-[10px] font-bold text-amber-700 flex items-center gap-1.5">
                        <i class="fas fa-triangle-exclamation"></i>
                        Pon fechas, o elige un expediente para verlo entero sin ellas.
                    </p>

                    <!-- Fila 3: sólo si hay algo que decir. Con el cuadro vacío y sin
                         seleccionar, esta franja era espacio en blanco fijo. -->
                    <div v-if="seleccionados.length || hayServiciosOcultos" class="mt-2 flex items-center gap-3">
                        <!-- El recuento de servicios se quedó en la fila de ejes, que está
                             siempre visible. Aquí sólo lo seleccionado: en un teléfono los dos
                             juntos ocupaban dos tercios del ancho y empujaban «agregar a orden»
                             fuera de la pantalla. -->
                        <span v-if="seleccionados.length" class="shrink-0 text-[10px] font-black text-[#376875] uppercase tracking-widest">
                            {{ seleccionados.length }} sel.
                        </span>

                        <!-- La página trae 200 como mucho y aquí no se pagina. Sin este
                             aviso, un rango amplio recortaba el cuadro en silencio: el
                             operador leía el día entero creyendo que estaba entero. -->
                        <span
                            v-if="hayServiciosOcultos"
                            class="text-[10px] font-black text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-0.5 uppercase tracking-widest"
                            :title="`Hay ${operacionStore.totalServicios} servicios en este rango y sólo caben 200 por página. Acota las fechas o usa los filtros.`"
                        >
                            <i class="fas fa-triangle-exclamation mr-1"></i>
                            {{ operacionStore.totalServicios - operacionStore.servicios.length }} sin mostrar
                        </span>

                        <template v-if="seleccionados.length">
                            <span v-if="conflictoSeleccion" class="text-[10px] font-bold text-rose-600">
                                <i class="fas fa-triangle-exclamation mr-1"></i>{{ conflictoSeleccion }}
                            </span>
                            <button
                                @click="abrirModalOs"
                                :disabled="!!conflictoSeleccion"
                                class="ml-auto flex items-center gap-2 px-4 py-1.5 bg-[#E07845] hover:bg-[#c96636] disabled:opacity-40 disabled:cursor-not-allowed text-white rounded-lg text-[10px] font-black uppercase tracking-widest transition-colors shadow-sm"
                            >
                                <i class="fas fa-file-invoice"></i>
                                Generar OS
                            </button>
                            <!-- Componer una orden no siempre ocurre de una sentada: se marcan
                                 los del martes y al revisar el miércoles aparecen dos más del
                                 mismo proveedor. Sólo sale si hay borradores compatibles. -->
                            <button
                                v-if="ordenesParaAgregar.length"
                                @click="mostrarModalAgregar = true; errorAgregar = null"
                                class="flex items-center gap-2 px-4 py-1.5 bg-white border-2 border-[#376875] text-[#376875] hover:bg-[#376875] hover:text-white rounded-lg text-[10px] font-black uppercase tracking-widest transition-colors shadow-sm"
                            >
                                <i class="fas fa-plus"></i>
                                Agregar a OS ({{ ordenesParaAgregar.length }})
                            </button>
                            <button
                                @click="seleccionados = []"
                                class="text-[10px] font-black uppercase tracking-widest text-slate-400 hover:text-slate-700"
                            >
                                Quitar
                            </button>
                        </template>
                    </div>
                </div>

                <!-- Spinner -->
                <div v-if="operacionStore.isLoading" class="flex-1 flex items-center justify-center py-16">
                    <div class="text-center">
                        <i class="fas fa-spinner fa-spin text-3xl text-[#376875] mb-3"></i>
                        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Sincronizando logística...</p>
                    </div>
                </div>

                <!-- Empty state -->
                <div v-else-if="operacionStore.servicios.length === 0" class="flex-1 flex items-center justify-center py-16">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-slate-100 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-inner">
                            <i class="fas fa-car-side text-2xl text-slate-300"></i>
                        </div>
                        <p class="font-black text-slate-500 uppercase tracking-widest text-xs mb-1">Sin logística</p>
                        <p class="text-sm text-slate-400">No hay servicios programados con estos filtros.</p>
                        <!-- Las dos causas reales de un panel vacío, en orden de frecuencia.
                             Se enuncian aquí porque ninguna es visible desde esta pantalla:
                             las filas nacen al confirmar una cotización, y sólo entonces. -->
                        <div class="mt-4 mx-auto max-w-sm text-left bg-white border border-slate-200 rounded-xl p-3 shadow-sm">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Si esperabas ver algo</p>
                            <ul class="text-xs text-slate-500 space-y-1.5 leading-snug">
                                <li>
                                    <i class="fas fa-calendar-day text-slate-300 mr-1.5"></i>
                                    Amplía el rango: los servicios de un expediente suelen caer semanas después de venderlo.
                                </li>
                                <li>
                                    <i class="fas fa-file-signature text-slate-300 mr-1.5"></i>
                                    Comprueba que la cotización esté en estado <strong class="text-slate-600">Confirmado</strong>.
                                    Sólo esa transición genera operación; si la editaste después de confirmarla, usa
                                    <strong class="text-slate-600">«Enviar a Operaciones»</strong> en la cotización.
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!--
                  REJILLA DE FICHAS, agrupada por día.

                  Hasta el 25/08/2026 esto era UNA tabla con seis columnas marcadas
                  `hidden md:table-cell`. En el teléfono —que es donde se usa— no se convertía en
                  ficha: se quedaba una tabla a la que le faltaban columnas, con el servicio, el
                  expediente, el prestador y el costo apelotonados en una sola celda. Ni tabla ni
                  ficha.

                  Ahora es una ficha por servicio, en una o varias columnas según quepa. La ficha
                  enseña lo que se mira de pasada y **sólo se edita en línea lo que se toca a
                  diario**: los dos estados y la hora. Todo lo demás —puntos, notas, prestador,
                  proveedor, costo— vive en el formulario, a un toque de la plumita.
                -->
                <!--
                  Vacío por los filtros DE LA VISTA, no por los del servidor.

                  `filtroOs` y el de organización filtran lo ya cargado, así que pueden dejar el cuadro
                  a cero con la petición trayendo cuarenta filas: sin este bloque quedaba un hueco
                  en blanco sin una palabra que dijera por qué. Es el mismo principio que el chip
                  «Sin etiqueta»: un filtro que esconde en silencio no es un filtro, es un fallo.
                -->
                <div v-else-if="!serviciosPorDia.length" class="flex-1 flex items-center justify-center py-16 px-4">
                    <div class="text-center max-w-sm">
                        <div class="w-16 h-16 bg-slate-100 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-inner">
                            <i class="fas fa-filter-circle-xmark text-2xl text-slate-300"></i>
                        </div>
                        <p class="font-black text-slate-500 uppercase tracking-widest text-xs mb-1">Nada con estos filtros</p>
                        <p class="text-sm text-slate-400">
                            Hay {{ operacionStore.servicios.length }} servicio{{ operacionStore.servicios.length !== 1 ? 's' : '' }}
                            cargado{{ operacionStore.servicios.length !== 1 ? 's' : '' }}, pero ninguno pasa el filtro de esta pantalla.
                        </p>
                        <div class="mt-4 flex flex-wrap items-center justify-center gap-2">
                            <button v-if="organizacionesSeleccionadas.length" @click="organizacionesSeleccionadas = []"
                                    class="px-3 py-1.5 bg-white border border-slate-200 hover:border-[#376875] rounded-lg text-[10px] font-black uppercase tracking-widest text-slate-500">
                                <i class="fas fa-truck mr-1"></i>Quitar organización ({{ organizacionesSeleccionadas.length }})
                            </button>
                            <button v-if="filtroOs" @click="filtroOs = ''"
                                    class="px-3 py-1.5 bg-white border border-slate-200 hover:border-[#376875] rounded-lg text-[10px] font-black uppercase tracking-widest text-slate-500">
                                <i class="fas fa-file-invoice mr-1"></i>Quitar «{{ filtroOs === 'sin' ? 'sin OS' : 'en OS' }}»
                            </button>
                        </div>
                    </div>
                </div>

                <div v-else class="px-3 md:px-6 py-4 flex flex-col gap-5">
                    <div v-for="grupo in serviciosPorDia" :key="grupo.fecha">

                        <h2 class="flex items-center gap-2 mb-2 px-1">
                            <i class="fas fa-calendar-day text-[#376875] text-xs"></i>
                            <span class="text-xs font-black text-slate-700 uppercase tracking-widest">{{ etiquetaDia(grupo.fecha) }}</span>
                            <span class="text-[10px] font-bold text-slate-400">({{ grupo.servicios.length }})</span>
                            <span class="flex-1 h-px bg-slate-200"></span>

                            <!-- El interruptor de orden vivía bajo la columna «Hora» de la tabla,
                                 que ya no existe. Aquí sigue diciendo de qué va sin leerlo: está
                                 pegado al título del día, que es lo que reordena. -->
                            <button
                                @click="ordenPorHora = !ordenPorHora"
                                class="flex items-center gap-1 px-1.5 py-0.5 border rounded text-[9px] font-black uppercase tracking-wider transition-colors shrink-0"
                                :class="ordenPorHora ? 'bg-[#376875] text-white border-[#376875]'
                                    : 'bg-white text-slate-500 border-slate-200 hover:border-slate-400'"
                                :title="ordenPorHora ? 'Ordenado por hora. Toca para volver al orden del itinerario.'
                                    : 'Ordenado como el itinerario. Toca para ordenar por hora.'"
                            >
                                <i class="fas text-[8px]" :class="ordenPorHora ? 'fa-clock' : 'fa-list-ol'"></i>
                                {{ ordenPorHora ? 'Hora' : 'Itin.' }}
                            </button>
                        </h2>

                        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
                            <!-- Tocar el cuerpo de la ficha MARCA para la orden, que es lo que se
                                 hace en serie: se recorre el día marcando lo de un proveedor. El
                                 detalle y la edición tienen cada uno su botón arriba a la derecha
                                 —los dos iguales— porque son operaciones de una fila, no del
                                 barrido. Lo que se edita en línea para `@click.stop`, o tocar un
                                 desplegable marcaría además la ficha. -->
                            <article
                                v-for="servicio in grupo.servicios"
                                :key="servicio.id"
                                @click="esComprable(servicio) && alternarSeleccion(servicio.id)"
                                class="relative bg-white rounded-2xl border shadow-sm p-3 transition-all hover:shadow-md hover:border-[#376875]/40"
                                :class="[
                                    esComprable(servicio) ? 'cursor-pointer' : 'cursor-default',
                                    seleccionados.includes(servicio.id ?? '') ? 'border-[#376875] ring-1 ring-[#376875]/25' : 'border-slate-200',
                                    servicio.estadoComponente === 'cancelado' || servicio.modoComponente === 'reemplazado' ? 'opacity-60' : '',
                                ]"
                            >
                                <!-- ── Cabecera: a quién compro, cuándo, y qué es ────────── -->
                                <div class="flex items-start gap-2.5">
                                    <!-- Selección para la OS. Las filas de referencia no se marcan
                                         porque no pueden ir a una orden. -->
                                    <div class="pt-0.5 shrink-0">
                                        <input
                                            v-if="esComprable(servicio)"
                                            type="checkbox"
                                            :checked="seleccionados.includes(servicio.id ?? '')"
                                            @change="alternarSeleccion(servicio.id)"
                                            @click.stop
                                            class="w-4 h-4 accent-[#376875] cursor-pointer"
                                        />
                                        <i
                                            v-else
                                            class="fas text-slate-300 text-xs block mt-1"
                                            :class="servicio.soloReferencia ? 'fa-eye' : 'fa-ban'"
                                            :title="servicio.soloReferencia
                                                ? 'Sólo referencia: no se compra a ningún proveedor'
                                                : 'Cancelado o reemplazado en la cotización: no se compra'"
                                        ></i>
                                    </div>

                                    <!-- LA HORA, editable aquí porque es lo que más se cambia
                                         estando de pie. Vacía = se usa la hora con la que se vendió,
                                         y el marcador de posición la enseña.

                                         ⚠️ Sólo si el componente ADMITE hora (`admiteHora()`, que
                                         mira el flag del componente). Un ingreso al Koricancha es un
                                         ticket de horario variable: no tiene hora que fijar, y
                                         ofrecer el campo invitaba a inventarse una y mandársela al
                                         proveedor. -->
                                    <div class="shrink-0 text-center">
                                        <input
                                            v-if="admiteHora(servicio)"
                                            :value="servicio.horaRecojo ?? ''"
                                            @change="editarHora(servicio, $event)"
                                            @click.stop
                                            :placeholder="servicio.horaComponente || '--:--'"
                                            maxlength="5"
                                            class="w-[3.8rem] text-xs font-black bg-slate-100 px-1.5 py-1 rounded-lg border border-slate-200 tabular-nums text-center outline-none focus:ring-2 focus:ring-[#376875] focus:bg-white"
                                            :class="servicio.horaRecojo ? 'text-slate-900' : 'text-slate-400'"
                                            title="Hora de recojo. Vacía = se usa la hora con la que se vendió."
                                        />
                                        <span
                                            v-else
                                            class="block w-[3.8rem] text-[9px] font-black uppercase tracking-wide text-slate-300 px-1 py-1.5 rounded-lg border border-dashed border-slate-200"
                                            title="Este tipo de componente no lleva hora: se entra cuando se llega."
                                        >
                                            sin hora
                                        </span>
                                        <p v-if="admiteHora(servicio) && servicio.horaComponente && servicio.horaRecojo && servicio.horaRecojo !== servicio.horaComponente"
                                           class="text-[8px] font-bold text-slate-400 mt-0.5 tabular-nums"
                                           title="Hora con la que se vendió al cliente">
                                            vend. {{ servicio.horaComponente }}
                                        </p>
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <!-- ── LA EMPRESA, ENCIMA DE TODO ──────────────────────
                                             Es la base para marcar: la orden agrupa por comprador,
                                             y el barrido del día se hace buscando a quién se le
                                             pide cada cosa. Va arriba y destacada, pero más
                                             pequeña que el componente: manda en el vistazo, no en
                                             la jerarquía —lo que la fila ES sigue siendo el
                                             componente—.

                                             El comprador sólo se enseña cuando NO es el prestador
                                             (`mostrarComprador()`): repetir el mismo nombre dos
                                             veces en cada ficha convierte el dato en ruido. -->
                                        <p class="text-[11px] font-black uppercase tracking-wide leading-tight flex items-center gap-1 flex-wrap"
                                           :class="servicio.prestadorEfectivoNombre ? 'text-[#376875]' : 'text-amber-600'">
                                            <i class="fas fa-truck text-[9px] shrink-0"></i>
                                            <span class="truncate">
                                                {{ servicio.prestadorEfectivoNombre || (servicio.soloReferencia ? 'Referencia' : 'Sin asignar') }}
                                            </span>
                                            <span v-if="mostrarComprador(servicio)"
                                                  class="text-slate-400 normal-case tracking-normal font-bold truncate"
                                                  title="A quién se le compra: es por quien agrupa la Orden de Servicio">
                                                · compra {{ servicio.compradorEfectivoNombre || 'sin definir' }}
                                            </span>
                                        </p>

                                        <!-- EL COMPONENTE identifica la ficha; la tarifa es el detalle. -->
                                        <p class="mt-0.5 text-sm font-black text-slate-800 leading-tight flex items-start gap-1.5">
                                            <i :class="[getTipoComponenteConfig(servicio.tipoComponente).icon,
                                                       getTipoComponenteConfig(servicio.tipoComponente).text, 'text-sm mt-0.5 shrink-0']"
                                               :title="getTipoComponenteConfig(servicio.tipoComponente).label"></i>
                                            <span>{{ nombreComponenteDe(servicio) || nombreSegmentoDe(servicio) || servicio.contextoServicio || getTipoComponenteConfig(servicio.tipoComponente).label }}</span>
                                        </p>
                                        <p v-if="servicio.descripcionServicio" class="text-[11px] font-bold text-slate-500 leading-tight mt-1">
                                            <i class="fas fa-tag text-[8px] mr-1 text-slate-300"></i>{{ servicio.descripcionServicio }}
                                        </p>
                                    </div>

                                    <!-- Ver y editar, cada uno con su botón y del mismo tamaño: son
                                         las dos operaciones sobre UNA fila, y ninguna es la del
                                         barrido —esa es marcar, y se hace tocando la ficha—. -->
                                    <div class="shrink-0 flex flex-col gap-1">
                                        <button
                                            @click.stop="abrirFicha(servicio)"
                                            title="Ver el detalle"
                                            class="w-7 h-7 rounded-full bg-slate-50 text-slate-300 hover:text-[#376875] hover:bg-slate-100 flex items-center justify-center transition-colors"
                                        >
                                            <i class="fas fa-eye text-xs"></i>
                                        </button>
                                        <button
                                            @click.stop="abrirFicha(servicio, true)"
                                            title="Editar este servicio"
                                            class="w-7 h-7 rounded-full bg-slate-50 text-slate-300 hover:text-[#376875] hover:bg-slate-100 flex items-center justify-center transition-colors"
                                        >
                                            <i class="fas fa-pencil-alt text-xs"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- ── Ruta, en lectura. Editarla es cosa del formulario ──── -->
                                <p v-if="rutaDe(servicio)"
                                   class="mt-2 text-[10px] font-bold leading-snug flex items-start gap-1.5"
                                   :class="puntosDe(servicio)?.efectivo?.completo ? 'text-slate-500' : 'text-amber-700'">
                                    <i class="fas fa-route text-[9px] mt-0.5 shrink-0"
                                       :class="puntosDe(servicio)?.efectivo?.completo ? 'text-slate-300' : 'text-amber-500'"></i>
                                    <span>{{ rutaDe(servicio) }}</span>
                                </p>

                                <!-- Lo que se le dice al proveedor: se enseña, no se escribe aquí. -->
                                <p v-if="(servicio.notasPrestadorEfectivas ?? []).length"
                                   class="mt-1 text-[10px] font-medium text-slate-400 leading-snug flex items-start gap-1.5">
                                    <i class="fas fa-comment-dots text-[9px] text-slate-300 mt-0.5 shrink-0"></i>
                                    <span class="line-clamp-2">{{ (servicio.notasPrestadorEfectivas ?? []).join(' · ') }}</span>
                                </p>

                                <!-- ── Etiquetas: sólo cuando dicen algo ─────────────────── -->
                                <div class="flex flex-wrap gap-1 mt-2">
                                    <span
                                        v-for="lugar in operacionStore.lugaresDeServicio(servicio)"
                                        :key="lugar"
                                        class="px-1.5 py-0.5 inline-flex items-center gap-1 text-[9px] font-black rounded border bg-sky-50 text-sky-700 border-sky-200"
                                    >
                                        <i class="fas fa-map-marker-alt text-[8px]"></i> {{ lugar }}
                                    </span>
                                    <span
                                        v-if="servicio.soloReferencia"
                                        class="px-1.5 py-0.5 inline-flex items-center gap-1 text-[9px] font-black rounded border bg-indigo-50 text-indigo-600 border-indigo-200"
                                        title="Referencia operativa: no se compra a ningún proveedor y no entra en Órdenes de Servicio"
                                    >
                                        <i class="fas fa-eye text-[8px]"></i> Referencia
                                    </span>
                                    <span
                                        v-if="getModoComponenteConfig(servicio.modoComponente) && servicio.modoComponente !== 'incluido'"
                                        :class="['px-1.5 py-0.5 inline-flex items-center gap-1 text-[9px] font-black rounded border', getModoComponenteConfig(servicio.modoComponente)!.bg, getModoComponenteConfig(servicio.modoComponente)!.text, getModoComponenteConfig(servicio.modoComponente)!.border]"
                                    >
                                        <i :class="['fas text-[8px]', getModoComponenteConfig(servicio.modoComponente)!.icon]"></i>
                                        {{ getModoComponenteConfig(servicio.modoComponente)!.label }}
                                    </span>
                                    <span
                                        v-if="servicio.estadoComponente === 'cancelado'"
                                        :class="['px-1.5 py-0.5 inline-flex items-center gap-1 text-[9px] font-black rounded border', getEstadoComponenteConfig(servicio.estadoComponente)!.bg, getEstadoComponenteConfig(servicio.estadoComponente)!.text, getEstadoComponenteConfig(servicio.estadoComponente)!.border]"
                                    >
                                        <i :class="['fas text-[8px]', getEstadoComponenteConfig(servicio.estadoComponente)!.icon]"></i>
                                        Cancelado en cotización
                                    </span>
                                    <span
                                        v-if="servicio.ordenServicio"
                                        class="px-1.5 py-0.5 inline-flex items-center gap-1 text-[9px] font-black rounded border bg-[#E07845]/10 text-[#E07845] border-[#E07845]/30"
                                    >
                                        <i class="fas fa-file-invoice text-[8px]"></i> En OS
                                    </span>
                                </div>

                                <!-- ── Contexto: de qué viaje es esto y con quién se opera ── -->
                                <div class="mt-2 pt-2 border-t border-slate-100 flex flex-wrap items-center gap-x-3 gap-y-1 text-[10px] font-bold text-slate-400">
                                    <button v-if="servicio.file?.id" @click.stop="abrirExpediente(servicio)"
                                            class="text-[#376875] hover:underline decoration-dotted max-w-full truncate">
                                        <i class="fas fa-folder-open mr-1"></i>{{ servicio.file?.nombreGrupo || 'Sin expediente' }}
                                    </button>
                                    <span v-else><i class="fas fa-folder-open mr-1"></i>Sin expediente</span>

                                    <span class="shrink-0"><i class="fas fa-users mr-1"></i>{{ servicio.cantidadPax }}</span>
                                    <span v-if="nombreSegmentoDe(servicio) || servicio.contextoServicio" class="truncate">
                                        <i class="fas fa-map-signs mr-1"></i>{{ nombreSegmentoDe(servicio) || servicio.contextoServicio }}
                                    </span>
                                </div>

                                <!-- ── Los dos ESTADOS, en línea. Son lo que se mueve a diario ─ -->
                                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                    <select
                                        :value="servicio.estadoReservaProveedor"
                                        @change="guardarCampo(servicio, { estadoReservaProveedor: ($event.target as HTMLSelectElement).value })"
                                        @click.stop
                                        :disabled="guardando === servicio.id"
                                        :class="['px-2 py-1 text-[10px] font-black rounded-lg border cursor-pointer outline-none appearance-none', getEstadoReservaProveedorConfig(servicio.estadoReservaProveedor).bg, getEstadoReservaProveedorConfig(servicio.estadoReservaProveedor).text, getEstadoReservaProveedorConfig(servicio.estadoReservaProveedor).border]"
                                    >
                                        <option v-for="(cfg, k) in ESTADO_RESERVA_PROVEEDOR_CONFIG" :key="k" :value="k">{{ cfg.label }}</option>
                                    </select>
                                    <select
                                        :value="servicio.estadoOperacion"
                                        @change="guardarCampo(servicio, { estadoOperacion: ($event.target as HTMLSelectElement).value })"
                                        @click.stop
                                        :disabled="guardando === servicio.id"
                                        :class="['px-2 py-1 text-[10px] font-black rounded-lg border cursor-pointer outline-none appearance-none', getEstadoOperacionConfig(servicio.estadoOperacion).bg, getEstadoOperacionConfig(servicio.estadoOperacion).text, getEstadoOperacionConfig(servicio.estadoOperacion).border]"
                                    >
                                        <option v-for="(cfg, k) in ESTADO_OPERACION_CONFIG" :key="k" :value="k">{{ cfg.label }}</option>
                                    </select>

                                    <button
                                        v-if="servicio.estadoReservaProveedorDesde"
                                        @click.stop="abrirBitacora(servicio)"
                                        class="flex items-center gap-1 text-[9px] font-bold text-slate-400 hover:text-[#376875] transition-colors"
                                        title="Ver el historial de estados"
                                    >
                                        <i class="fas fa-clock-rotate-left text-[8px]"></i>
                                        {{ desdeHace(servicio.estadoReservaProveedorDesde) }}
                                    </button>

                                    <!-- El dinero, en LECTURA. Se negocia en el formulario, que es
                                         donde está el editor con su confirmación. -->
                                    <span v-if="!servicio.soloReferencia" class="ml-auto text-right shrink-0">
                                        <span class="block text-[10px] font-black tabular-nums"
                                              :class="servicio.costoNegociado ? 'text-slate-800' : 'text-slate-300'">
                                            <span class="text-slate-300 mr-0.5">{{ (servicio.costoNegociado ? servicio.monedaNegociada?.id : servicio.monedaCotizada?.id) || '' }}</span>{{ importe(servicio.costoNegociado ?? servicio.costoCotizado) }}
                                        </span>
                                        <span v-if="deltaOperativo(servicio) !== null && deltaOperativo(servicio) !== 0"
                                              class="block text-[9px] font-black tabular-nums"
                                              :class="deltaOperativo(servicio)! > 0 ? 'text-rose-600' : 'text-emerald-600'"
                                              :title="deltaOperativo(servicio)! > 0 ? 'Costó más de lo cotizado' : 'Costó menos de lo cotizado'">
                                            {{ deltaOperativo(servicio)! > 0 ? '+' : '−' }}{{ Math.abs(deltaOperativo(servicio)!).toFixed(2) }}
                                        </span>
                                    </span>
                                </div>
                            </article>
                        </div>
                    </div>
                </div>
            </section>

            <!-- PESTAÑA: ÓRDENES DE SERVICIO -------------------------------->
            <section v-else-if="activeTab === 'ordenes'" class="flex flex-col min-h-full">

                <div class="sticky top-0 z-10 bg-[#F8FAFC]/95 backdrop-blur-sm border-b border-slate-200 px-3 md:px-6 py-2.5 shrink-0">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-list-check text-[#E07845]"></i>
                        <!-- El rótulo dice lo que se ESTÁ viendo. Antes decía «vigentes» siempre,
                             también cuando la lista traía canceladas: un título que miente es peor
                             que no tenerlo, porque nadie vuelve a comprobarlo. -->
                        <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest">
                            {{ estadosOsVisibles.includes('cancelada') ? 'Órdenes' : 'Órdenes vigentes' }}
                        </span>
                        <span class="text-[10px] font-black text-slate-400 tabular-nums">{{ ordenesVisibles.length }}</span>

                        <button
                            v-if="estadosOsVisibles.length !== ESTADOS_OS_POR_DEFECTO.length
                                  || !ESTADOS_OS_POR_DEFECTO.every(e => estadosOsVisibles.includes(e))"
                            @click="estadosOsVisibles = [...ESTADOS_OS_POR_DEFECTO]"
                            class="ml-auto px-2 py-1 text-[9px] font-black uppercase tracking-wider text-slate-400 hover:text-[#E07845] transition-colors"
                        >
                            Restablecer
                        </button>
                    </div>

                    <!-- Un chip por estado, con su contador. Las CANCELADAS empiezan apagadas: una
                         anulada no se borra —es el rastro de lo que se le mandó a alguien— pero
                         tampoco debe leerse igual de vigente que el resto al repasar la lista. -->
                    <div class="mt-2 flex flex-wrap items-center gap-1">
                        <button
                            v-for="(cfg, estado) in ESTADO_OS_CONFIG"
                            :key="estado"
                            @click="alternarEstadoOs(estado as EstadoOsValue)"
                            :class="estadosOsVisibles.includes(estado as EstadoOsValue)
                                ? [cfg.bg, cfg.text, cfg.border]
                                : 'bg-white text-slate-400 border-slate-200 hover:border-slate-400'"
                            class="flex items-center gap-1 px-2 py-1 border rounded-lg text-[9px] font-black uppercase tracking-wider transition-colors"
                        >
                            <i class="fas" :class="cfg.icon"></i>
                            {{ cfg.label }}
                            <span v-if="conteoPorEstadoOs[estado]" class="tabular-nums opacity-70">
                                {{ conteoPorEstadoOs[estado] }}
                            </span>
                        </button>
                    </div>

                    <!-- ⚠️ Se dice SIEMPRE que hay alguna escondida. Una lista recortada sin aviso
                         se lee como «no hay nada», que es el fallo que ya costó las filas de tipo
                         `contacto` en La Biblia. -->
                    <p v-if="ordenesOcultasPorFiltro > 0"
                       class="mt-1.5 text-[10px] font-bold text-slate-400 flex items-center gap-1.5">
                        <i class="fas fa-eye-slash text-[9px]"></i>
                        <!-- «órdenes» lleva tilde y «orden» no: el plural la mueve, así que no
                             vale con pegarle «es» al singular. -->
                        {{ ordenesOcultasPorFiltro }} {{ ordenesOcultasPorFiltro === 1 ? 'orden' : 'órdenes' }}
                        sin mostrar por el filtro
                    </p>
                </div>

                <div v-if="operacionStore.isLoading" class="flex-1 flex items-center justify-center py-16">
                    <div class="text-center">
                        <i class="fas fa-spinner fa-spin text-3xl text-[#E07845] mb-3"></i>
                        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Cargando órdenes...</p>
                    </div>
                </div>

                <!-- ⚠️ Dos vacíos distintos, y confundirlos es mandar a alguien a crear una orden
                     que ya tiene. «No hay ninguna» se arregla generando; «las hay pero el filtro
                     las esconde» se arregla tocando un chip. -->
                <div v-else-if="operacionStore.ordenesServicio.length > 0 && ordenesVisibles.length === 0"
                     class="flex-1 flex items-center justify-center py-16 px-4">
                    <div class="text-center max-w-sm">
                        <div class="w-16 h-16 bg-slate-100 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-inner">
                            <i class="fas fa-filter-circle-xmark text-2xl text-slate-300"></i>
                        </div>
                        <p class="font-black text-slate-500 uppercase tracking-widest text-xs mb-1">Nada con este filtro</p>
                        <p class="text-sm text-slate-400">
                            Hay {{ operacionStore.ordenesServicio.length }} {{ operacionStore.ordenesServicio.length === 1 ? 'orden' : 'órdenes' }},
                            pero ninguna en los estados que estás viendo.
                        </p>
                        <button
                            @click="estadosOsVisibles = (Object.keys(ESTADO_OS_CONFIG) as EstadoOsValue[])"
                            class="mt-4 px-3 py-1.5 bg-white border border-slate-200 hover:border-[#E07845] rounded-lg text-[10px] font-black uppercase tracking-widest text-slate-500"
                        >
                            Ver todas
                        </button>
                    </div>
                </div>

                <div v-else-if="operacionStore.ordenesServicio.length === 0" class="flex-1 flex items-center justify-center py-16">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-slate-100 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-inner">
                            <i class="fas fa-file-invoice text-2xl text-slate-300"></i>
                        </div>
                        <p class="font-black text-slate-500 uppercase tracking-widest text-xs mb-1">Sin órdenes</p>
                        <p class="text-sm text-slate-400">Selecciona servicios en La Biblia y pulsa «Generar OS».</p>
                    </div>
                </div>

                <!--
                  TARJETAS, no tabla. Eran cinco columnas y en móvil se cortaban por la mitad:
                  el estado y las acciones quedaban fuera de la pantalla y no había forma de
                  llegar a ellos. Aquí cada orden fluye en dos filas y cabe entera.
                -->
                <div v-else class="px-3 md:px-6 py-3 md:py-4 flex flex-col gap-2">
                    <div
                        v-for="orden in ordenesVisibles"
                        :key="orden.id"
                        class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden"
                    >
                        <!-- Fila 1: identidad y estado -->
                        <div class="px-3 py-2.5 flex items-center justify-between gap-2 border-b border-slate-100">
                            <button @click="alternarDetalle(orden)"
                                    class="flex items-center gap-2 min-w-0 text-left">
                                <i class="fas text-[10px] text-slate-300 shrink-0"
                                   :class="ordenExpandida === orden.id ? 'fa-chevron-down' : 'fa-chevron-right'"></i>
                                <span class="text-sm font-black text-[#376875] truncate">{{ orden.numeroOs }}</span>
                            </button>

                            <span
                                :class="['px-2 py-1 inline-flex items-center gap-1 text-[9px] font-black rounded-lg border shrink-0', getEstadoOsConfig(orden.estadoOs).bg, getEstadoOsConfig(orden.estadoOs).text, getEstadoOsConfig(orden.estadoOs).border]"
                            >
                                <i :class="['fas text-[9px]', getEstadoOsConfig(orden.estadoOs).icon]"></i>
                                {{ getEstadoOsConfig(orden.estadoOs).label }}
                            </span>
                        </div>

                        <!-- CAMBIO MENOR: el proveedor confirmó la hora. No obliga a reemitir
                             —es el final normal del flujo, no un descuido— así que va en azul y
                             con su propio botón: darle el mismo que a reemitir invitaría a usar
                             el destructivo por costumbre. -->
                        <div v-if="orden.cambiosMenores?.length" class="px-3 py-2 bg-sky-50 border-b border-sky-200">
                            <p class="text-[10px] font-black text-sky-800 uppercase tracking-wider flex items-center gap-1.5">
                                <i class="fas fa-circle-info"></i>
                                Confirmación del proveedor
                            </p>
                            <ul class="mt-1 space-y-0.5">
                                <li v-for="(d, i) in orden.cambiosMenores" :key="i" class="text-[10px] text-sky-700 leading-snug">· {{ d }}</li>
                            </ul>
                            <button
                                v-if="orden.id"
                                @click="aplicarMenores(orden)"
                                class="mt-1.5 px-2 py-1 text-[10px] font-black text-sky-900 bg-sky-100 hover:bg-sky-200 border border-sky-300 rounded-lg transition-colors"
                            >
                                Actualizar y avisar
                            </button>
                            <p class="mt-1 text-[9px] text-sky-600 leading-snug">
                                La orden sigue vigente. Se confirma la hora al cliente y al proveedor.
                            </p>
                        </div>

                        <!-- SUCIA: La Biblia se movió después de emitir. No se corrige sola —un
                             documento mandado no cambia solo—: se avisa para que una persona
                             decida y reemita. El detalle va debajo porque «algo cambió» sin
                             decir QUÉ obliga a ir a buscarlo. -->
                        <div v-if="orden.divergencias?.length" class="px-3 py-2 bg-amber-50 border-b border-amber-200">
                            <p class="text-[10px] font-black text-amber-800 uppercase tracking-wider flex items-center gap-1.5">
                                <i class="fas fa-triangle-exclamation"></i>
                                Ya no coincide con La Biblia
                            </p>
                            <ul class="mt-1 space-y-0.5">
                                <li v-for="(d, i) in orden.divergencias" :key="i" class="text-[10px] text-amber-700 leading-snug">· {{ d }}</li>
                            </ul>
                            <button
                                v-if="orden.id"
                                @click="anularParaReemitir(orden)"
                                class="mt-1.5 px-2 py-1 text-[10px] font-black text-amber-900 bg-amber-100 hover:bg-amber-200 border border-amber-300 rounded-lg transition-colors"
                            >
                                Reemitir
                            </button>
                            <p class="mt-1 text-[9px] text-amber-600 leading-snug">
                                Se anula ésta y se crea la sucesora en borrador con los datos de hoy. Nada se pierde.
                            </p>
                        </div>

                        <!-- Fila 2: destinatario e importes por moneda -->
                        <div class="px-3 py-2.5 flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Destinatario</p>
                                <p class="text-sm font-bold text-slate-800 leading-snug">
                                    {{ orden.compradorNombre || 'No definido' }}
                                </p>

                                <!-- ⚠️ SALDADA no es un estado de la orden: es un hecho sobre el
                                     dinero, y por eso va aquí y no en el chip de `estadoOs`.
                                     Aquél dice en qué punto está el PAPELEO —emitida,
                                     confirmada, completada— y son dos ejes que no se mueven
                                     juntos: una orden puede estar completada y sin pagar, o
                                     pagada por adelantado y todavía sin confirmar. Ver
                                     `OperacionOrdenServicio::isSaldada()`.

                                     Con varias monedas es lo único que contesta de un vistazo:
                                     las líneas de la derecha dicen «pagado» por moneda, no si
                                     la orden entera está saldada. -->
                                <p v-if="orden.saldada"
                                   class="mt-1 inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-emerald-50 text-emerald-700 text-[9px] font-black uppercase tracking-widest">
                                    <i class="fas fa-circle-check text-[9px]"></i> Saldada
                                </p>
                            </div>

                            <!-- Una línea por moneda, sin convertir. Ver §5.4. -->
                            <div class="shrink-0 text-right">
                                <div v-for="t in (orden.totalesPorMoneda ?? [])" :key="t.moneda" class="leading-tight mb-0.5">
                                    <span class="text-[10px] font-bold text-slate-400 mr-1">{{ t.moneda }}</span>
                                    <span class="text-sm font-black text-slate-800 tabular-nums">{{ importe(t.real) }}</span>
                                    <!-- Con pagos: se ve el SALDO, que es lo que de verdad falta abonar. -->
                                    <p v-if="Number(t.pagado ?? 0) > 0"
                                       class="text-[9px] font-black tabular-nums"
                                       :class="Number(t.saldo ?? 0) <= 0 ? 'text-emerald-600' : 'text-[#E07845]'">
                                        {{ Number(t.saldo ?? 0) <= 0 ? 'pagado' : `saldo ${importe(t.saldo)}` }}
                                    </p>
                                    <p v-else-if="t.real !== t.cotizado" class="text-[9px] font-bold text-slate-400 tabular-nums">
                                        cotizado {{ importe(t.cotizado) }}
                                    </p>
                                </div>
                                <p v-if="!(orden.totalesPorMoneda ?? []).length" class="text-[10px] font-bold text-slate-300">
                                    Sin importes
                                </p>
                            </div>
                        </div>

                        <!-- Detalle: los servicios que agrupa. Estaba sin forma de verse. -->
                        <div v-if="ordenExpandida === orden.id" class="px-3 pb-2.5 border-t border-slate-100 pt-2">
                            <!-- ══ EL DOCUMENTO CONGELADO ═══════════════════════════════════
                                 En cuanto la orden deja de ser borrador, lo que se pinta son sus
                                 ÍTEMS, no las filas vivas.

                                 ⚠️ Antes se pintaba siempre desde el enlace vivo, y eso mentía dos
                                 veces: una orden ANULADA salía vacía —suelta sus servicios por
                                 diseño— aunque su documento estuviera entero en la base; y una
                                 EMITIDA enseñaba lo que La Biblia dice AHORA, no lo que el
                                 proveedor tiene en la mano. Justo lo que `getDivergencias()` existe
                                 para denunciar, pintado como si fuera el documento. -->
                            <template v-if="orden.estadoOs !== 'borrador'">
                                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1.5">
                                    {{ (orden.items ?? []).length }} línea(s) · documento emitido
                                </p>

                                <!-- Lo que falta por decir al proveedor. Se avisa, no se bloquea:
                                     la decisión es de la persona, el sistema hace visible la
                                     consecuencia. -->
                                <p v-for="aviso in (orden.avisosDeRutas ?? [])" :key="aviso"
                                   class="text-[10px] font-bold text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-1 mb-1.5">
                                    <i class="fas fa-triangle-exclamation mr-1"></i>{{ aviso }}
                                </p>

                                <!-- Qué significan las pastillas, dicho una vez y no en cada
                                     línea. Sin esto, «Auto» a secas no dice ni de qué es ni qué
                                     hace: era un control que había que adivinar. -->
                                <p v-if="puedeAjustarRutas(orden)" class="text-[10px] font-bold text-slate-400 leading-snug mb-1.5">
                                    <i class="fas fa-circle-info mr-1"></i>
                                    Las pastillas deciden qué se le imprime al proveedor.
                                    <b class="text-slate-500">Auto</b>: varios servicios seguidos del mismo proveedor dicen sólo
                                    dónde empieza y dónde acaba. <b class="text-slate-500">Siempre</b>: se imprime aunque esté en medio.
                                    <b class="text-slate-500">Oculto</b>: no se imprime.
                                    El <b class="text-slate-500">texto</b> del punto se edita en La Biblia, y cambiarlo sí obliga a reemitir.
                                </p>

                                <!-- Agrupado por DÍA: la regla de cadenas trabaja por jornada, así
                                     que ver las líneas sueltas obliga a reconstruir de cabeza qué
                                     va con qué. Con el día delante, se ve la cadena. -->
                                <div v-for="(lineas, dia) in lineasPorDia(orden)" :key="dia" class="mb-2">
                                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">
                                        <i class="far fa-calendar mr-1"></i>{{ dia }}
                                    </p>
                                    <div class="flex flex-col gap-1.5">
                                    <div v-for="it in lineas" :key="it.id ?? ''"
                                         class="bg-slate-50 rounded-lg px-2 py-1.5">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="min-w-0">
                                                <p class="text-[11px] font-black text-slate-800 leading-snug">{{ it.descripcion }}</p>
                                                <p class="text-[10px] text-slate-400 leading-snug">
                                                    <span v-if="it.hora">{{ it.hora }}</span>
                                                    <span v-if="it.cantidadPax"> · {{ it.cantidadPax }} pax</span>
                                                    <span v-if="it.prestadorNombre"> · {{ it.prestadorNombre }}</span>
                                                </p>
                                                <p v-if="(orden.rutasVisibles ?? {})[it.id ?? '']"
                                                   class="text-[10px] font-bold text-slate-600 leading-snug mt-0.5">
                                                    <i class="fas fa-route text-[8px] mr-1 text-slate-300"></i>{{ (orden.rutasVisibles ?? {})[it.id ?? ''] }}
                                                </p>
                                                <!-- Congelado al emitir, no en vivo: lo que el proveedor
                                                     tiene en la mano es esto. Si cambia, la orden lo
                                                     denuncia y hay que reemitir. -->
                                                <p v-for="nota in (it.notasPrestador ?? [])" :key="nota"
                                                   class="text-[10px] font-bold text-slate-600 leading-snug mt-0.5">
                                                    <i class="fas fa-comment-dots text-[8px] mr-1 text-slate-300"></i>{{ nota }}
                                                </p>
                                            </div>
                                            <p class="text-[11px] font-black text-slate-700 tabular-nums shrink-0">
                                                <span class="text-slate-300 mr-1">{{ it.moneda?.id || '' }}</span>{{ importe(it.importe) }}
                                            </p>
                                        </div>

                                        <!-- ── Qué ve el proveedor, línea por línea ──────────
                                             Aquí y no en La Biblia porque aquí está el contexto:
                                             se ve la cadena entera y qué renglones salen. Cambiarlo
                                             NO obliga a reemitir — ocultar dice menos, no dice algo
                                             falso; cambiar el TEXTO de un punto sí sería pacto. -->
                                        <div v-if="puedeAjustarRutas(orden) && (it.puntoRecojoConfirmado || it.puntoEntregaConfirmado)"
                                             class="mt-1.5 pt-1.5 border-t border-slate-200 flex flex-wrap gap-1.5">
                                            <button v-if="it.puntoRecojoConfirmado"
                                                    @click.stop="alternarVisibilidad(orden, it, 'recojo')"
                                                    :disabled="ajustandoRutas"
                                                    class="text-[9px] font-black uppercase tracking-wider px-2 py-0.5 rounded border transition-colors disabled:opacity-40"
                                                    :class="claseVisibilidad(it.visibilidadRecojo, efectiva(orden, it, 'recojo'))"
                                                    :title="`Recojo: ${etiquetaVisibilidad(it.visibilidadRecojo)}. Toca para cambiar.`">
                                                <i class="fas fa-location-dot mr-1"></i>Recojo: {{ textoVisibilidad(it.visibilidadRecojo, efectiva(orden, it, 'recojo')) }}
                                            </button>
                                            <button v-if="it.puntoEntregaConfirmado"
                                                    @click.stop="alternarVisibilidad(orden, it, 'entrega')"
                                                    :disabled="ajustandoRutas"
                                                    class="text-[9px] font-black uppercase tracking-wider px-2 py-0.5 rounded border transition-colors disabled:opacity-40"
                                                    :class="claseVisibilidad(it.visibilidadEntrega, efectiva(orden, it, 'entrega'))"
                                                    :title="`Entrega: ${etiquetaVisibilidad(it.visibilidadEntrega)}. Toca para cambiar.`">
                                                <i class="fas fa-flag-checkered mr-1"></i>Entrega: {{ textoVisibilidad(it.visibilidadEntrega, efectiva(orden, it, 'entrega')) }}
                                            </button>
                                        </div>
                                    </div>
                                    </div>
                                </div>
                            </template>

                            <template v-else>
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1.5">
                                {{ serviciosDeOrdenAbierta.length }} servicio(s)
                            </p>
                                <div class="flex flex-col gap-1.5">
                                    <div v-for="s in serviciosDeOrdenAbierta" :key="s.id ?? ''"
                                         class="flex items-start justify-between gap-2 bg-slate-50 rounded-lg px-2 py-1.5">
                                        <!-- Aquí manda `descripcionServicio`, al contrario que en La
                                             Biblia. Es el nombre con el que el PROVEEDOR conoce el
                                             servicio —sale de `nombreParaProveedor` si está puesto— y
                                             la orden es el documento que se le manda a él. El día del
                                             itinerario queda debajo: sirve para ubicarlo, no para
                                             pedirlo. Ver docs/Operacion.md §3.9 bis. -->
                                        <div class="min-w-0">
                                            <!-- El COMPONENTE encabeza, igual que en La Biblia y en
                                                 la previsualización: es lo que dice si esto es un
                                                 ticket o un guiado. Debajo va el nombre con el que
                                                 lo conoce el PROVEEDOR, que es lo que se le pide.

                                                 ⚠️ Aquí el nombre del maestro puede no estar
                                                 resuelto: estos servicios vienen con la orden, no
                                                 del cuadro, y el lote de nombres se arma con las
                                                 filas del cuadro. Por eso el respaldo es la
                                                 etiqueta del tipo, que viaja en el snapshot. -->
                                            <p class="text-[11px] font-black text-slate-800 leading-snug flex items-center gap-1.5">
                                                <i :class="[getTipoComponenteConfig(s.tipoComponente).icon,
                                                           getTipoComponenteConfig(s.tipoComponente).text, 'text-[10px] shrink-0']"
                                                   :title="getTipoComponenteConfig(s.tipoComponente).label"></i>
                                                <span class="truncate">
                                                    {{ nombreComponenteDe(s) || getTipoComponenteConfig(s.tipoComponente).label }}
                                                </span>
                                            </p>
                                            <p class="text-[11px] font-bold text-slate-600 leading-snug">
                                                {{ s.descripcionServicio }}
                                            </p>
                                            <!-- El nombre INTERNO de la tarifa, sólo cuando aporta algo.
                                                 Si coincide con el de arriba es que la tarifa no tiene
                                                 `nombreParaProveedor` y `descripcionServicio` ya cayó a
                                                 él: repetirlo seria la misma linea dos veces. -->
                                            <p v-if="s.tarifaNombre && s.tarifaNombre !== s.descripcionServicio"
                                               class="text-[10px] font-bold text-slate-500 leading-snug"
                                               title="Nombre interno de la tarifa: con éste la buscas en el tarifario">
                                                <i class="fas fa-tag text-[8px] mr-1 text-slate-300"></i>{{ s.tarifaNombre }}
                                            </p>
                                            <p v-if="s.contextoServicio" class="text-[10px] text-slate-400 leading-snug">
                                                {{ s.contextoServicio }}
                                            </p>

                                            <!-- ── DÓNDE RECOJO / DÓNDE DEJO ──────────────────
                                                 Es la primera pregunta del proveedor, y aquí es
                                                 donde hay que poder revisarlo: ANTES de emitir.
                                                 Después el documento ya está en sus manos y
                                                 corregirlo cuesta anular y reemitir.

                                                 Se enseña el punto EFECTIVO —override incluido y
                                                 hotel resuelto—, que es exactamente lo que se va a
                                                 congelar. En ámbar si falta algo. -->
                                            <p v-if="rutaDe(s)"
                                               class="text-[10px] font-bold leading-snug mt-0.5"
                                               :class="puntosDe(s)?.efectivo?.completo ? 'text-slate-500' : 'text-amber-700'">
                                                <i class="fas fa-route text-[8px] mr-1"
                                                   :class="puntosDe(s)?.efectivo?.completo ? 'text-slate-300' : 'text-amber-500'"></i>{{ rutaDe(s) }}
                                            </p>

                                            <!-- ⚠️ La información operativa: «Delta LATAM LA-2695
                                                 Aterriza 22:00». Faltaba en este panel, así que se
                                                 veía en La Biblia y desaparecía justo en la
                                                 pantalla desde la que se manda la orden — sin ella
                                                 no se puede repasar lo que el proveedor va a
                                                 recibir. En un borrador se leen EN VIVO
                                                 (`…Efectivas`); al emitir se congelan en el ítem. -->
                                            <p v-for="nota in (s.notasPrestadorEfectivas ?? [])" :key="nota"
                                               class="text-[10px] font-bold text-slate-600 leading-snug mt-0.5">
                                                <i class="fas fa-comment-dots text-[8px] mr-1 text-slate-300"></i>{{ nota }}
                                            </p>
                                            <p class="text-[10px] text-slate-400 leading-snug">
                                                {{ (s.fechaServicio ?? '').slice(0, 10) }} · {{ nochesTexto(s.cantidadComponente, s.tipoComponente) }}{{ s.cantidadPax }} pax
                                            </p>
                                        </div>
                                        <!-- Lo NEGOCIADO, editable aquí mismo: es el momento en que
                                             tienes al proveedor al teléfono y la orden delante. El
                                             cotizado queda debajo como referencia y no se toca. -->
                                        <div class="shrink-0">
                                            <EditorCostoNegociado
                                                :ref="(el) => registrarEditor(idDe(s), el)"
                                                denso
                                                :costo-cotizado="s.costoCotizado"
                                                :desglose="s.desgloseCotizado"
                                                :moneda-cotizada="s.monedaCotizada?.id ?? ''"
                                                :costo-negociado="s.costoNegociado"
                                                :moneda-negociada="s.monedaNegociada?.id ?? null"
                                                :monedas="operacionStore.monedas"
                                                @guardar="(pl) => onGuardarCosto(s, pl)"
                                            />
                                        </div>
                                    </div>
                                </div>
                                </template>

                                <p v-if="orden.estadoOs === 'borrador'" class="text-[10px] text-slate-400 leading-snug mt-1.5">
                                    <i class="fas fa-circle-info mr-1"></i>
                                    Toca el importe para editarlo. Vacío = todavía sin negociar, vale el
                                    cotizado. Estos campos son tuyos: una resincronización de la cotización
                                    nunca los pisa.
                                </p>
                        </div>

                        <div class="px-3 py-2 bg-slate-50 border-t border-slate-100 flex justify-end gap-2 flex-wrap">
                            <!-- ⚠️ ELIMINAR sólo en BORRADOR. Una orden que ya salió al
                                 proveedor se ANULA —eso también devuelve sus servicios al pool—
                                 pero deja constancia de que existió. Lo impide igualmente
                                 `OperacionOrdenBorradoListener`; esto sólo evita ofrecer un
                                 botón que va a responder 403. -->
                            <button
                                v-if="orden.estadoOs === 'borrador'"
                                @click="eliminarOrden(orden)"
                                :disabled="borrandoOrden === orden.id"
                                :title="confirmandoOrden === orden.id
                                    ? 'Pulsa otra vez para confirmar'
                                    : 'Elimina el borrador y devuelve sus servicios al pool'"
                                class="mr-auto inline-flex items-center gap-1.5 px-3 py-1.5 text-[10px] font-black uppercase tracking-widest rounded-lg border transition-all shadow-sm disabled:opacity-40"
                                :class="confirmandoOrden === orden.id
                                    ? 'bg-rose-600 text-white border-rose-600'
                                    : 'bg-white hover:bg-rose-50 text-rose-500 border-slate-200 hover:border-rose-200'"
                            >
                                <i class="fas text-[9px]" :class="borrandoOrden === orden.id ? 'fa-circle-notch fa-spin' : 'fa-trash-alt'"></i>
                                {{ confirmandoOrden === orden.id ? '¿Seguro? Los servicios vuelven al pool' : 'Eliminar' }}
                            </button>

                            <!-- Las acciones de ESTADO: sólo las que caben desde donde está, y
                                 cada una confirma antes. Ver `accionesDe()`. -->
                            <button
                                v-for="accion in accionesDe(orden.estadoOs)"
                                :key="accion.id"
                                @click="ejecutarAccion(orden, accion)"
                                :disabled="ejecutandoAccion === `${orden.id}:${accion.id}`"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[10px] font-black uppercase tracking-widest rounded-lg border transition-all shadow-sm disabled:opacity-40"
                                :class="confirmandoAccion === `${orden.id}:${accion.id}`
                                    ? (accion.tono === 'grave' ? 'bg-rose-600 text-white border-rose-600' : 'bg-[#376875] text-white border-[#376875]')
                                    : (accion.tono === 'grave'
                                        ? 'bg-white hover:bg-rose-50 text-rose-500 border-slate-200 hover:border-rose-200'
                                        : 'bg-white hover:bg-[#376875] hover:text-white hover:border-[#376875] text-slate-600 border-slate-200')"
                            >
                                <i class="fas text-[9px]"
                                   :class="ejecutandoAccion === `${orden.id}:${accion.id}` ? 'fa-circle-notch fa-spin' : accion.icono"></i>
                                {{ confirmandoAccion === `${orden.id}:${accion.id}` ? consecuenciaDe(accion.id) : accion.etiqueta }}
                            </button>

                            <!-- ENVIAR: separado de emitir a propósito. Emitir congela el
                                 contenido; esto se lo pone delante al proveedor, y no se
                                 retira. Por eso es el mismo botón para enviar y para
                                 REENVIAR — que es lo normal cuando se perdió el correo. -->
                            <button
                                v-if="orden.estadoOs === 'emitida' || orden.estadoOs === 'confirmada' || orden.estadoOs === 'completada'"
                                @click="abrirEnvio(orden)"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white hover:bg-emerald-600 hover:text-white hover:border-emerald-600 text-emerald-700 text-[10px] font-black uppercase tracking-widest rounded-lg border border-slate-200 transition-all shadow-sm"
                                title="Previsualiza y manda la orden al proveedor. Se puede reenviar las veces que haga falta."
                            >
                                <i class="fas fa-paper-plane text-[9px]"></i> Enviar
                            </button>

                            <!-- Reemitir: anula y deja la sucesora ya creada, en borrador. Vivía
                                 sólo dentro del aviso de divergencias con La Biblia, así que no
                                 se podía reemitir por cualquier otro motivo —un cambio de precio
                                 pactado, un error en el documento— sin pasar por el desplegable. -->
                            <button
                                v-if="orden.estadoOs === 'emitida' || orden.estadoOs === 'confirmada'"
                                @click="anularParaReemitir(orden)"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white hover:bg-amber-500 hover:text-white hover:border-amber-500 text-amber-600 text-[10px] font-black uppercase tracking-widest rounded-lg border border-slate-200 transition-all shadow-sm"
                                title="Anula ésta y crea la sucesora en borrador con los datos de hoy. Nada se pierde."
                            >
                                <i class="fas fa-rotate text-[9px]"></i> Reemitir
                            </button>

                            <button
                                @click="abrirEdicion(orden)"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white hover:bg-[#376875] hover:text-white hover:border-[#376875] text-slate-600 text-[10px] font-black uppercase tracking-widest rounded-lg border border-slate-200 transition-all shadow-sm"
                            >
                                <i class="fas fa-pen text-[9px]"></i> Editar
                            </button>
                            <button
                                @click="abrirPagos(orden)"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white hover:bg-emerald-600 hover:text-white hover:border-emerald-600 text-slate-600 text-[10px] font-black uppercase tracking-widest rounded-lg border border-slate-200 transition-all shadow-sm"
                            >
                                <i class="fas fa-hand-holding-dollar text-[9px]"></i> Pagos
                            </button>
                            <button
                                @click="abrirMensajes(orden)"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white hover:bg-[#376875] hover:text-white hover:border-[#376875] text-slate-600 text-[10px] font-black uppercase tracking-widest rounded-lg border border-slate-200 transition-all shadow-sm"
                            >
                                <i class="fas fa-message text-[9px]"></i> Bitácora
                            </button>

                            <p v-if="errorOrden?.id === orden.id"
                               class="w-full text-[11px] font-bold text-rose-600 leading-snug text-left">
                                <i class="fas fa-triangle-exclamation mr-1"></i>{{ errorOrden?.motivo }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

        </main>

        <!-- ================================================================
             MODAL: EXPEDIENTE (namelist + documentos + salto a cotización)

             Dos paneles colapsables. Mobile-first: hoja inferior en móvil, tarjeta en ancho.
             El botón de cotización salta al editor de la versión de la que cuelga el servicio
             clicado, que es lo que pidió el operador.
             ================================================================ -->
        <div v-if="expedienteAbierto" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-slate-900/50" @click.self="cerrarModal()">
            <div class="bg-white rounded-t-2xl sm:rounded-2xl shadow-xl w-full sm:max-w-lg max-h-[88vh] flex flex-col overflow-hidden">
                <header class="bg-slate-900 text-white px-5 py-3 flex items-center gap-2 shrink-0">
                    <i class="fas fa-folder-open text-[#E07845]"></i>
                    <div class="min-w-0">
                        <h3 class="font-black text-sm tracking-tight leading-tight truncate">{{ expedienteAbierto.nombre }}</h3>
                        <p v-if="expedienteDetalle?.pasajeroPrincipal" class="text-[10px] text-slate-400 truncate">{{ expedienteDetalle.pasajeroPrincipal }}</p>
                        <!-- Localizador + versión: copiable, y enlace a la vista del cliente (app pax,
                             otro dominio → otra pestaña). -->
                        <div v-if="expedienteAbierto.localizador" class="flex items-center gap-2 mt-1">
                            <button @click="copiarLocalizador"
                                    class="inline-flex items-center gap-1 text-[10px] font-black text-white bg-white/10 hover:bg-white/20 rounded px-1.5 py-0.5 tracking-wider transition-colors"
                                    :title="localizadorCopiado ? 'Copiado' : 'Copiar localizador'">
                                {{ localizadorVersion }}
                                <i class="fas text-[9px]" :class="localizadorCopiado ? 'fa-check text-emerald-300' : 'fa-copy text-slate-300'"></i>
                            </button>
                            <a v-if="linkPax" :href="linkPax" target="_blank" rel="noopener"
                               class="inline-flex items-center gap-1 text-[10px] font-bold text-sky-300 hover:text-sky-200"
                               title="Abrir la vista del cliente en otra pestaña">
                                <i class="fas fa-arrow-up-right-from-square text-[9px]"></i> Vista cliente
                            </a>
                        </div>
                    </div>
                    <button @click="cerrarModal()" class="ml-auto text-slate-400 hover:text-white shrink-0">
                        <i class="fas fa-xmark"></i>
                    </button>
                </header>

                <div class="overflow-y-auto">
                    <p v-if="cargandoExpediente" class="text-xs text-slate-400 text-center py-8">
                        <i class="fas fa-spinner fa-spin mr-1"></i> Cargando…
                    </p>
                    <template v-else>
                        <!-- Panel: NAMELIST -->
                        <div class="border-b border-slate-100">
                            <button @click="panelNamelist = !panelNamelist"
                                    class="w-full px-4 py-3 flex items-center justify-between hover:bg-slate-50 transition-colors">
                                <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest">
                                    <i class="fas fa-users mr-1 text-slate-300"></i>
                                    Namelist ({{ (expedienteDetalle?.filepasajeros ?? []).length }})
                                </span>
                                <i class="fas text-[10px] text-slate-300" :class="panelNamelist ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                            </button>
                            <div v-if="panelNamelist" class="px-4 pb-3">
                                <p v-if="!(expedienteDetalle?.filepasajeros ?? []).length" class="text-[11px] text-slate-400 py-2">
                                    Sin pasajeros cargados.
                                </p>
                                <div v-else class="flex flex-col divide-y divide-slate-100">
                                    <div v-for="(pax, i) in (expedienteDetalle?.filepasajeros ?? [])" :key="i" class="py-2">
                                        <p class="text-sm font-bold text-slate-800">{{ nombrePasajero(pax) }}</p>
                                        <p class="text-[10px] text-slate-400">
                                            <span v-for="(ident, idxDoc) in (pax.identificaciones ?? [])" :key="idxDoc">
                                              <span v-if="idxDoc"> · </span>{{ ident.tipo || 'Doc' }}: {{ ident.numero }}
                                            </span>
                                            <span v-if="pax.pais && typeof pax.pais === 'object'"> · {{ (pax.pais as { nombre?: string }).nombre }}</span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Panel: DOCUMENTOS -->
                        <div>
                            <button @click="panelDocumentos = !panelDocumentos"
                                    class="w-full px-4 py-3 flex items-center justify-between hover:bg-slate-50 transition-colors">
                                <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest">
                                    <i class="fas fa-paperclip mr-1 text-slate-300"></i>
                                    Documentos ({{ (expedienteDetalle?.filearchivos ?? []).length }})
                                </span>
                                <i class="fas text-[10px] text-slate-300" :class="panelDocumentos ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                            </button>
                            <div v-if="panelDocumentos" class="px-4 pb-3">
                                <!-- Subir documento (voucher, confirmación de reserva…) sin salir del
                                     cuadro: se generan justo al operar. Reutiliza el endpoint multipart
                                     de FileDetalle; el tipo sale del nombre del archivo. -->
                                <button @click="docInputRef?.click()" :disabled="subiendoDoc"
                                        class="w-full mb-2 py-2 flex items-center justify-center gap-2 text-[11px] font-black uppercase tracking-widest text-[#376875] bg-[#376875]/8 hover:bg-[#376875]/15 disabled:opacity-50 rounded-lg border border-dashed border-[#376875]/30 transition-colors">
                                    <i class="fas" :class="subiendoDoc ? 'fa-spinner fa-spin' : 'fa-cloud-arrow-up'"></i>
                                    {{ subiendoDoc ? 'Subiendo…' : 'Subir documento' }}
                                </button>
                                <input ref="docInputRef" type="file" class="hidden" @change="onSubirDocumento" />

                                <p v-if="!(expedienteDetalle?.filearchivos ?? []).length" class="text-[11px] text-slate-400 py-2">
                                    Sin archivos cargados.
                                </p>
                                <div v-else class="grid grid-cols-1 gap-2">
                                    <a v-for="(doc, i) in (expedienteDetalle?.filearchivos ?? [])" :key="i"
                                       :href="(doc.imageUrl as string) || '#'" target="_blank" rel="noopener"
                                       class="flex items-center gap-3 bg-slate-50 hover:bg-slate-100 rounded-lg px-3 py-2 transition-colors">
                                        <i class="fas fa-file-lines text-[#376875]"></i>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm font-bold text-slate-700 truncate">{{ (doc.tipodocumento as string) || 'Documento' }}</p>
                                            <p v-if="doc.vencimiento" class="text-[10px] text-slate-400">vence {{ String(doc.vencimiento).slice(0, 10) }}</p>
                                        </div>
                                        <i class="fas fa-arrow-up-right-from-square text-[10px] text-slate-300"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Accesos: al expediente completo y a la cotización del servicio -->
                <div class="px-4 py-3 border-t border-slate-200 bg-slate-50 shrink-0 flex gap-2">
                    <button @click="irAExpediente"
                            class="flex-1 py-2.5 bg-white hover:bg-slate-100 border border-slate-300 text-[#376875] rounded-lg text-xs font-black uppercase tracking-widest shadow-sm transition-colors">
                        <i class="fas fa-folder-open mr-1"></i>
                        Expediente
                    </button>
                    <button @click="irACotizacion" :disabled="!expedienteAbierto.cotizacionId"
                            class="flex-1 py-2.5 bg-[#E07845] hover:bg-[#c96636] disabled:opacity-40 text-white rounded-lg text-xs font-black uppercase tracking-widest shadow-sm transition-colors">
                        <i class="fas fa-file-invoice-dollar mr-1"></i>
                        Cotización
                    </button>
                </div>
            </div>
        </div>

        <!-- ================================================================
             MODAL: ENVIAR LA ORDEN AL PROVEEDOR

             Se previsualiza porque es IRREVERSIBLE. El cuerpo se pinta en `<pre>` para que se
             vea EXACTAMENTE como va a salir: un `<div>` colapsaría los saltos de línea y el
             operador aprobaría un texto distinto del que se manda.
             ================================================================ -->
        <div v-if="ordenAEnviar" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-slate-900/50" @click.self="ordenAEnviar = null">
            <div class="bg-white rounded-t-2xl sm:rounded-2xl shadow-xl w-full sm:max-w-2xl max-h-[90vh] flex flex-col overflow-hidden">
                <header class="bg-slate-900 text-white px-5 py-3 flex items-center gap-2 shrink-0">
                    <i class="fas fa-paper-plane text-emerald-400"></i>
                    <div class="min-w-0">
                        <h3 class="font-black text-sm tracking-tight leading-tight">Enviar al proveedor</h3>
                        <p class="text-[10px] text-slate-400 truncate">{{ documento?.destinatario || ordenAEnviar.compradorNombre || ordenAEnviar.numeroOs }}</p>
                    </div>
                    <button @click="ordenAEnviar = null" class="ml-auto text-slate-400 hover:text-white shrink-0">
                        <i class="fas fa-xmark"></i>
                    </button>
                </header>

                <div class="overflow-y-auto px-5 py-4 space-y-4">
                    <p v-if="cargandoDocumento" class="text-xs text-slate-400 text-center py-6">
                        <i class="fas fa-spinner fa-spin mr-1"></i> Preparando el documento…
                    </p>

                    <template v-else-if="documento">
                        <div>
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Asunto</p>
                            <p class="text-sm font-bold text-slate-800">{{ documento.asunto }}</p>
                        </div>

                        <div>
                            <div class="flex items-center gap-2 mb-1">
                                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">
                                    Lo que se le manda · {{ documento.lineas }} línea(s)
                                </p>
                                <!-- Para los grupos: Meta NO deja escribir en un grupo que ya
                                     existe —la Groups API sólo sirve para los que crea el propio
                                     número, por invitación y con ocho participantes—. Así que ahí
                                     se pega a mano, y lo que se copia incluye el enlace, igual que
                                     lo que manda la API. -->
                                <button
                                    @click="copiarParaWhatsapp"
                                    :class="copiado ? 'bg-emerald-600 border-emerald-600 text-white' : 'bg-white border-slate-200 text-slate-600 hover:border-[#376875] hover:text-[#376875]'"
                                    class="ml-auto shrink-0 flex items-center gap-1.5 px-2.5 py-1 border rounded-lg text-[10px] font-black uppercase tracking-widest transition-colors shadow-sm"
                                    title="Copia el texto con el formato de WhatsApp, para pegarlo en un grupo"
                                >
                                    <i :class="copiado ? 'fas fa-check' : 'fas fa-copy'" class="text-[10px]"></i>
                                    {{ copiado ? 'Copiado' : 'Copiar' }}
                                </button>
                            </div>
                            <pre id="cuerpo-del-mensaje" class="text-xs text-slate-700 bg-slate-50 border border-slate-200 rounded-xl p-3 whitespace-pre-wrap font-sans leading-relaxed">{{ documento.cuerpo }}</pre>
                            <p class="text-[10px] text-slate-400 mt-1 leading-snug">
                                Sale de las líneas congeladas al emitir, y no lleva importes: lo que se paga
                                se lleva aparte. <b>Copiar</b> se lleva también el enlace, con el formato de
                                WhatsApp puesto.
                            </p>
                        </div>

                        <div v-if="documento.enlace">
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Enlace que recibe</p>
                            <a :href="documento.enlace" target="_blank" rel="noopener noreferrer"
                               class="text-xs font-bold text-[#376875] hover:underline break-all">{{ documento.enlace }}</a>
                            <p class="text-[10px] text-slate-400 mt-1 leading-snug">
                                Abre la orden sin necesidad de cuenta, con botón para descargar el PDF.
                            </p>
                        </div>

                        <!-- ⚠️ Fuera de la ventana de 24 h, Meta sólo admite plantillas aprobadas
                             y una orden con varias líneas no cabe en una. Se dice ANTES de elegir
                             canal, no después de leer todo el documento. -->
                        <p v-if="!documento.ventanaWhatsappAbierta"
                           class="text-[11px] font-bold text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 leading-snug">
                            <i class="fas fa-triangle-exclamation mr-1"></i>
                            La ventana de 24 h de WhatsApp está cerrada: Meta sólo deja mandar plantillas
                            aprobadas. Usa el correo, o espera a que el proveedor escriba.
                        </p>

                        <div>
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Por dónde</p>
                            <div class="flex flex-wrap gap-2">
                                <!-- Los no disponibles se enseñan con su motivo en vez de esconderse:
                                     «no aparece WhatsApp» obliga a adivinar; «sin datos o vetado» no. -->
                                <button v-for="c in documento.canales" :key="c.id"
                                        @click="c.disponible && (canalElegido = c.id)"
                                        :disabled="!c.disponible"
                                        :title="c.motivo ?? ''"
                                        class="px-3 py-2 rounded-lg border text-[11px] font-black uppercase tracking-widest transition-all disabled:opacity-40 disabled:cursor-not-allowed"
                                        :class="canalElegido === c.id
                                            ? 'bg-[#376875] text-white border-[#376875]'
                                            : 'bg-white text-slate-600 border-slate-200 hover:border-[#376875]'">
                                    {{ c.nombre }}
                                    <span v-if="!c.disponible" class="block text-[9px] font-bold normal-case tracking-normal opacity-70">
                                        {{ c.motivo === 'sin_datos_o_vetado' ? 'sin datos' : 'no aplica' }}
                                    </span>
                                </button>
                            </div>
                        </div>
                    </template>

                    <p v-if="errorEnvio" class="text-[11px] font-bold text-rose-600 leading-snug">
                        <i class="fas fa-triangle-exclamation mr-1"></i>{{ errorEnvio }}
                    </p>
                </div>

                <footer class="px-5 py-3 bg-slate-50 border-t border-slate-200 flex items-center justify-end gap-2 shrink-0">
                    <button @click="ordenAEnviar = null"
                            class="px-4 py-2 text-xs font-black uppercase tracking-widest text-slate-500 hover:text-slate-800">
                        Cancelar
                    </button>
                    <button @click="confirmarEnvio"
                            :disabled="enviandoOrden || !canalElegido || !documento || documento.lineas === 0"
                            class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-40 text-white text-xs font-black uppercase tracking-widest rounded-lg shadow-sm">
                        <i :class="enviandoOrden ? 'fas fa-spinner fa-spin' : 'fas fa-paper-plane'" class="mr-1"></i>
                        Enviar
                    </button>
                </footer>
            </div>
        </div>

        <!-- ================================================================
             MODAL: PAGOS A CUENTA AL PROVEEDOR

             Mobile-first: hoja inferior en móvil, tarjeta centrada en ancho. Saldo por moneda
             arriba, historial de pagos, y el alta abajo. La moneda del alta se limita a las de
             la orden — no se convierte, así que un pago sólo tiene sentido en una de ellas.
             ================================================================ -->
        <div v-if="pagosOrden" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-slate-900/50" @click.self="cerrarModal()">
            <div class="bg-white rounded-t-2xl sm:rounded-2xl shadow-xl w-full sm:max-w-md max-h-[88vh] flex flex-col overflow-hidden">
                <header class="bg-slate-900 text-white px-5 py-3 flex items-center gap-2 shrink-0">
                    <i class="fas fa-hand-holding-dollar text-emerald-400"></i>
                    <div class="min-w-0">
                        <h3 class="font-black text-sm tracking-tight leading-tight">Pagos al proveedor</h3>
                        <p class="text-[10px] text-slate-400 truncate">{{ pagosOrden.compradorNombre || pagosOrden.numeroOs }}</p>
                    </div>
                    <button @click="cerrarModal()" class="ml-auto text-slate-400 hover:text-white shrink-0">
                        <i class="fas fa-xmark"></i>
                    </button>
                </header>

                <div class="overflow-y-auto">
                    <!-- SALDO por moneda: negociado − pagado -->
                    <div class="px-4 py-3 bg-slate-50 border-b border-slate-100 flex flex-col gap-1">
                        <div v-for="t in (pagosOrden.totalesPorMoneda ?? [])" :key="t.moneda"
                             class="flex items-center justify-between gap-3 text-sm">
                            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">{{ t.moneda }}</span>
                            <div class="flex items-baseline gap-3 tabular-nums">
                                <span class="text-[10px] text-slate-400">neg. {{ importe(t.real) }}</span>
                                <span class="text-[10px] text-slate-400">pag. {{ importe(t.pagado ?? '0') }}</span>
                                <span class="font-black" :class="Number(t.saldo ?? 0) <= 0 ? 'text-emerald-600' : 'text-[#E07845]'">
                                    {{ Number(t.saldo ?? 0) <= 0 ? 'saldado' : importe(t.saldo) }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Historial de pagos -->
                    <div class="px-4 py-3">
                        <p v-if="cargandoPagos" class="text-xs text-slate-400 text-center py-4">
                            <i class="fas fa-spinner fa-spin mr-1"></i> Cargando…
                        </p>
                        <p v-else-if="!pagos.length" class="text-xs text-slate-400 text-center py-4">
                            Sin pagos registrados todavía.
                        </p>
                        <div v-else class="flex flex-col gap-2">
                            <div v-for="pago in pagos" :key="pago.id ?? ''"
                                 class="flex items-start justify-between gap-2 bg-slate-50 rounded-lg px-3 py-2">
                                <div class="min-w-0">
                                    <p class="text-sm font-black text-slate-800 tabular-nums">
                                        <span class="text-[10px] font-bold text-slate-400 mr-1">{{ pago.moneda?.id }}</span>{{ importe(pago.monto) }}
                                    </p>
                                    <p class="text-[10px] text-slate-400 flex items-center gap-1 flex-wrap">
                                        <span class="font-bold text-slate-500 flex items-center gap-1">
                                            <i class="fas text-[9px]" :class="iconoMedioPago(pago.medioPago)"></i>
                                            {{ pago.medioPagoLabel }}
                                        </span>
                                        · {{ (pago.fecha ?? '').slice(0, 10) }}<span v-if="pago.usuarioNombre"> · {{ pago.usuarioNombre }}</span>
                                    </p>
                                    <p v-if="pago.notas" class="text-[10px] text-slate-500 leading-snug mt-0.5">{{ pago.notas }}</p>
                                </div>
                                <button @click="borrarPago(pago)"
                                        class="shrink-0 w-7 h-7 rounded bg-white hover:bg-rose-500 hover:text-white text-slate-400 border border-slate-200 flex items-center justify-center transition-all"
                                        title="Eliminar pago">
                                    <i class="fas fa-trash-alt text-[10px]"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ALTA de pago -->
                <div class="px-4 py-3 border-t border-slate-200 bg-white shrink-0 flex flex-col gap-2">
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Registrar pago</p>
                    <div class="flex gap-2">
                        <select v-model="formPago.moneda"
                                class="text-xs font-black text-slate-600 bg-white border border-slate-200 rounded-lg px-2 py-2 outline-none focus:ring-2 focus:ring-emerald-500">
                            <option v-for="m in monedasDeOrden" :key="m" :value="m">{{ m }}</option>
                        </select>
                        <input v-model="formPago.monto" inputmode="decimal" placeholder="Monto"
                               class="flex-1 min-w-0 text-sm font-black text-slate-800 tabular-nums text-right bg-white border border-slate-200 rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-emerald-500" />
                        <input v-model="formPago.fecha" type="date"
                               class="text-xs font-bold text-slate-600 bg-white border border-slate-200 rounded-lg px-2 py-2 outline-none focus:ring-2 focus:ring-emerald-500" />
                    </div>
                    <!-- Sin opción preseleccionada: cómo se pagó es un hecho, y un valor por
                         defecto lo convierte en «lo que estaba puesto al abrir». -->
                    <select v-model="formPago.medioPago"
                            class="text-xs font-bold text-slate-600 bg-white border border-slate-200 rounded-lg px-2 py-2 outline-none focus:ring-2 focus:ring-emerald-500">
                        <option value="" disabled>¿Por qué medio se pagó?</option>
                        <option v-for="m in operacionStore.mediosPago" :key="m.id" :value="m.id">{{ m.label }}</option>
                    </select>
                    <input v-model="formPago.notas" placeholder="Observaciones (opcional): nº operación, banco…"
                           class="text-sm text-slate-700 bg-white border border-slate-200 rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-emerald-500" />
                    <p v-if="errorPago" class="text-[11px] font-bold text-rose-600">
                        <i class="fas fa-triangle-exclamation mr-1"></i>{{ errorPago }}
                    </p>
                    <button @click="registrarPago" :disabled="guardandoPago"
                            class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white rounded-lg text-xs font-black uppercase tracking-widest shadow-sm transition-colors">
                        <i v-if="guardandoPago" class="fas fa-spinner fa-spin mr-1"></i>
                        Registrar pago
                    </button>
                </div>
            </div>
        </div>

        <!-- ================================================================
             MODAL: HISTORIAL DE ESTADOS (bitácora)

             El «desde hace X» de la fila responde «¿cuánto lleva así?»; esto responde «¿por
             dónde pasó y quién lo movió?». Se abre desde el reloj bajo el estado.
             ================================================================ -->
        <div v-if="bitacoraServicio" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-slate-900/50" @click.self="cerrarModal()">
            <div class="bg-white rounded-t-2xl sm:rounded-2xl shadow-xl w-full sm:max-w-md max-h-[80vh] flex flex-col overflow-hidden">
                <header class="bg-slate-900 text-white px-5 py-3 flex items-center gap-2 shrink-0">
                    <i class="fas fa-clock-rotate-left text-[#E07845]"></i>
                    <div class="min-w-0">
                        <h3 class="font-black text-sm tracking-tight leading-tight">Historial de estados</h3>
                        <!-- De QUÉ es este historial. Con la tarifa sola («Adulto Extranjero») no
                             se distingue de otras cuatro filas del mismo día. -->
                        <p class="text-[10px] text-slate-400 truncate">
                            {{ nombreComponenteDe(bitacoraServicio) || getTipoComponenteConfig(bitacoraServicio.tipoComponente).label }}
                            · {{ bitacoraServicio.descripcionServicio }}
                        </p>
                    </div>
                    <button @click="cerrarModal()" class="ml-auto text-slate-400 hover:text-white shrink-0">
                        <i class="fas fa-xmark"></i>
                    </button>
                </header>

                <div class="p-4 overflow-y-auto">
                    <p v-if="cargandoBitacora" class="text-xs text-slate-400 text-center py-6">
                        <i class="fas fa-spinner fa-spin mr-1"></i> Cargando…
                    </p>
                    <p v-else-if="!bitacora.length" class="text-xs text-slate-400 text-center py-6">
                        Sin cambios registrados todavía. La historia se registra desde ahora.
                    </p>
                    <ol v-else class="relative border-l-2 border-slate-200 ml-2">
                        <li v-for="(b, i) in bitacora" :key="i" class="ml-4 pb-4 last:pb-0 relative">
                            <span class="absolute -left-[1.35rem] top-1 w-3 h-3 rounded-full border-2 border-white"
                                  :class="i === 0 ? 'bg-[#E07845]' : 'bg-slate-300'"></span>
                            <p class="text-[9px] font-black uppercase tracking-widest"
                               :class="b.campo === 'reserva' ? 'text-[#376875]' : 'text-slate-400'">
                                {{ b.campo === 'reserva' ? 'Reserva' : 'Operación' }}
                            </p>
                            <p class="text-sm font-bold text-slate-800 leading-snug">
                                <span v-if="b.valorAnterior" class="text-slate-400 font-medium">{{ etiquetaEstado(b.campo, b.valorAnterior) }} → </span>
                                {{ etiquetaEstado(b.campo, b.valorNuevo) }}
                            </p>
                            <p class="text-[10px] text-slate-400">
                                {{ fechaHora(b.createdAt) }}
                                <span v-if="b.usuarioNombre"> · {{ b.usuarioNombre }}</span>
                            </p>
                        </li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- ================================================================
             MODAL: EDITAR LA CABECERA DE UNA ORDEN

             Una orden se creaba y ya no se podía tocar: ni corregir el número, ni cambiar el
             destinatario, ni moverla de estado. Lo único a mano era la bitácora, que no edita
             nada. El importe NO se edita aquí — sale de los ítems (§5.4).
             ================================================================ -->
        <div v-if="ordenEditando" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden">
                <header class="bg-slate-900 text-white px-5 py-3 flex items-center gap-2">
                    <i class="fas fa-pen text-[#E07845]"></i>
                    <h3 class="font-black text-sm tracking-tight">Editar Orden de Servicio</h3>
                </header>

                <div class="p-5 flex flex-col gap-3">
                    <label class="flex flex-col gap-1">
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Número de OS</span>
                        <input
                            v-model="formEdicion.numeroOs"
                            class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm font-bold text-slate-700 outline-none focus:ring-2 focus:ring-[#376875]"
                        />
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">
                            Destinatario <span class="text-slate-300 normal-case font-bold">(a quién se le manda)</span>
                        </span>
                        <SearchableSelect
                            v-model="formEdicion.compradorMaestroId"
                            :options="opcionesProveedores"
                            placeholder="Buscar proveedor..."
                            @update:model-value="onDestinatarioEdicion"
                        />
                        <span v-if="!formEdicion.compradorMaestroId && formEdicion.compradorNombre"
                              class="text-[10px] font-bold text-amber-600 flex items-start gap-1">
                            <i class="fas fa-triangle-exclamation mt-0.5"></i>
                            <span>Va a nombre de <b>{{ formEdicion.compradorNombre }}</b>, que no está en el catálogo.</span>
                        </span>
                    </label>

                    <!-- ⚠️ El estado se VE aquí y se MUEVE desde los botones de la tarjeta.
                         Era un `<select>` y ese control no distingue entre corregir un dato y
                         mandarle un documento a un proveedor: emitir y anular salían del mismo
                         gesto con el que se arregla un número de OS, sin confirmación y a un
                         desliz de distancia. Cada acción tiene ahora su botón y su confirmación,
                         y el listado ofrece sólo las que caben desde el estado actual. -->
                    <div class="flex flex-col gap-1">
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Estado</span>
                        <div class="flex items-center gap-2 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
                            <span class="text-sm font-bold text-slate-700">{{ getEstadoOsConfig(formEdicion.estadoOs).label }}</span>
                            <span class="ml-auto text-[10px] text-slate-400">se cambia desde los botones de la orden</span>
                        </div>
                    </div>

                    <!-- El importe no se toca aquí a propósito: vive en cada servicio, con su
                         moneda. Decirlo evita que alguien lo busque y lo eche de menos. -->
                    <p class="text-[10px] text-slate-400 leading-snug bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
                        <i class="fas fa-circle-info mr-1"></i>
                        Los importes se ajustan servicio por servicio en La Biblia, en la columna de
                        costo real. Aquí no hay un total que editar.
                    </p>

                    <p v-if="errorEdicion" class="text-xs font-bold text-rose-600">
                        <i class="fas fa-triangle-exclamation mr-1"></i>{{ errorEdicion }}
                    </p>
                </div>

                <footer class="px-5 py-3 bg-slate-50 border-t border-slate-200 flex justify-end gap-2">
                    <button
                        @click="ordenEditando = null"
                        class="px-4 py-2 text-xs font-black uppercase tracking-widest text-slate-500 hover:text-slate-800"
                    >
                        Cancelar
                    </button>
                    <button
                        @click="guardarEdicion"
                        :disabled="guardandoEdicion || !formEdicion.numeroOs.trim()"
                        class="px-5 py-2 bg-[#E07845] hover:bg-[#c96636] disabled:opacity-50 text-white rounded-lg text-xs font-black uppercase tracking-widest shadow-sm transition-colors"
                    >
                        <i v-if="guardandoEdicion" class="fas fa-spinner fa-spin mr-1"></i>
                        Guardar
                    </button>
                </footer>
            </div>
        </div>

        <!-- ================================================================
             MODAL: GENERAR ORDEN DE SERVICIO
             ================================================================ -->
        <div v-if="mostrarModalOs" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden">
                <header class="bg-slate-900 text-white px-5 py-3 flex items-center gap-2">
                    <i class="fas fa-file-invoice text-[#E07845]"></i>
                    <h3 class="font-black text-sm tracking-tight">Generar Orden de Servicio</h3>
                </header>

                <div class="p-5 flex flex-col gap-3">
                    <p class="text-xs text-slate-500">
                        Se agruparán <strong class="text-slate-800">{{ seleccionados.length }}</strong> servicios
                        del expediente <strong class="text-slate-800">{{ serviciosSeleccionados[0]?.file?.nombreGrupo }}</strong>.
                    </p>

                    <!--
                      EL DESGLOSE. El total salía solo, sin decir de dónde, y con un número
                      que no cuadra con nada visible sólo queda confiar o rehacerlo a mano.
                      Cada línea es un COMPONENTE —que es la unidad de la orden— con lo que
                      aporta.
                    -->
                    <div class="rounded-lg border border-slate-200 bg-slate-50 divide-y divide-slate-200 max-h-40 overflow-y-auto">
                        <div v-for="s in serviciosSeleccionados" :key="s.id ?? ''"
                             class="flex items-start justify-between gap-2 px-3 py-2">
                            <div class="min-w-0">
                                <!-- EL COMPONENTE, primero y con su icono de tipo. Es lo único que
                                     dice QUÉ se compró: la tarifa («Adulto Extranjero», «Americana
                                     Royal Class») es demasiado genérica para saber si esto es un
                                     ticket o un guiado, y con ella sola no se puede revisar una
                                     orden antes de mandarla.

                                     El icono va SIEMPRE aunque el nombre no se haya resuelto: el
                                     tipo viaja denormalizado en el snapshot y ya responde la
                                     pregunta cara —ticket o guiado— sin depender del catálogo. -->
                                <p class="text-[11px] font-black text-slate-800 leading-snug flex items-center gap-1.5">
                                    <i :class="[getTipoComponenteConfig(s.tipoComponente).icon,
                                               getTipoComponenteConfig(s.tipoComponente).text, 'text-[10px] shrink-0']"
                                       :title="getTipoComponenteConfig(s.tipoComponente).label"></i>
                                    <span class="truncate">
                                        {{ nombreComponenteDe(s) || getTipoComponenteConfig(s.tipoComponente).label }}
                                    </span>
                                </p>
                                <p class="text-[11px] font-bold text-slate-500 leading-snug truncate">
                                    <i class="fas fa-tag text-[8px] mr-1 text-slate-300"></i>{{ s.descripcionServicio }}
                                </p>
                                <p v-if="s.contextoServicio" class="text-[10px] text-slate-400 leading-snug truncate">
                                    {{ s.contextoServicio }}
                                </p>
                                <p class="text-[10px] text-slate-400">
                                    {{ (s.fechaServicio ?? '').slice(0, 10) }} · {{ nochesTexto(s.cantidadComponente, s.tipoComponente) }}{{ s.cantidadPax }} pax
                                </p>
                            </div>
                            <span class="text-xs font-black text-slate-700 tabular-nums shrink-0">
                                <!-- La moneda va SIEMPRE pegada al número: «12.00» a secas no
                                     dice si son soles o dólares, y en una orden que ahora puede
                                     mezclarlas es la mitad del dato. -->
                                <span class="text-[10px] font-bold text-slate-400 mr-1">{{ s.monedaCotizada?.id || '?' }}</span>{{ importe(s.costoCotizado) }}
                            </span>
                        </div>
                    </div>

                    <label class="flex flex-col gap-1">
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Número de OS</span>
                        <input
                            v-model="formOs.numeroOs"
                            class="bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm font-bold text-slate-700 outline-none focus:ring-2 focus:ring-[#376875]"
                        />
                    </label>

                    <!--
                      Selector del catálogo, no texto libre. La OS existe para MANDARSE a
                      alguien: escrito a mano, «Gabrie Aime» y «Gabriel Aimé» son dos
                      proveedores distintos para cualquier filtro, y no hay a quién enviarla
                      porque detrás no queda un id, sólo una cadena.
                    -->
                    <label class="flex flex-col gap-1">
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">
                            Destinatario <span class="text-slate-300 normal-case font-bold">(a quién se le manda)</span>
                        </span>
                        <SearchableSelect
                            v-model="formOs.compradorMaestroId"
                            :options="opcionesProveedores"
                            placeholder="Buscar proveedor..."
                            @update:model-value="onDestinatarioChange"
                        />
                        <span v-if="!formOs.compradorMaestroId && formOs.compradorNombre"
                              class="text-[10px] font-bold text-amber-600 flex items-start gap-1 mt-0.5">
                            <i class="fas fa-triangle-exclamation mt-0.5"></i>
                            <span>
                                Estos servicios están a nombre de <b>{{ formOs.compradorNombre }}</b>, que no
                                está en el catálogo. Elige uno para poder enviarle la orden.
                            </span>
                        </span>
                    </label>

                    <!--
                      SIN campo de total. El importe no se fija aquí: vive en cada ítem con su
                      moneda —cotizado (referencial) y negociado (editable)—. Esto es sólo el
                      resumen de lo que dice el cotizador, POR MONEDA y sin convertir, para que
                      se vea de dónde sale antes de crear la orden.
                    -->
                    <div class="rounded-lg bg-slate-800 text-white px-3 py-2 flex flex-col gap-1">
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">
                            Referencial del cotizador
                        </span>
                        <div v-for="t in totalesPorMoneda" :key="t.moneda"
                             class="flex items-baseline justify-between gap-3">
                            <span class="text-[10px] font-bold text-slate-400">{{ t.moneda }}</span>
                            <span class="text-base font-black tabular-nums">{{ importe(t.total.toFixed(2)) }}</span>
                        </div>
                        <span class="text-[10px] text-slate-400 leading-snug mt-0.5">
                            Se ajusta servicio por servicio en el cuadro, en la columna de costo real.
                        </span>
                    </div>

                    <p v-if="errorOs" class="text-xs font-bold text-rose-600">
                        <i class="fas fa-triangle-exclamation mr-1"></i>{{ errorOs }}
                    </p>
                </div>

                <footer class="px-5 py-3 bg-slate-50 border-t border-slate-200 flex justify-end gap-2">
                    <button
                        @click="mostrarModalOs = false"
                        class="px-4 py-2 text-xs font-black uppercase tracking-widest text-slate-500 hover:text-slate-800"
                    >
                        Cancelar
                    </button>
                    <!-- Dos caminos, y el que sale al proveedor NO es el destacado.
                         Componer y mandar son decisiones distintas: se puede querer repasar el
                         destinatario o los importes antes de que el documento exista para
                         alguien de fuera. El borrador se puede emitir después desde su tarjeta. -->
                    <!-- «Crear» a secas y en verde: es la acción segura y la que se usa el 90 %
                         de las veces. Se llamaba «Crear borrador» y sonaba a paso intermedio que
                         hay que rematar; lo que crea es una orden, que además se puede emitir
                         después desde su tarjeta. -->
                    <button
                        @click="confirmarOs(false)"
                        :disabled="guardandoOs"
                        title="La crea. Se puede repasar y emitir después desde su tarjeta"
                        class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white text-xs font-black uppercase tracking-widest rounded-lg shadow-sm"
                    >
                        <i v-if="guardandoOs" class="fas fa-spinner fa-spin mr-1"></i>
                        Crear
                    </button>
                    <!-- ⚠️ Emitir PIDE CONFIRMACIÓN: congela el contenido y ya no vuelve a
                         borrador — para cambiar algo hay que anular y reemitir. Un botón de una
                         sola pulsación al lado del seguro es cómo se emite sin querer. -->
                    <button
                        @click="pedirConfirmacionEmitir"
                        :disabled="guardandoOs"
                        title="La crea y la emite: congela el contenido y ya no vuelve a borrador"
                        class="px-5 py-2 bg-[#E07845] hover:bg-[#c96636] disabled:opacity-50 text-white text-xs font-black uppercase tracking-widest rounded-lg shadow-sm"
                    >
                        <i v-if="guardandoOs" class="fas fa-spinner fa-spin mr-1"></i>
                        {{ confirmandoEmitir ? '¿Seguro? Toca otra vez' : 'Crear y emitir' }}
                    </button>
                </footer>
            </div>
        </div>

        <!-- ================================================================
             MODAL: BITÁCORA DE MENSAJES
             ================================================================ -->
        <div v-if="ordenActiva" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg overflow-hidden flex flex-col max-h-[80vh]">
                <header class="bg-slate-900 text-white px-5 py-3 flex items-center gap-2">
                    <i class="fas fa-message text-[#376875]"></i>
                    <h3 class="font-black text-sm tracking-tight">
                        Bitácora · {{ ordenActiva.numeroOs }}
                    </h3>
                    <button @click="ordenActiva = null" class="ml-auto text-slate-400 hover:text-white">
                        <i class="fas fa-xmark"></i>
                    </button>
                </header>

                <div class="flex-1 overflow-y-auto p-5 flex flex-col gap-3">
                    <p v-if="operacionStore.mensajesActivos.length === 0" class="text-xs text-slate-400 text-center py-6">
                        Todavía no se ha enviado nada a este proveedor.
                    </p>
                    <article
                        v-for="mensaje in operacionStore.mensajesActivos"
                        :key="mensaje.id ?? mensaje.createdAt"
                        class="border border-slate-200 rounded-xl p-3 bg-slate-50"
                    >
                        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">
                            {{ mensaje.tipo }} · {{ formatearFecha(mensaje.createdAt) }}
                        </p>
                        <!--
                          Interpolación y no `v-html`, aunque el campo se llame `cuerpoHtml`:
                          lo que se guarda es el texto plano del textarea (ver enviarMensaje),
                          y el `whitespace-pre-wrap` de al lado ya delataba la intención. Con
                          `v-html` un `<` tecleado por el operador rompía el marcado, y pegar
                          algo desde el correo podía meter etiquetas en la bitácora.
                        -->
                        <div class="text-sm text-slate-700 whitespace-pre-wrap">{{ mensaje.cuerpoHtml }}</div>
                    </article>
                </div>

                <footer class="px-5 py-3 bg-slate-50 border-t border-slate-200 flex flex-col gap-2">
                    <textarea
                        v-model="cuerpoMensaje"
                        rows="3"
                        placeholder="Escribe la solicitud al proveedor…"
                        class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-700 outline-none focus:ring-2 focus:ring-[#376875] resize-none"
                    ></textarea>
                    <div class="flex justify-end gap-2">
                        <button
                            @click="ordenActiva = null"
                            class="px-4 py-2 text-xs font-black uppercase tracking-widest text-slate-500 hover:text-slate-800"
                        >
                            Cerrar
                        </button>
                        <button
                            @click="enviarMensaje"
                            :disabled="enviandoMensaje || !cuerpoMensaje.trim()"
                            class="px-5 py-2 bg-[#376875] hover:bg-[#2d5660] disabled:opacity-40 text-white text-xs font-black uppercase tracking-widest rounded-lg shadow-sm"
                        >
                            <i v-if="enviandoMensaje" class="fas fa-spinner fa-spin mr-1"></i>
                            Registrar
                        </button>
                    </div>
                </footer>
            </div>
        </div>
    </div>

    <!-- ══ AGREGAR A UNA ORDEN EXISTENTE ═══════════════════════════════════════
         Lista sólo borradores compatibles. Cada fila enseña el NÚMERO y el
         COMPRADOR: sin el comprador delante, elegir entre «OS-014» y «OS-015» es
         adivinar, y equivocarse manda el encargo al proveedor que no era. -->
    <Transition name="fade-scale">
      <div v-if="mostrarModalAgregar" class="fixed inset-0 z-1400 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4"
           @click.self="mostrarModalAgregar = false">
        <div class="bg-white w-full max-w-lg rounded-2xl shadow-2xl overflow-hidden border border-slate-200">
          <div class="bg-[#376875] text-white px-5 py-4 flex justify-between items-center">
            <h3 class="font-black text-sm uppercase tracking-widest">
              <i class="fas fa-plus mr-2"></i>Agregar a una orden
            </h3>
            <button @click="mostrarModalAgregar = false" class="hover:opacity-70"><i class="fas fa-times"></i></button>
          </div>

          <div class="p-5 space-y-3">
            <p class="text-xs font-bold text-slate-500">
              Se agregarán <span class="text-[#376875]">{{ seleccionados.length }} servicio{{ seleccionados.length !== 1 ? 's' : '' }}</span>.
              Sólo aparecen las órdenes en borrador del mismo expediente y comprador.
            </p>

            <p v-if="errorAgregar" class="text-xs font-bold text-rose-600 bg-rose-50 border border-rose-200 rounded-lg px-3 py-2">
              <i class="fas fa-triangle-exclamation mr-1"></i>{{ errorAgregar }}
            </p>

            <div class="space-y-2 max-h-72 overflow-y-auto">
              <button
                v-for="o in ordenesParaAgregar" :key="o.id"
                @click="agregarAOrden(o.id)"
                :disabled="!!agregandoA"
                class="w-full text-left px-4 py-3 rounded-xl border-2 border-slate-200 hover:border-[#376875] hover:bg-[#376875]/5 disabled:opacity-40 transition-colors"
              >
                <div class="flex items-center justify-between gap-3">
                  <div class="min-w-0">
                    <p class="font-black text-sm text-slate-900">{{ o.numeroOs }}</p>
                    <p class="text-[11px] font-bold text-slate-500 truncate">
                      <i class="fas fa-handshake text-[9px] mr-1 text-slate-300"></i>{{ o.compradorNombre || 'Sin comprador' }}
                    </p>
                  </div>
                  <span class="shrink-0 text-[10px] font-black text-slate-400 uppercase tracking-widest">
                    <i v-if="agregandoA === o.id" class="fas fa-spinner fa-spin mr-1"></i>
                    {{ (o.operacionServicios?.length ?? 0) }} línea{{ (o.operacionServicios?.length ?? 0) !== 1 ? 's' : '' }}
                  </span>
                </div>
              </button>
            </div>
          </div>

          <div class="bg-slate-50 px-5 py-3 border-t border-slate-100 flex justify-end">
            <button @click="mostrarModalAgregar = false" class="px-4 py-2 text-xs font-bold text-slate-500 hover:text-slate-700">Cancelar</button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- ══ FICHA DEL SERVICIO: DETALLE, Y DENTRO EL FORMULARIO ═══════════════════
         Tocar una ficha de la rejilla abre esto LEYENDO; la plumita entra a editar. Recorrer
         cuarenta servicios es lo que más se hace en un día de tráfico, y en un formulario los
         datos están repartidos entre campos que hay que interpretar. Es el mismo reparto que el
         namelist del expediente, y por el mismo motivo.

         Las dos son CAPAS EN EL HISTORIAL (`?capa=servicio.servicio-edicion`): el gesto atrás
         devuelve del formulario al detalle, y del detalle a la rejilla. Nunca saca de la vista.

         A pantalla completa en el teléfono —en 360 px un modal centrado deja media pantalla de
         fondo inútil y el teclado tapa el resto— y como panel lateral a partir de `md`, para no
         perder de vista la rejilla que hay detrás. -->
    <Transition name="fade-scale">
      <div v-if="servicioFicha" class="fixed inset-0 z-1400 flex justify-end md:bg-slate-900/40"
           @click.self="cerrarFicha()">
        <div class="bg-white w-full md:max-w-xl flex flex-col md:shadow-2xl">
          <header class="bg-[#376875] text-white px-3 py-3 flex items-center gap-2 shrink-0">
            <!-- Atrás, no una ✕: la ficha es un sitio al que se ha entrado, y desde el
                 formulario este botón devuelve al detalle igual que el gesto del sistema. -->
            <button @click="modoVistaFicha ? cerrarFicha() : girarFicha(true)"
                    :title="modoVistaFicha ? 'Volver a la lista' : 'Volver al detalle'"
                    class="w-8 h-8 rounded-full bg-white/15 flex items-center justify-center shrink-0">
              <i class="fas fa-arrow-left text-sm"></i>
            </button>

            <div class="min-w-0 flex-1">
              <p class="font-black text-sm truncate flex items-center gap-1.5">
                <i :class="[getTipoComponenteConfig(servicioFicha.tipoComponente).icon, 'text-xs opacity-80']"></i>
                {{ nombreComponenteDe(servicioFicha) || servicioFicha.contextoServicio || getTipoComponenteConfig(servicioFicha.tipoComponente).label }}
              </p>
              <p class="text-[10px] font-bold text-white/70 truncate">{{ servicioFicha.descripcionServicio }}</p>
            </div>

            <!-- Saltar de una ficha a otra sin volver a la rejilla. NO apila capas: es la misma
                 capa cambiando de contenido. Sólo en lectura — con el formulario abierto
                 saltarían los cambios sin guardar. -->
            <div v-if="modoVistaFicha" class="flex items-center gap-1 shrink-0">
              <button @click="irAFichaAdyacente(-1)" :disabled="indiceDeFicha <= 0"
                      title="Servicio anterior"
                      class="w-8 h-8 rounded-full bg-white/15 hover:bg-white/25 disabled:opacity-30 flex items-center justify-center">
                <i class="fas fa-chevron-up text-xs"></i>
              </button>
              <button @click="irAFichaAdyacente(1)" :disabled="indiceDeFicha < 0 || indiceDeFicha >= serviciosPlanos.length - 1"
                      title="Servicio siguiente"
                      class="w-8 h-8 rounded-full bg-white/15 hover:bg-white/25 disabled:opacity-30 flex items-center justify-center">
                <i class="fas fa-chevron-down text-xs"></i>
              </button>
              <button @click="girarFicha(false)" title="Editar"
                      class="w-8 h-8 rounded-full bg-[#E07845] hover:bg-[#c96636] flex items-center justify-center">
                <i class="fas fa-pencil-alt text-xs"></i>
              </button>
            </div>
          </header>

          <!-- ══ DETALLE (lectura) ══════════════════════════════════════════════ -->
          <div v-if="modoVistaFicha" class="flex-1 overflow-y-auto p-4 space-y-4">
            <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 space-y-1.5">
              <p class="text-[11px] font-bold text-slate-500">
                <i class="far fa-calendar w-4 text-slate-400"></i>
                {{ servicioFicha.fechaServicio ? etiquetaDia(servicioFicha.fechaServicio.slice(0, 10)) : 'Sin fecha' }}
                <span v-if="servicioFicha.horaRecojo" class="ml-1 tabular-nums font-black text-slate-700">· {{ servicioFicha.horaRecojo }}</span>
                <span v-else-if="admiteHora(servicioFicha) && servicioFicha.horaComponente" class="ml-1 tabular-nums">· vendida {{ servicioFicha.horaComponente }}</span>
                <span v-else-if="!admiteHora(servicioFicha)" class="ml-1 text-slate-400">· sin hora</span>
              </p>
              <p class="text-[11px] font-bold text-slate-500 truncate">
                <i class="fas fa-folder w-4 text-slate-400"></i>
                <button v-if="servicioFicha.file?.id" @click="abrirExpediente(servicioFicha)"
                        class="text-[#376875] hover:underline decoration-dotted">
                  {{ servicioFicha.file?.nombreGrupo || '—' }}
                </button>
                <span v-else>—</span>
                <span class="ml-1 text-slate-400">· {{ servicioFicha.cantidadPax }} pax</span>
              </p>
              <p v-if="nombreSegmentoDe(servicioFicha) || servicioFicha.contextoServicio"
                 class="text-[11px] font-bold text-slate-500 truncate">
                <i class="fas fa-map-signs w-4 text-slate-400"></i>{{ nombreSegmentoDe(servicioFicha) || servicioFicha.contextoServicio }}
              </p>
              <p v-if="servicioFicha.tarifaNombre && servicioFicha.tarifaNombre !== servicioFicha.descripcionServicio"
                 class="text-[11px] font-bold text-slate-400 truncate" title="Nombre interno de la tarifa">
                <i class="fas fa-tag w-4 text-slate-300"></i>{{ servicioFicha.tarifaNombre }}
              </p>
            </div>

            <!-- Con quién se opera. El teléfono se marca desde aquí: es el dato del recojo. -->
            <div class="space-y-1.5">
              <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Prestador</p>
              <p class="text-sm font-black text-slate-800">
                {{ servicioFicha.prestadorEfectivoNombre || (servicioFicha.soloReferencia ? 'Referencia' : 'Por asignar') }}
              </p>
              <p v-if="servicioFicha.prestadorServicioEfectivoNombre" class="text-[11px] font-bold text-slate-500">
                {{ servicioFicha.prestadorServicioEfectivoNombre }}
              </p>
              <p v-if="cotizadoDe(servicioFicha)" class="text-[10px] font-medium text-slate-400 italic">
                {{ cotizadoDe(servicioFicha) }}
              </p>
              <a v-if="telefonoDe(servicioFicha)" :href="telHref(telefonoDe(servicioFicha))"
                 class="flex items-center gap-1.5 text-[11px] font-bold text-slate-500 hover:text-[#376875]">
                <i class="fas fa-phone text-slate-300 text-[10px]"></i>
                <span class="tabular-nums">{{ telefonoDe(servicioFicha) }}</span>
              </a>
              <p v-if="direccionDe(servicioFicha)" class="flex items-start gap-1.5 text-[11px] font-medium text-slate-400">
                <i class="fas fa-location-dot text-slate-300 text-[10px] mt-0.5 shrink-0"></i>
                <span>{{ direccionDe(servicioFicha) }}</span>
              </p>
              <p v-if="mostrarComprador(servicioFicha)" class="text-[11px] font-bold text-slate-500">
                <span class="text-[9px] font-black text-slate-300 uppercase tracking-wider mr-1">Compra</span>
                {{ servicioFicha.compradorEfectivoNombre || 'Sin definir' }}
              </p>
            </div>

            <!-- La ruta efectiva: override incluido y hotel resuelto. En ámbar si falta algo. -->
            <div v-if="rutaDe(servicioFicha)" class="space-y-1">
              <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Ruta</p>
              <p class="text-[11px] font-bold leading-snug flex items-start gap-1.5"
                 :class="puntosDe(servicioFicha)?.efectivo?.completo ? 'text-slate-600' : 'text-amber-700'">
                <i class="fas fa-route text-[10px] mt-0.5 shrink-0"
                   :class="puntosDe(servicioFicha)?.efectivo?.completo ? 'text-slate-300' : 'text-amber-500'"></i>
                <span>{{ rutaDe(servicioFicha) }}</span>
              </p>
              <p v-for="aviso in (puntosDe(servicioFicha)?.avisos || [])" :key="aviso"
                 class="text-[10px] font-bold text-amber-700 leading-tight pl-5">
                <i class="fas fa-triangle-exclamation mr-1"></i>{{ aviso }}
              </p>
            </div>

            <div v-if="(servicioFicha.notasPrestadorEfectivas ?? []).length" class="space-y-1">
              <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Qué se le dice al proveedor</p>
              <p v-for="(nota, i) in (servicioFicha.notasPrestadorEfectivas ?? [])" :key="i"
                 class="text-[11px] font-medium text-slate-600 leading-snug flex items-start gap-1.5">
                <i class="fas fa-comment-dots text-[10px] text-slate-300 mt-0.5 shrink-0"></i>
                <span>{{ nota }}</span>
              </p>
            </div>

            <div v-if="!servicioFicha.soloReferencia" class="bg-slate-50 border border-slate-200 rounded-xl p-3">
              <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Costo</p>
              <div class="flex items-baseline justify-between">
                <span class="text-[11px] font-bold text-slate-400">Cotizado</span>
                <span class="text-[11px] font-bold text-slate-500 tabular-nums">
                  {{ servicioFicha.monedaCotizada?.id || '' }} {{ importe(servicioFicha.costoCotizado) }}
                </span>
              </div>
              <div class="flex items-baseline justify-between mt-1">
                <span class="text-[11px] font-bold text-slate-400">Negociado</span>
                <span class="text-sm font-black tabular-nums" :class="servicioFicha.costoNegociado ? 'text-slate-800' : 'text-slate-300'">
                  {{ servicioFicha.monedaNegociada?.id || '' }} {{ importe(servicioFicha.costoNegociado) }}
                </span>
              </div>
              <p v-if="deltaOperativo(servicioFicha) !== null && deltaOperativo(servicioFicha) !== 0"
                 class="text-right text-[11px] font-black tabular-nums mt-0.5"
                 :class="deltaOperativo(servicioFicha)! > 0 ? 'text-rose-600' : 'text-emerald-600'">
                {{ deltaOperativo(servicioFicha)! > 0 ? '+' : '−' }}{{ Math.abs(deltaOperativo(servicioFicha)!).toFixed(2) }}
              </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
              <span class="text-[10px] font-black uppercase tracking-wider px-2 py-1 rounded border"
                    :class="[getEstadoReservaProveedorConfig(servicioFicha.estadoReservaProveedor).bg,
                             getEstadoReservaProveedorConfig(servicioFicha.estadoReservaProveedor).text,
                             getEstadoReservaProveedorConfig(servicioFicha.estadoReservaProveedor).border]">
                {{ getEstadoReservaProveedorConfig(servicioFicha.estadoReservaProveedor).label }}
              </span>
              <span class="text-[10px] font-black uppercase tracking-wider px-2 py-1 rounded border"
                    :class="[getEstadoOperacionConfig(servicioFicha.estadoOperacion).bg,
                             getEstadoOperacionConfig(servicioFicha.estadoOperacion).text,
                             getEstadoOperacionConfig(servicioFicha.estadoOperacion).border]">
                {{ getEstadoOperacionConfig(servicioFicha.estadoOperacion).label }}
              </span>
              <button v-if="servicioFicha.estadoReservaProveedorDesde" @click="abrirBitacora(servicioFicha)"
                      class="flex items-center gap-1 text-[10px] font-bold text-slate-400 hover:text-[#376875]"
                      title="Ver el historial de estados">
                <i class="fas fa-clock-rotate-left text-[9px]"></i>{{ desdeHace(servicioFicha.estadoReservaProveedorDesde) }}
              </button>
            </div>
          </div>

          <!-- ══ FORMULARIO (edición) ═══════════════════════════════════════════ -->
          <div v-else class="flex-1 overflow-y-auto p-4 space-y-5">
            <!-- ⚠️ Sólo si el componente ADMITE hora. Un ticket de horario variable —el ingreso
                 al Koricancha— no tiene hora que fijar: el campo ni siquiera aparece. Lo decide
                 `admiteHora()`, que consulta el flag del componente y no una copia de la regla. -->
            <div v-if="admiteHora(servicioFicha)">
              <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5">Hora de recojo</label>
              <input
                v-model="borradorFicha.horaRecojo"
                :placeholder="servicioFicha.horaComponente || '--:--'"
                maxlength="5" inputmode="numeric"
                class="w-full text-sm font-black bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-200 tabular-nums outline-none focus:ring-2 focus:ring-[#376875] focus:bg-white placeholder:text-slate-400 placeholder:font-bold"
              />
              <p class="text-[10px] font-bold text-slate-400 mt-1">Vacío = se usa la hora con la que se vendió.</p>
            </div>

            <!-- Quién opera y a quién se le compra. NO es texto libre: son organizaciones del
                 catálogo, y un nombre que no esté dado de alta corta el guardado con su motivo.
                 En la tabla el campo se revertía en silencio y nadie sabía por qué. -->
            <div>
              <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5">Prestador</label>
              <input
                v-model="borradorFicha.prestadorNombre"
                list="catalogo-organizaciones"
                :placeholder="servicioFicha.soloReferencia ? 'Referencia' : 'Por asignar'"
                class="w-full text-sm font-bold bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-200 outline-none focus:ring-2 focus:ring-[#376875] focus:bg-white placeholder:text-slate-400"
              />
              <p class="text-[10px] font-bold text-slate-400 mt-1">Vacío = vuelve a lo que dijo la cotización.</p>
            </div>

            <div>
              <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5">Servicio contratado</label>
              <input
                v-model="borradorFicha.prestadorServicioNombre"
                placeholder="El tipo de habitación, el tramo…"
                class="w-full text-xs font-bold bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-200 outline-none focus:ring-2 focus:ring-[#376875] focus:bg-white placeholder:text-slate-400"
              />
            </div>

            <div v-if="mostrarComprador(servicioFicha)">
              <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5">
                A quién se le compra
              </label>
              <input
                v-model="borradorFicha.compradorNombre"
                list="catalogo-organizaciones"
                placeholder="Sin definir"
                class="w-full text-sm font-bold bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-200 outline-none focus:ring-2 focus:ring-[#376875] focus:bg-white placeholder:text-slate-400"
              />
              <p class="text-[10px] font-bold text-slate-400 mt-1">Es por quien agrupa la Orden de Servicio.</p>
            </div>

            <div>
              <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5">Dónde se recoge</label>
              <input
                v-model="borradorFicha.puntoRecojo"
                :placeholder="puntosDe(servicioFicha)?.recojo || 'sin declarar en el catálogo'"
                maxlength="255"
                class="w-full text-xs font-bold bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-200 outline-none focus:ring-2 focus:ring-[#376875] focus:bg-white placeholder:text-slate-500 placeholder:italic"
              />
            </div>

            <div v-if="puntosDe(servicioFicha)?.tieneEntrega || servicioFicha.puntoEntrega">
              <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5">Dónde se deja</label>
              <input
                v-model="borradorFicha.puntoEntrega"
                :placeholder="puntosDe(servicioFicha)?.entrega || 'sin declarar en el catálogo'"
                maxlength="255"
                class="w-full text-xs font-bold bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-200 outline-none focus:ring-2 focus:ring-[#376875] focus:bg-white placeholder:text-slate-500 placeholder:italic"
              />
            </div>

            <p class="text-[10px] font-bold text-slate-400 -mt-3">Vacío = lo que diga el catálogo.</p>

            <div>
              <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Qué se le dice al proveedor</label>
              <textarea
                v-model="borradorFicha.notasPrestador"
                :placeholder="(servicioFicha.notasPrestadorEfectivas ?? []).join('\n') || 'nada que indicarle'"
                rows="3"
                class="w-full mt-1 text-xs font-bold bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-200 outline-none focus:ring-2 focus:ring-[#376875] focus:bg-white placeholder:text-slate-500 placeholder:italic resize-none"
              ></textarea>
              <p class="text-[10px] font-bold text-slate-400 mt-1">Una por línea. Vacío = los detalles del componente.</p>
            </div>

            <!-- Qué se le imprime al proveedor, por lado. `Auto` deja mandar la regla de cadenas:
                 varios servicios seguidos del mismo proveedor dicen sólo dónde empieza y dónde
                 acaba, porque lo de en medio es logística suya. -->
            <div class="bg-slate-50 border border-slate-200 rounded-xl px-3 py-2.5 space-y-2">
              <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Qué verá el proveedor</p>
              <!-- ⚠️ Esto es la SEMILLA, no la decisión final. Se copia a la orden al emitirla, y a
                   partir de ahí manda la orden. Con una orden ya emitida se ajusta desde su tarjeta
                   —ahí sí se ve la cadena entera y si el «auto» acaba mostrando u ocultando—, que es
                   el contexto que aquí no existe: una fila suelta no sabe con quién hará cadena. -->
              <p class="text-[10px] font-bold text-slate-400 leading-snug">
                  Se copia a la orden al emitirla. <b class="text-slate-500">Automático</b> deja mandar la regla:
                  varios servicios seguidos del mismo proveedor dicen sólo dónde empieza y dónde acaba.
                  En una orden ya emitida esto se ajusta desde su tarjeta, donde se ve la cadena completa.
              </p>
              <div class="flex items-center gap-2">
                <span class="text-[11px] font-bold text-slate-500 w-16 shrink-0">Recojo</span>
                <select v-model="borradorFicha.visibilidadRecojo"
                        class="flex-1 text-[11px] font-bold bg-white px-2 py-1.5 rounded-lg border border-slate-200 outline-none focus:ring-2 focus:ring-[#376875]">
                  <option value="auto">Automático</option>
                  <option value="siempre">Mostrar siempre</option>
                  <option value="oculto">Ocultar al proveedor</option>
                </select>
              </div>
              <div class="flex items-center gap-2">
                <span class="text-[11px] font-bold text-slate-500 w-16 shrink-0">Entrega</span>
                <select v-model="borradorFicha.visibilidadEntrega"
                        class="flex-1 text-[11px] font-bold bg-white px-2 py-1.5 rounded-lg border border-slate-200 outline-none focus:ring-2 focus:ring-[#376875]">
                  <option value="auto">Automático</option>
                  <option value="siempre">Mostrar siempre</option>
                  <option value="oculto">Ocultar al proveedor</option>
                </select>
              </div>
            </div>

            <div>
              <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5">Reserva con el proveedor</label>
              <select v-model="borradorFicha.estadoReservaProveedor"
                      class="w-full text-xs font-black px-3 py-2.5 rounded-xl border outline-none focus:ring-2 focus:ring-[#376875]"
                      :class="[getEstadoReservaProveedorConfig(borradorFicha.estadoReservaProveedor).bg,
                               getEstadoReservaProveedorConfig(borradorFicha.estadoReservaProveedor).text,
                               getEstadoReservaProveedorConfig(borradorFicha.estadoReservaProveedor).border]">
                <option v-for="(cfg, k) in ESTADO_RESERVA_PROVEEDOR_CONFIG" :key="k" :value="k">{{ cfg.label }}</option>
              </select>
            </div>

            <div>
              <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5">Operación</label>
              <select v-model="borradorFicha.estadoOperacion"
                      class="w-full text-xs font-black px-3 py-2.5 rounded-xl border outline-none focus:ring-2 focus:ring-[#376875]"
                      :class="[getEstadoOperacionConfig(borradorFicha.estadoOperacion).bg,
                               getEstadoOperacionConfig(borradorFicha.estadoOperacion).text,
                               getEstadoOperacionConfig(borradorFicha.estadoOperacion).border]">
                <option v-for="(cfg, k) in ESTADO_OPERACION_CONFIG" :key="k" :value="k">{{ cfg.label }}</option>
              </select>
            </div>

            <!-- El costo negociado trae su propio commit, así que se empotra tal cual en vez de
                 duplicarlo en el borrador: dos formas de guardar el mismo importe acabarían
                 discrepando. Guarda al confirmarlo, sin esperar al botón de abajo. Una fila de
                 referencia no se compra y no lo enseña. -->
            <div v-if="!servicioFicha.soloReferencia">
              <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5">Costo negociado</label>
              <EditorCostoNegociado
                :ref="(el) => registrarEditor(idDe(servicioFicha!), el)"
                :costo-cotizado="servicioFicha.costoCotizado"
                :desglose="servicioFicha.desgloseCotizado"
                :moneda-cotizada="servicioFicha.monedaCotizada?.id ?? ''"
                :costo-negociado="servicioFicha.costoNegociado"
                :moneda-negociada="servicioFicha.monedaNegociada?.id ?? null"
                :monedas="operacionStore.monedas"
                @guardar="(pl) => onGuardarCosto(servicioFicha!, pl)"
              />
              <p class="text-[10px] font-bold text-slate-400 mt-1">Se guarda al confirmarlo, aparte del resto.</p>
            </div>

            <p v-if="errorFicha" class="text-xs font-bold text-rose-600 bg-rose-50 border border-rose-200 rounded-xl px-3 py-2">
              <i class="fas fa-triangle-exclamation mr-1"></i>{{ errorFicha }}
            </p>
          </div>

          <!-- Abajo y fijos: es donde llega el pulgar y donde no los tapa el teclado. Sólo en
               edición — en lectura no hay nada que confirmar. -->
          <footer v-if="!modoVistaFicha" class="shrink-0 border-t border-slate-200 bg-white px-4 py-3 flex items-center gap-3">
            <button @click="girarFicha(true)" :disabled="guardandoFicha"
                    class="px-4 py-3 text-xs font-black uppercase tracking-widest text-slate-500 disabled:opacity-40">
              Cancelar
            </button>
            <button @click="guardarFicha" :disabled="guardandoFicha || !hayCambiosEnFicha"
                    class="flex-1 px-4 py-3 bg-[#E07845] hover:bg-[#c96636] disabled:opacity-40 text-white rounded-xl text-xs font-black uppercase tracking-widest shadow-sm">
              <i v-if="guardandoFicha" class="fas fa-spinner fa-spin mr-1"></i>
              {{ hayCambiosEnFicha ? 'Guardar cambios' : 'Sin cambios' }}
            </button>
          </footer>
        </div>
      </div>
    </Transition>
</template>
