import { defineStore } from 'pinia';
import { ref, computed } from 'vue';

// ============================================================================
// TIPOS E INTERFACES BASE
// ============================================================================
export type NivelInspector = 'resumen' | 'servicio' | 'componente' | 'tarifa';

export interface I18nString {
    language: string;
    content: string;
}

export const useCotizacionEditorStore = defineStore('cotizacionEditorStore', () => {

    // ============================================================================
    // 1. ESTADOS DE DATOS (Catálogos y Mock Multilingüe)
    // ============================================================================

    // Diccionario base de Machupicchu para reutilizar en el mock
    const mockMachupicchuI18n = [
        { language: 'es', content: 'Excursión a Machupicchu de 1 día' },
        { language: 'en', content: 'Day trip to Machu Picchu' },
        { language: 'pt', content: 'Excursão de um dia a Machu Picchu' },
        { language: 'fr', content: "Excursion d'une journée au Machu Picchu" },
        { language: 'it', content: 'Escursione di un giorno a Machu Picchu' },
        { language: 'de', content: 'Tagesausflug nach Machu Picchu' },
        { language: 'nl', content: 'Dagtocht naar Machu Picchu' }
    ];

    const catalogos = ref({
        servicios: [
            { id: 'srv_m_1', nombreInterno: 'Machupicchu Full Day', nombreI18n: JSON.parse(JSON.stringify(mockMachupicchuI18n)) }
        ],
        componentes: [
            { id: 'comp_m_1', nombreI18n: [{ language: 'es', content: 'Ticket de Tren Expedition' }, { language: 'en', content: 'Expedition Train Ticket' }], tipo: 'tren' }
        ],
        tarifas: [
            { id: 'tar_m_1', nombreInterno: 'Tren PeruRail', nombreI18n: [{ language: 'es', content: 'Ticket Tren' }], moneda: 'USD', monto: 65.00, modalidad: 'Compartido', proveedorNombre: 'PeruRail' }
        ],
        plantillasItinerario: [
            {
                id: 'plt_1',
                nombreI18n: JSON.parse(JSON.stringify(mockMachupicchuI18n)),
                segmentos: [
                    {
                        id: 'seg_m_1',
                        nombreI18n: [{ language: 'es', content: 'Viaje en Tren' }, { language: 'en', content: 'Train Journey' }],
                        contenidoI18n: [{ language: 'es', content: 'Iniciaremos el viaje en tren...' }, { language: 'en', content: 'We will start the train journey...' }]
                    }
                ]
            }
        ],
        poolSegmentos: []
    });

    /** Snapshot Inmutable de la Cotización Actual (Totalmente Multilingüe) */
    const cotizacion = ref({
        version: 1,
        estado: 'Pendiente',
        monedaGlobal: 'USD',
        idiomaCliente: 'en', // Asumimos que este File es para un cliente angloparlante
        idiomaEdicion: 'es', // El operador empieza tipeando en español
        numPax: 3,
        comision: 19.00,
        adelanto: 50.00,
        hotelOculto: true,
        precioOculto: false,
        resumenI18n: [
            { language: 'es', content: 'Resumen general de su viaje a Machupicchu.' },
            { language: 'en', content: 'General overview of your trip to Machu Picchu.' }
        ],

        itinerario: [
            {
                diaNumero: 1,
                fechaAbsoluta: '2026-05-25',
                cotservicios: [
                    {
                        id: 'srv_1',
                        servicioMaestroId: 'srv_m_1',
                        // 🔥 Aquí inyectamos los 7 idiomas reales para el Snapshot
                        nombreSnapshot: JSON.parse(JSON.stringify(mockMachupicchuI18n)),
                        itinerarioNombreSnapshot: JSON.parse(JSON.stringify(mockMachupicchuI18n)),
                        fechaInicioAbsoluta: '2026-05-25',
                        cotsegmentos: [
                            {
                                id: 'cot_seg_1',
                                dia: 1,
                                orden: 1,
                                nombreSnapshot: [
                                    { language: 'es', content: 'Llegada a Aguas Calientes' },
                                    { language: 'en', content: 'Arrival at Aguas Calientes' },
                                    { language: 'pt', content: 'Chegada a Aguas Calientes' }
                                ],
                                contenidoSnapshot: [
                                    { language: 'es', content: 'A su llegada, nuestro guía los acompañará al bus de subida.' },
                                    { language: 'en', content: 'Upon arrival, our guide will escort you to the bus.' },
                                    { language: 'pt', content: 'Na chegada, nosso guia os acompanhará até o ônibus.' }
                                ]
                            }
                        ],
                        cotcomponentes: [
                            {
                                id: 'comp_1',
                                componenteMaestroId: 'comp_m_1',
                                nombreSnapshot: [
                                    { language: 'es', content: 'Ticket de Tren Vistadome' },
                                    { language: 'en', content: 'Vistadome Train Ticket' }
                                ],
                                cantidad: 1,
                                estado: 'Pendiente',
                                modo: 'incluido',
                                fechaHoraInicio: '2026-05-25T06:30',
                                fechaHoraFin: '2026-05-25T09:30',
                                cotsegmentoId: 'cot_seg_1',
                                snapshotItems: [],
                                cottarifas: [
                                    {
                                        id: 'tar_1',
                                        tarifaMaestraId: 'tar_m_1',
                                        nombreSnapshot: [{ language: 'es', content: 'Tren PeruRail' }],
                                        cantidad: 3, // Heredado de numPax
                                        moneda: 'USD',
                                        montoCosto: 85.00,
                                        tipoModalidadSnapshot: 'Compartido',
                                        proveedorNombreSnapshot: 'PeruRail',
                                        detallesOperativos: [
                                            { id: 'det_1', tipo: 'Información Operativa', contenido: 'Sale 06:30 hrs desde Ollantaytambo' }
                                        ]
                                    }
                                ]
                            }
                        ]
                    }
                ]
            }
        ]
    });

    // ============================================================================
    // 2. CEREBRO FINANCIERO
    // ============================================================================
    const totalCostoNeto = computed(() => {
        let total = 0;
        cotizacion.value.itinerario.forEach(dia => {
            dia.cotservicios.forEach(servicio => {
                servicio.cotcomponentes.forEach(componente => {
                    if (componente.modo === 'incluido' && componente.estado !== 'Cancelado') {
                        componente.cottarifas.forEach(tarifa => {
                            total += (tarifa.montoCosto * tarifa.cantidad);
                        });
                    }
                });
            });
        });
        return total;
    });

    const ventaSugerida = computed(() => {
        return totalCostoNeto.value * (1 + (cotizacion.value.comision / 100));
    });

    // ============================================================================
    // 3. I18N HELPERS (Lectura y Escritura Reactiva)
    // ============================================================================

    /**
     * Retorna el nodo del idioma. Si no existe, lo crea al vuelo (Lazy Translation Init).
     * Esto evita que el v-model de Vue rompa si el array de idiomas de un componente nuevo está vacío.
     */
    const getI18n = (arrayI18n: I18nString[] | undefined, lang: string): I18nString => {
        if (!arrayI18n) return { language: lang, content: '' };

        let found = arrayI18n.find(item => item.language === lang);
        if (!found) {
            found = { language: lang, content: '' };
            arrayI18n.push(found);
        }
        return found;
    };

    /** Muestra el idioma actual, si no está traducido, avisa. */
    const renderI18n = (arrayI18n: I18nString[] | undefined, lang: string): string => {
        if (!arrayI18n || !Array.isArray(arrayI18n)) return `[Sin traducción ${lang.toUpperCase()}]`;
        const found = arrayI18n.find(item => item.language === lang && item.content.trim() !== '');
        return found ? found.content : `[Falta traducción ${lang.toUpperCase()}]`;
    };

    // ============================================================================
    // 4. ESTADOS DE INTERFAZ & NAVEGACIÓN
    // ============================================================================
    const inspectorActivo = ref<NivelInspector>('resumen');
    const dataActiva = ref<any>(null);
    const historialNavegacion = ref<{ nivel: NivelInspector, data: any }[]>([]);
    const isMobileOpen = ref<boolean>(false);
    const isSegmentEditorOpen = ref<boolean>(false);

    const abrirNivel = (nivel: NivelInspector, data: any = null): void => {
        if (nivel === 'servicio' || nivel === 'resumen') historialNavegacion.value = [];
        else historialNavegacion.value.push({ nivel: inspectorActivo.value, data: dataActiva.value });
        inspectorActivo.value = nivel;
        dataActiva.value = data;
        isMobileOpen.value = true;
    };

    const retrocederNivel = (): void => {
        if (historialNavegacion.value.length > 0) {
            const previo = historialNavegacion.value.pop()!;
            inspectorActivo.value = previo.nivel;
            dataActiva.value = previo.data;
        } else {
            inspectorActivo.value = 'resumen';
            dataActiva.value = null;
            isMobileOpen.value = false;
        }
    };

    const cerrarInspectorMobile = (): void => {
        isMobileOpen.value = false;
        setTimeout(() => { inspectorActivo.value = 'resumen'; dataActiva.value = null; historialNavegacion.value = []; }, 300);
    };

    // ============================================================================
    // 5. ACCIONES CRUD (Memoria)
    // ============================================================================

    const guardarCotizacion = async (): Promise<void> => {
        // En tu fase de conexión real, este JSON es el que se enviará vía PUT a API Platform.
        // API Platform se encargará de hacer el diffing de idiomas.
        console.log("JSON FINAL PARA GUARDAR:", JSON.parse(JSON.stringify(cotizacion.value)));
        alert('Cotización guardada. Revisa la consola para ver el JSON.');
    };

    const verificarInspectorTrasBorrado = (idBorrado: string) => {
        if (dataActiva.value && dataActiva.value.id === idBorrado) retrocederNivel();
    };

    const agregarServicio = (diaNumero: number): void => {
        const dia = cotizacion.value.itinerario.find(d => d.diaNumero === diaNumero);
        if (dia) {
            const nuevoServicio = {
                id: crypto.randomUUID(),
                servicioMaestroId: null,
                nombreSnapshot: [{ language: 'es', content: 'Nuevo Servicio' }],
                itinerarioNombreSnapshot: [{ language: 'es', content: 'Sin plantilla' }],
                fechaInicioAbsoluta: dia.fechaAbsoluta,
                cotsegmentos: [],
                cotcomponentes: []
            };
            dia.cotservicios.push(nuevoServicio as any);
            abrirNivel('servicio', nuevoServicio);
        }
    };

    const eliminarServicio = (diaNumero: number, servicioId: string): void => {
        const dia = cotizacion.value.itinerario.find(d => d.diaNumero === diaNumero);
        if (dia) {
            dia.cotservicios = dia.cotservicios.filter(s => s.id !== servicioId);
            verificarInspectorTrasBorrado(servicioId);
        }
    };

    const agregarComponente = (servicioId: string): void => {
        for (const dia of cotizacion.value.itinerario) {
            const servicio = dia.cotservicios.find(s => s.id === servicioId);
            if (servicio) {
                const nuevoComponente = {
                    id: crypto.randomUUID(),
                    componenteMaestroId: null,
                    nombreSnapshot: [{ language: 'es', content: 'Nuevo Componente' }],
                    cantidad: 1,
                    estado: 'Pendiente',
                    modo: 'incluido',
                    fechaHoraInicio: `${servicio.fechaInicioAbsoluta}T08:00`,
                    fechaHoraFin: `${servicio.fechaInicioAbsoluta}T09:00`,
                    cotsegmentoId: null,
                    snapshotItems: [],
                    cottarifas: []
                };
                servicio.cotcomponentes.push(nuevoComponente as any);
                abrirNivel('componente', nuevoComponente);
                return;
            }
        }
    };

    const eliminarComponente = (servicioId: string, componenteId: string): void => {
        for (const dia of cotizacion.value.itinerario) {
            const servicio = dia.cotservicios.find(s => s.id === servicioId);
            if (servicio) {
                servicio.cotcomponentes = servicio.cotcomponentes.filter(c => c.id !== componenteId);
                verificarInspectorTrasBorrado(componenteId);
                return;
            }
        }
    };

    const agregarTarifa = (componenteId: string): void => {
        for (const dia of cotizacion.value.itinerario) {
            for (const servicio of dia.cotservicios) {
                const componente = servicio.cotcomponentes.find(c => c.id === componenteId);
                if (componente) {
                    const nuevaTarifa = {
                        id: crypto.randomUUID(),
                        tarifaMaestraId: null,
                        nombreSnapshot: [{ language: 'es', content: 'Nueva Tarifa' }],
                        cantidad: cotizacion.value.numPax,
                        moneda: cotizacion.value.monedaGlobal,
                        montoCosto: 0.00,
                        tipoModalidadSnapshot: 'Normal',
                        proveedorNombreSnapshot: null,
                        detallesOperativos: []
                    };
                    componente.cottarifas.push(nuevaTarifa as any);
                    abrirNivel('tarifa', nuevaTarifa);
                    return;
                }
            }
        }
    };

    const eliminarTarifa = (componenteId: string, tarifaId: string): void => {
        for (const dia of cotizacion.value.itinerario) {
            for (const servicio of dia.cotservicios) {
                const componente = servicio.cotcomponentes.find(c => c.id === componenteId);
                if (componente) {
                    componente.cottarifas = componente.cottarifas.filter(t => t.id !== tarifaId);
                    verificarInspectorTrasBorrado(tarifaId);
                    return;
                }
            }
        }
    };

    const agregarDetalleOperativo = (tarifaId: string): void => {
        for (const dia of cotizacion.value.itinerario) {
            for (const servicio of dia.cotservicios) {
                for (const componente of servicio.cotcomponentes) {
                    const tarifa = componente.cottarifas.find(t => t.id === tarifaId);
                    if (tarifa) {
                        tarifa.detallesOperativos.push({ id: crypto.randomUUID(), tipo: 'Información Operativa', contenido: '' });
                        return;
                    }
                }
            }
        }
    };

    const eliminarDetalleOperativo = (tarifaId: string, detalleId: string): void => {
        for (const dia of cotizacion.value.itinerario) {
            for (const servicio of dia.cotservicios) {
                for (const componente of servicio.cotcomponentes) {
                    const tarifa = componente.cottarifas.find(t => t.id === tarifaId);
                    if (tarifa) {
                        tarifa.detallesOperativos = tarifa.detallesOperativos.filter(d => d.id !== detalleId);
                        return;
                    }
                }
            }
        }
    };

    // ============================================================================
    // 6. STORYTELLING Y AUTOCOMPLETADO
    // ============================================================================
    const abrirEditorSegmentos = () => { isSegmentEditorOpen.value = true; };
    const cerrarEditorSegmentos = () => { isSegmentEditorOpen.value = false; };

    const aplicarPlantilla = (plantillaId: string): void => {
        const plantilla = catalogos.value.plantillasItinerario.find(p => p.id === plantillaId);
        if (!plantilla || !dataActiva.value) return;

        dataActiva.value.itinerarioNombreSnapshot = JSON.parse(JSON.stringify(plantilla.nombreI18n));
        let ordenMaximo = dataActiva.value.cotsegmentos.length;
        plantilla.segmentos.forEach(seg => {
            ordenMaximo++;
            dataActiva.value.cotsegmentos.push({
                id: crypto.randomUUID(),
                dia: 1,
                orden: ordenMaximo,
                nombreSnapshot: JSON.parse(JSON.stringify(seg.nombreI18n)),
                contenidoSnapshot: JSON.parse(JSON.stringify(seg.contenidoI18n))
            });
        });
    };

    const agregarSegmentoIndividual = (segmentoMaestro: any): void => {
        if (!dataActiva.value) return;
        const ordenNuevo = dataActiva.value.cotsegmentos.length + 1;
        dataActiva.value.cotsegmentos.push({
            id: crypto.randomUUID(),
            dia: 1,
            orden: ordenNuevo,
            nombreSnapshot: JSON.parse(JSON.stringify(segmentoMaestro.nombreI18n)),
            contenidoSnapshot: JSON.parse(JSON.stringify(segmentoMaestro.contenidoI18n))
        });
    };

    const removerCotSegmento = (id: string): void => {
        if (!dataActiva.value) return;
        dataActiva.value.cotsegmentos = dataActiva.value.cotsegmentos.filter((s: any) => s.id !== id);
    };

    // Funciones de autocompletado desde Catálogos
    const onServicioMaestroChange = (id: string): void => {
        const maestro = catalogos.value.servicios.find(s => s.id === id);
        if (maestro && dataActiva.value) dataActiva.value.nombreSnapshot = JSON.parse(JSON.stringify(maestro.nombreI18n));
    };

    const onComponenteMaestroChange = (id: string): void => {
        const maestro = catalogos.value.componentes.find(c => c.id === id);
        if (maestro && dataActiva.value) dataActiva.value.nombreSnapshot = JSON.parse(JSON.stringify(maestro.nombreI18n));
    };

    const onTarifaMaestraChange = (id: string): void => {
        const maestro = catalogos.value.tarifas.find(t => t.id === id);
        if (maestro && dataActiva.value) {
            dataActiva.value.nombreSnapshot = JSON.parse(JSON.stringify(maestro.nombreI18n));
            dataActiva.value.moneda = maestro.moneda;
            dataActiva.value.montoCosto = maestro.monto;
            dataActiva.value.tipoModalidadSnapshot = maestro.modalidad;
            dataActiva.value.proveedorNombreSnapshot = maestro.proveedorNombre;
        }
    };

    return {
        catalogos, cotizacion, inspectorActivo, dataActiva, isMobileOpen, isSegmentEditorOpen,
        totalCostoNeto, ventaSugerida,
        getI18n, renderI18n,
        abrirNivel, retrocederNivel, cerrarInspectorMobile, guardarCotizacion,
        agregarServicio, eliminarServicio, agregarComponente, eliminarComponente,
        agregarTarifa, eliminarTarifa, agregarDetalleOperativo, eliminarDetalleOperativo,
        abrirEditorSegmentos, cerrarEditorSegmentos, aplicarPlantilla, agregarSegmentoIndividual, removerCotSegmento,
        onServicioMaestroChange, onComponenteMaestroChange, onTarifaMaestraChange
    };
});