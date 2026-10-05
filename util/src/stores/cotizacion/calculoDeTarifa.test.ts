import { describe, it, expect, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useCotizacionEditorStore } from './cotizacionEditorStore';
import { expurgarParaCliente, etiquetaGrupoTarifa, esEstandarVisible } from '@/types/cotizacionEditorModel';

/**
 * Cómo reparte el clasificador el dinero de cada **modalidad de cálculo**.
 *
 * ── Por qué este test, y por qué ahora ──────────────────────────────────────
 * Mismo criterio que `itinerarioDinamico.test.ts`: se fija el comportamiento **antes** de mover la
 * regla. Lo que la fase 4 de `docs/PlanModalidadDeTarifa.md` cambia es justo esto, y sin el test
 * sería un cambio a ciegas sobre el cálculo del dinero.
 *
 * ── Las dos preguntas que el booleano respondía a la vez ────────────────────
 * ```
 *                 ¿multiplica por cantidad?   ¿se reparte entre todos?
 * individual              sí                         no
 * grupal                  no (× 1)                   sí
 * operativa               sí                         sí      ← la que no cabía
 * ```
 * `individual` y `grupal` tienen que salir **idénticas a como salían**: son el 100 % de los datos
 * de hoy. `operativa` es la que estrena comportamiento.
 *
 * ── Por qué a través de `resumenFinanciero` ─────────────────────────────────
 * Es la única puerta: el reparto vive dentro de ese `computed` y no se exporta. Probarlo por fuera
 * obligaría a duplicar la regla en el test, que es la forma más fácil de que el test confirme lo
 * que el test cree en vez de lo que el código hace.
 */

/** Una tarifa con lo justo que el clasificador lee. */
const tarifa = (monto: number, cantidad: number, extra: Record<string, unknown> = {}) => ({
    id: `t-${monto}-${cantidad}-${JSON.stringify(extra)}`,
    montoCosto: String(monto),
    moneda: 'USD',
    cantidad,
    rolSnapshot: 'estandar',
    grupoTarifa: 1,
    tituloSnapshot: [],
    nombreInternoSnapshot: 'Tarifa',
    procedenciaSnapshot: null,
    edadMinimaSnapshot: null,
    edadMaximaSnapshot: null,
    modalidadSnapshot: null,
    categoriaSnapshot: null,
    comisionOverrideSnapshot: null,
    ...extra,
});

/** Una cotización de un servicio y un componente, con las tarifas que se le pasen. */
const cotizacionCon = (numPax: number, tarifas: ReturnType<typeof tarifa>[], modo = 'incluido') => ({
    id: 'cot-1',
    numPax,
    comision: '0',
    adelanto: '0',
    tipoCambio: '1',
    idiomaEdicion: 'es',
    cotservicios: [{
        id: 's-1',
        orden: 1,
        fechaInicioAbsoluta: '2026-10-01',
        tituloSnapshot: [{ language: 'es', content: 'Día 1' }],
        nombreInternoSnapshot: [],
        cotsegmentos: [],
        cotcomponentes: [{
            id: 'c-1',
            modo,
            estado: 'confirmado',
            cantidad: 1,
            tituloSnapshot: [{ language: 'es', content: 'Vuelo' }],
            nombreInternoSnapshot: 'Vuelo',
            snapshotItems: [],
            cottarifas: tarifas,
        }],
    }],
});

/**
 * Lo que acaba pagando **cada clase de pasajero**, en dólares.
 *
 * ⚠️ **No se mide el total del bucket, y la diferencia importa.** El total es la suma de los
 * `costoTotal` de cada línea y ya multiplica bien por cantidad — un test sobre él pasa igual
 * antes y después del cambio, o sea no prueba nada. Lo que la fase 4 mueve es **a cuántos
 * pasajeros se le reparte cada línea**, y eso sólo se ve en el detalle por clase.
 */
const costoPorClase = (numPax: number, tarifas: ReturnType<typeof tarifa>[]): number[] => {
    const store = useCotizacionEditorStore();
    // @ts-expect-error — fixture mínimo: sólo lleva lo que el clasificador lee.
    store.cotizacion = cotizacionCon(numPax, tarifas);

    const fin = store.resumenFinanciero;
    expect(fin, 'el clasificador devolvió null: el fixture no le basta').not.toBeNull();

    return fin!.clasesPasajeros.map((c) => Number(c.resumen.montoDolares.toFixed(4)));
};

/** Cuántos pasajeros quedan SIN cubrir, que es el otro efecto del reparto. */
const avisosDeCobertura = (numPax: number, tarifas: ReturnType<typeof tarifa>[]): string[] => {
    const store = useCotizacionEditorStore();
    // @ts-expect-error — fixture mínimo.
    store.cotizacion = cotizacionCon(numPax, tarifas);

    return (store.resumenFinanciero?.advertencias ?? []).filter((a) => a.includes('no cubre'));
};

describe('cómo reparte el clasificador cada modalidad', () => {
    beforeEach(() => setActivePinia(createPinia()));

    it('individual: su cantidad son PASAJEROS y se le asigna a ellos', () => {
        // 4 pax, una tarifa de 100 que cubre a los 4: una sola clase, 400 en total.
        expect(costoPorClase(4, [tarifa(100, 4)])).toEqual([400]);
        expect(avisosDeCobertura(4, [tarifa(100, 4)])).toEqual([]);
    });

    it('grupal: precio cerrado, repartido entre todos', () => {
        // 400 para 4 pax. Si multiplicara por cantidad saldrían 1600 — el fallo de agosto.
        //
        // ⚠️ Esta línea decía `{ esGrupal: true }` con `cantidad = 1`, y pasaba sin probar nada:
        // la clave murió en la fase 6b, así que el clasificador leía `calculoSnapshot` ausente y
        // caía en `individual` — que con cantidad 1 da los mismos 400. El test verde seguía
        // llamándose «grupal». Va con la clave viva y con `cantidad = 4`, que es lo único que
        // distingue las dos modalidades: grupal 400, individual 1600.
        expect(costoPorClase(4, [tarifa(400, 4, { calculoSnapshot: 'grupal' })])).toEqual([400]);
    });

    it('operativa: su cantidad son UNIDADES, no pasajeros', () => {
        // 🔑 El caso real: 5 vuelos liberados a 80 en un grupo de 10 que ya tiene su vuelo.
        //
        // ⚠️ Antes de la fase 4 esto producía DOS clases, y la segunda era «⚠️ CONFLICTO» con
        // los 400 dentro: los 5 se leían como cinco pasajeros más que nadie cubre, y eso
        // **bloqueaba publicar la cotización**. No era una cifra mal redondeada: era no poder
        // vender el viaje por haber cotizado bien los liberados.
        //
        // Ahora la línea se reparte entre todos, como la grupal, pero habiendo multiplicado por
        // cantidad: 80 × 5 = 400 sobre los 10 pax, encima de sus 10 000.
        const clases = costoPorClase(10, [
            tarifa(1000, 10),
            tarifa(80, 5, { calculoSnapshot: 'operativa', grupoTarifa: null }),
        ]);

        expect(clases).toHaveLength(1);
        expect(clases).toEqual([10400]);
    });

    it('una operativa NUNCA se publica al cliente', () => {
        // 🔥 Lo más peligroso de la fase 5. El filtro del expurgador miraba `rol === 'operativo'`,
        // y «operativo» dejó de ser un rol: si se hubiera quedado mirando el rol, el guía, su
        // viático y los liberados habrían **empezado a aparecer en la propuesta del huésped** sin
        // que nada fallara ni nadie lo notara.
        const store = useCotizacionEditorStore();
        // @ts-expect-error — fixture mínimo.
        store.cotizacion = cotizacionCon(10, [
            tarifa(1000, 10),
            tarifa(80, 5, { calculoSnapshot: 'operativa', rolSnapshot: 'estandar', grupoTarifa: null }),
        ]);

        const paraElCliente = expurgarParaCliente(store.resumenFinanciero!);
        const lineas = paraElCliente.clasesPasajeros.flatMap((c) => c.detalle);

        expect(lineas).toHaveLength(1);
        expect(lineas.every((l) => l.cantidad !== 5)).toBe(true);
    });

    it('una operativa NO deja pasajeros «sin cubrir»', () => {
        // El otro efecto del reparto: no viaja nadie en esa línea, son cinco vuelos.
        expect(avisosDeCobertura(10, [
            tarifa(1000, 10),
            tarifa(80, 5, { calculoSnapshot: 'operativa', grupoTarifa: null }),
        ])).toEqual([]);
    });
});

/**
 * Quién puede cambiar de modalidad, y a dónde se vuelve.
 *
 * ⚠️ Lo que se protege es la VUELTA. Marcar una operativa siempre se pudo; lo que faltaba era
 * volver sin estropear nada: una tarifa **grupal del catálogo** devuelta a «individual» pasaría de
 * `× 1` a `× cantidad` en silencio. Hoy no mordería —las 277 grupales del maestro tienen
 * `cantidad = 1`— pero es la misma trampa de poner sin poder quitar bien.
 */
describe('qué modalidades admite una tarifa', () => {
    beforeEach(() => setActivePinia(createPinia()));

    /** Registra una tarifa maestra y devuelve el snapshot enlazado a ella. */
    const conMaestro = (calculoMaestro: string) => {
        const store = useCotizacionEditorStore();
        // @ts-expect-error — fixture mínimo del catálogo.
        store.todasLasTarifasMaestras = [{ tarifaId: 'm-1', calculo: calculoMaestro }];

        return { store, t: tarifa(100, 4, { tarifaMaestraId: 'm-1' }) };
    };

    it('una tarifa suelta admite las tres', () => {
        const store = useCotizacionEditorStore();

        // @ts-expect-error — fixture mínimo.
        expect(store.modalidadesDisponibles(tarifa(100, 4))).toEqual(['individual', 'grupal', 'operativa']);
    });

    it('una del catálogo admite la suya y operativa, no la otra', () => {
        const { store, t } = conMaestro('grupal');

        // @ts-expect-error — fixture mínimo.
        expect(store.modalidadesDisponibles(t)).toEqual(['grupal', 'operativa']);
    });

    it('si el maestro ES operativa, queda fija', () => {
        // No hay a dónde volver: la tarifa es operativa por definición del catálogo.
        const { store, t } = conMaestro('operativa');

        // @ts-expect-error — fixture mínimo.
        expect(store.modalidadesDisponibles(t)).toEqual([]);
    });

    it('🔑 salir de operativa devuelve a la modalidad DEL CATÁLOGO, no a individual', () => {
        const { store, t } = conMaestro('grupal');
        // @ts-expect-error — fixture mínimo.
        store.cotizacion = cotizacionCon(4, [t]);

        // `cotcomponentes` es opcional en el tipo; el fixture siempre lo trae.
        const laTarifa = () => store.cotizacion!.cotservicios[0]!.cotcomponentes![0]!.cottarifas[0]!;

        store.cambiarModalidadTarifa(laTarifa().id, 'operativa');
        expect(laTarifa().calculoSnapshot).toBe('operativa');

        // Aunque se pida «individual», vuelve a grupal: lo dice el catálogo.
        store.cambiarModalidadTarifa(laTarifa().id, 'individual');
        expect(laTarifa().calculoSnapshot).toBe('grupal');
    });

    it('una suelta sí vuelve a lo que se le pida', () => {
        const store = useCotizacionEditorStore();
        // @ts-expect-error — fixture mínimo.
        store.cotizacion = cotizacionCon(4, [tarifa(100, 4)]);

        // `cotcomponentes` es opcional en el tipo; el fixture siempre lo trae.
        const laTarifa = () => store.cotizacion!.cotservicios[0]!.cotcomponentes![0]!.cottarifas[0]!;

        store.cambiarModalidadTarifa(laTarifa().id, 'operativa');
        store.cambiarModalidadTarifa(laTarifa().id, 'grupal');

        expect(laTarifa().calculoSnapshot).toBe('grupal');
    });
});


/**
 * Qué ve el CLIENTE cuando el único «estándar» de un componente es una operativa.
 *
 * 🔥 El caso real: Coco Bongo en la cotización de Santa Rosa (05/10/2026). El componente tenía
 * dos tarifas con el mismo título — una `operativa` de rol estándar (6 entradas liberadas, 85 × 6)
 * y una `alternativa` individual (85 × 60)— y la vista del huésped mostraba:
 *
 * ```
 * ALTERNATIVA 0
 * Noche en Coco Bongo · Fiesta Blanca
 * REEMPLAZA  N̶o̶c̶h̶e̶ ̶e̶n̶ ̶C̶o̶c̶o̶ ̶B̶o̶n̶g̶o̶ ̶·̶ ̶F̶i̶e̶s̶t̶a̶ ̶B̶l̶a̶n̶c̶a̶
 * ```
 *
 * Se reemplazaba a sí misma. Y los tres síntomas eran el mismo fallo: la operativa entraba como
 * estándar de referencia porque el filtro miraba el ROL y no si el cliente puede verla.
 *
 * ⚠️ **Lo que de verdad se protege es que el nombre de una operativa NO LLEGUE AL CLIENTE.** Aquí
 * se notó porque las dos se llamaban igual y quedaba absurdo; con nombres distintos habría
 * enseñado el título de una línea oculta sin que nada chirriara.
 */
describe('un upgrade cuyo único estándar es una operativa', () => {
    beforeEach(() => setActivePinia(createPinia()));

    const conOperativaYAlternativa = () => {
        const store = useCotizacionEditorStore();
        // @ts-expect-error — fixture mínimo.
        store.cotizacion = cotizacionCon(60, [
            tarifa(85, 6, { calculoSnapshot: 'operativa', grupoTarifa: null }),
            tarifa(85, 60, { calculoSnapshot: 'individual', rolSnapshot: 'alternativa', grupoTarifa: 1 }),
        ]);

        return (store.resumenFinanciero?.opcionesUpgrade ?? [])[0];
    };

    it('no dice que reemplaza a nadie', () => {
        // Antes: `tieneEstandarEspejo` true y `estandarTitulo` = el título de la operativa.
        expect(conOperativaYAlternativa()?.tieneEstandarEspejo).toBe(false);
    });

    it('es una OPCIÓN que se añade, no una alternativa que sustituye', () => {
        // Sin estándar visible no hay nada que alternar. Con el fallo, `pax` reconstruía la
        // etiqueta como «Alternativa 0» — grupo 1 menos 1 — que no significa nada.
        expect(conOperativaYAlternativa()?.esOpcion).toBe(true);
    });
});


/**
 * Una operativa ATADA A UN GRUPO sólo cuesta si se toma esa opción.
 *
 * 🔥 El caso que lo pidió: Coco Bongo en Santa Rosa. El componente es **opcional** —no tiene
 * estándar visible— y llevaba dentro una operativa de 6 entradas liberadas. Como las operativas
 * no tenían grupo, esos 510 entraban en el costo BASE de los 60: 8,50 por cabeza por una fiesta
 * que el grupo podía no contratar, en un componente que el propio editor marcaba «OPCIONAL».
 *
 * ⚠️ **Lo que NO cambia es el caso normal.** De las 16 operativas de esa cotización, 15 no tienen
 * grupo —los liberados del vuelo, del hotel, del seguro, de las comidas— y ésas son costo del
 * viaje: suman a la base siempre. Por eso el grupo es opt-in y no al revés.
 */
describe('una operativa atada a un grupo', () => {
    beforeEach(() => setActivePinia(createPinia()));

    /** Con una alternativa en el grupo 2, que es lo que hace que el grupo EXISTA. */
    const resumen = (extraOperativa: Record<string, unknown>, conAlternativa = true) => {
        const store = useCotizacionEditorStore();
        // @ts-expect-error — fixture mínimo.
        store.cotizacion = cotizacionCon(10, [
            tarifa(100, 10),                                                     // la base visible
            ...(conAlternativa ? [tarifa(50, 10, { rolSnapshot: 'alternativa', grupoTarifa: 2 })] : []),
            tarifa(80, 2, { calculoSnapshot: 'operativa', grupoTarifa: null, ...extraOperativa }),  // 2 liberados
        ]);

        return store.resumenFinanciero;
    };

    it('SIN grupo suma a la base, como siempre', () => {
        // 100 × 10 = 1000 de la estándar + 80 × 2 = 160 de los liberados.
        expect(resumen({})?.totalCostoNeto).toBeCloseTo(1160, 2);
    });

    it('CON grupo no suma a la base', () => {
        // Los 160 salen del costo del viaje: dependen de que se contrate la opción.
        expect(resumen({ grupoTarifa: 2 })?.totalCostoNeto).toBeCloseTo(1000, 2);
    });

    /**
     * 🔥 **Este test nació de un fallo del test anterior.**
     *
     * El fixture de `CON grupo no suma a la base` no tenía alternativa: ataba la operativa al
     * grupo 2 y ese grupo no existía. El costo salía de la base, nadie lo recogía abajo —`grupos`
     * se construye desde las alternativas— y los 160 **no estaban en ningún sitio**. El test
     * aseguraba 1000 y pasaba: fijaba como correcto que el dinero se evaporara.
     *
     * Lo encontró una revisión independiente. Es el mismo fallo que el test de «grupal» de la
     * mañana —un fixture que no reproduce el caso que el nombre promete— y por eso ahora el
     * ayudante construye el grupo de verdad, y estos tres vigilan los caminos sin salida.
     */
    it('atada a un grupo que NO existe, vuelve a sumar a la base', () => {
        expect(resumen({ grupoTarifa: 2 }, false)?.totalCostoNeto).toBeCloseTo(1160, 2);
    });

    it('y lo dice, porque atarla a un grupo muerto es un error que si no, no se ve', () => {
        const avisos = resumen({ grupoTarifa: 2 }, false)?.informativas ?? [];
        expect(avisos.some((a) => a.includes('no tiene ninguna opción'))).toBe(true);
    });

    it('en un componente que no está INCLUIDO tampoco se desvía', () => {
        // En cortesía y en no incluido el bloque de upgrades ni corre: nadie recogería el costo.
        const store = useCotizacionEditorStore();
        // @ts-expect-error — fixture mínimo.
        store.cotizacion = cotizacionCon(10, [
            tarifa(100, 10),
            tarifa(50, 10, { rolSnapshot: 'alternativa', grupoTarifa: 2 }),
            tarifa(80, 2, { calculoSnapshot: 'operativa', grupoTarifa: 2 }),
        ], 'cortesia');

        expect(store.resumenFinanciero?.totalCostoNeto).toBeCloseTo(1660, 2);
    });

    const conUpgradeYLiberados = () => {
        const store = useCotizacionEditorStore();
        // @ts-expect-error — fixture mínimo.
        store.cotizacion = cotizacionCon(10, [
            tarifa(100, 10),
            tarifa(50, 10, { rolSnapshot: 'alternativa', grupoTarifa: 2 }),
            tarifa(80, 2, { calculoSnapshot: 'operativa', grupoTarifa: 2 }),
        ]);

        return (store.resumenFinanciero?.opcionesUpgrade ?? [])[0];
    };

    /**
     * ⚠️ **Éste es el campo que se PINTA, y por eso existe este test.**
     *
     * La primera versión sumó los liberados sólo a `deltasPorPerfil`, que **no lo lee nadie**: lo
     * declara el tipo y ni `pax` ni el panel del operador lo tocan. El documento guardado decía
     * 109,91 y la pantalla del cliente seguía diciendo 101,41 — y la verificación que hice miraba
     * el JSON, no la pantalla, así que la dio por buena.
     *
     * Al cambiar una cifra, la pregunta es **quién la pinta**, no dónde parece que vive.
     */
    it('CON grupo se cobra en el adicional que SE MUESTRA, repartido entre todos', () => {
        // La alternativa vale 50/pax y la estándar 100/pax → −50. Y los liberados son
        // 80 × 2 = 160 repartidos entre 10 → +16. Total −34.
        expect(conUpgradeYLiberados()?.deltaVentaPorPax).toBeCloseTo(-34, 2);
    });

    it('y el total de la opción es ese adicional por todos los pasajeros', () => {
        expect(conUpgradeYLiberados()?.deltaVentaTotal).toBeCloseTo(-340, 2);
    });

    it('el «std → alt» del panel llega al mismo número que el delta de al lado', () => {
        const up = conUpgradeYLiberados();

        // Si la flecha no acaba donde dice el delta, el operador no sabe cuál creer.
        expect((up?.ventaPorPaxAlternativa ?? 0) - (up?.ventaPorPaxEstandar ?? 0))
            .toBeCloseTo(up?.deltaVentaPorPax ?? 0, 2);
    });
});


/**
 * El número del grupo NO es el número de la etiqueta, y por eso nadie lo escribe a mano.
 *
 * 🔥 Lo destapó una pregunta de una sola línea: «¿la Opción 1 es el grupo 1?». Hoy sí —ese
 * componente no tiene estándar visible— y el selector que acababa de escribir ponía
 * «Opción ${grupo}» con el número crudo. Con un estándar visible delante, el grupo 2 se llama
 * «Alternativa 1» en todas las demás pantallas, así que el selector habría mandado a atar el
 * liberado a una opción que el operador ve con otro nombre.
 */
describe('cómo se numera un grupo', () => {
    beforeEach(() => setActivePinia(createPinia()));

    it('sin estándar visible, el grupo 1 ES la Opción 1', () => {
        expect(etiquetaGrupoTarifa(1, false).label).toBe('Opción 1');
    });

    it('con estándar visible, el grupo 2 es la Alternativa 1 — uno menos', () => {
        expect(etiquetaGrupoTarifa(2, true).label).toBe('Alternativa 1');
    });

    it('una operativa NO cuenta como estándar visible', () => {
        expect(esEstandarVisible({ rolSnapshot: 'estandar', calculoSnapshot: 'operativa' })).toBe(false);
        expect(esEstandarVisible({ rolSnapshot: 'estandar', calculoSnapshot: 'individual' })).toBe(true);
    });

    it('y el rol «operativo» de antes de la fase 5 tampoco', () => {
        expect(esEstandarVisible({ rolSnapshot: 'operativo' })).toBe(false);
    });
});


/**
 * El título de una operativa NO puede llegar a la propuesta del cliente.
 *
 * 🔥 Lo encontró una revisión independiente, y la puerta no era la que yo había cerrado.
 * `opcionesUpgrade` estaba limpio; la fuga era `construirInclusiones()`, cuyo filtro de
 * «tarifa estándar **visible**» decía eso en el comentario y miraba **sólo el rol**.
 *
 * Consecuencia en el caso real de Coco Bongo: el componente contaba como «tiene estándar», se
 * publicaba en **incluidos** con el título de la operativa dentro, y a la vez salía como
 * «Opción 1» en los upgrades. El cliente leía las dos cosas en la misma propuesta.
 *
 * Era la CUARTA copia de la pregunta «¿hay estándar visible?» — las otras tres ya la hacían bien.
 */
describe('la operativa y lo que se publica como incluido', () => {
    beforeEach(() => setActivePinia(createPinia()));

    const inclusiones = () => {
        const store = useCotizacionEditorStore();
        // @ts-expect-error — fixture mínimo.
        store.cotizacion = cotizacionCon(10, [
            tarifa(50, 10, { rolSnapshot: 'alternativa', grupoTarifa: 1 }),
            tarifa(80, 2, { calculoSnapshot: 'operativa', grupoTarifa: 1, tituloSnapshot: [{ language: 'es', content: 'LIBERADOS' }] }),
        ]);

        return (store.resumenFinanciero?.inclusiones ?? []).flatMap((s) => [
            ...s.incluidos.map((l) => ({ donde: 'incluido', linea: l })),
            ...s.opcionales.map((l) => ({ donde: 'opcional', linea: l })),
        ]);
    };

    it('un componente cuya única estándar es operativa NO se publica como incluido', () => {
        expect(inclusiones().every((x) => x.donde === 'opcional')).toBe(true);
    });

    it('y su título no aparece por ningún lado', () => {
        const todo = JSON.stringify(inclusiones());
        expect(todo).not.toContain('LIBERADOS');
    });
});
