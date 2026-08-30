// src/stores/reservas/finanzasStore.ts
// ============================================================================
// Panel financiero de una reserva: cabecera + cargos (Beds24) + pagos propios.
//
// Los enums (tipos de cargo, medios de pago) se cargan desde el backend
// (PmsEnumAjaxController) y se cachean en memoria: sus etiquetas viven SOLO en
// PHP, este store nunca las declara.
// ============================================================================
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type {
    PmsInformacionFinanciera,
    PmsCargoFinancieroPatch,
    PmsCargoFinancieroCreate,
    PmsPagoFinancieroCreate,
    PmsPagoFinancieroPatch,
    PmsTipoCargoOption,
    PmsMedioPagoOption,
    PmsCobradorOption,
    PmsFinanzasMonedaRef,
} from '@/types/pmsFinanzasModel';

export const useFinanzasStore = defineStore('finanzasStore', () => {
    const isLoading = ref<boolean>(false);
    const isSaving = ref<boolean>(false);

    /** Cabecera financiera de la reserva abierta en el drawer (null si aún no existe). */
    const info = ref<PmsInformacionFinanciera | null>(null);

    /**
     * Con qué reserva se cargó el panel, para que `recargar()` pueda repetir la MISMA operación.
     *
     * `por-reserva` no es un atajo del `GET` por id: es la única que rellena `prepagoPendiente`
     * y `costosTeoricos` —los calcula el provider, no la entidad—. Sin recordar el id, cada
     * recarga tenía que caer al `GET` por id y **borraba los dos**.
     */
    const reservaCargada = ref<string | null>(null);

    // Catálogos de enums servidos por PHP (cacheados en memoria).
    const tiposCargo = ref<PmsTipoCargoOption[]>([]);
    const mediosPago = ref<PmsMedioPagoOption[]>([]);
    const monedas = ref<PmsFinanzasMonedaRef[]>([]);
    const enumsLoaded = ref<boolean>(false);

    /** Usuarios con ROLE_COBRADOR, para el desplegable "lo cobró" del formulario de pago. */
    const cobradores = ref<PmsCobradorOption[]>([]);

    /**
     * Carga los enums del PMS (y el maestro de monedas) una sola vez por sesión.
     * El backend además cachea los enums 1h en el navegador (setSharedMaxAge).
     */
    const fetchEnums = async (): Promise<void> => {
        if (enumsLoaded.value) return;

        const [tcRes, mpRes, monRes] = await Promise.all([
            apiClient.get('/tipo/user/enum/pms/tipos-cargo'),
            apiClient.get('/tipo/user/enum/pms/medios-pago'),
            apiClient.get('/platform/maestro/monedas'),
        ]);

        tiposCargo.value = tcRes.data || [];
        mediosPago.value = mpRes.data || [];
        monedas.value = monRes.data['hydra:member'] || monRes.data['member'] || [];
        enumsLoaded.value = true;
    };

    /**
     * Carga la lista de cobradores.
     *
     * A propósito FUERA de `fetchEnums()` y sin el guardia de `enumsLoaded`: no es un enum,
     * son personas. Dar de alta a alguien tiene que verse en el acto, y cachearla por sesión
     * haría que el operador no encontrase a quien acaban de registrar —el backend tampoco la
     * cachea, al contrario que los enums (ver `PmsEnumAjaxController::getCobradores()`)—.
     *
     * Silenciosa ante fallo: sin lista el desplegable queda vacío, que es un incordio, pero
     * no debe impedir registrar el pago.
     */
    const fetchCobradores = async (): Promise<void> => {
        try {
            const res = await apiClient.get('/tipo/user/enum/pms/cobradores');
            cobradores.value = res.data || [];
        } catch {
            cobradores.value = [];
        }
    };

    /**
     * Trae la información financiera de una reserva.
     *
     * Usa el endpoint dedicado `/por-reserva/{id}` y NO un filtro de colección: el
     * `SearchFilter` sobre la relación `reserva` no vincula el tipo Doctrine del UUID
     * binario y devolvía SIEMPRE una colección vacía, sin error (§12.6 del doc).
     *
     * Un 404 significa que la reserva aún no tiene cabecera: no es un fallo, el panel
     * simplemente aparece vacío.
     */
    const fetchPorReserva = async (reservaId: string): Promise<PmsInformacionFinanciera | null> => {
        isLoading.value = true;
        try {
            const res = await apiClient.get(`/platform/pms/pms_informacion_financieras/por-reserva/${reservaId}`);
            info.value = res.data ?? null;
            reservaCargada.value = reservaId;
            return info.value;
        } catch (err) {
            if ((err as { response?: { status?: number } })?.response?.status === 404) {
                info.value = null;
                reservaCargada.value = reservaId;
                return null;
            }
            throw err;
        } finally {
            isLoading.value = false;
        }
    };

    /** Recarga la cabecera por su id (tras crear/editar un hijo, para refrescar totales). */
    /**
     * Relee la ficha después de cada escritura. La llaman los ocho `create`/`patch`/`delete`.
     *
     * ⚠️ **Vuelve por `por-reserva` y no por el `GET` por id.** Sólo esa operación rellena
     * `prepagoPendiente` y `costosTeoricos`: los calcula
     * `PmsInformacionFinancieraPorReservaProvider`, no la entidad, así que en el `GET` por id
     * llegan `null` — y no porque no existan, sino porque ahí nadie los ha calculado.
     *
     * Recargando por id, **cada cobro registrado hacía desaparecer del panel el bloque del
     * prepago y los costos teóricos**. Con el prepago costaba verlo, porque tras cobrarlo TIENE
     * que desaparecer; se notaba al registrar un pago parcial o al tocar un cargo, que los
     * borraba igual sin haber cobrado nada.
     *
     * No se llama a `fetchPorReserva()` a propósito: aquélla levanta `isLoading`, y el panel
     * pinta un esqueleto con esa bandera. Un parpadeo en cada guardado sería peor que el fallo
     * que esto arregla. El `GET` por id se conserva de respaldo por si alguien llegó a la ficha
     * sin pasar por `fetchPorReserva`.
     */
    const recargar = async (): Promise<void> => {
        const reservaId = reservaCargada.value;

        if (reservaId !== null) {
            const res = await apiClient.get(`/platform/pms/pms_informacion_financieras/por-reserva/${reservaId}`);
            info.value = res.data ?? null;
            return;
        }

        const id = info.value?.id;
        if (!id) return;
        const res = await apiClient.get(`/platform/pms/pms_informacion_financieras/${id}`);
        info.value = res.data;
    };

    const patchCargo = async (id: string, payload: PmsCargoFinancieroPatch): Promise<void> => {
        isSaving.value = true;
        try {
            await apiClient.patch(`/platform/pms/pms_cargo_financieros/${id}`, payload);
            await recargar();
        } finally {
            isSaving.value = false;
        }
    };

    /** Crea un cargo manual (reservas directas, que no reciben invoiceItems del canal). */
    const createCargo = async (payload: PmsCargoFinancieroCreate): Promise<void> => {
        isSaving.value = true;
        try {
            await apiClient.post('/platform/pms/pms_cargo_financieros', payload);
            await recargar();
        } finally {
            isSaving.value = false;
        }
    };

    /**
     * Activa/anula los cargos de la reserva (§12.7). Se usa cuando una reserva cancelada
     * en la OTA sigue adelante como directa: al reactivarla vuelven a contar sus cargos.
     */
    const setActiva = async (activa: boolean): Promise<void> => {
        const id = info.value?.id;
        if (!id) return;
        isSaving.value = true;
        try {
            await apiClient.patch(`/platform/pms/pms_informacion_financieras/${id}`, { activa });
            await recargar();
        } finally {
            isSaving.value = false;
        }
    };

    /**
     * Cambia la MONEDA BASE (contable) de la reserva.
     *
     * No es un PATCH sobre `moneda`: el backend reescribe los cargos en la moneda nueva
     * (anotando el importe original en la descripción), rellena los tipos de cambio que
     * falten en los pagos —que NO se convierten— y recalcula los totales. Sólo funciona en
     * reservas directas puras; en las demás responde 422 (§12.4.4).
     */
    const cambiarMonedaBase = async (moneda: string, tipoCambio?: string | null): Promise<void> => {
        const id = info.value?.id;
        if (!id) return;
        isSaving.value = true;
        try {
            await apiClient.post(
                `/platform/pms/pms_informacion_financieras/${id}/moneda-base`,
                { moneda, tipoCambio: tipoCambio || null },
            );
            await recargar();
        } finally {
            isSaving.value = false;
        }
    };

    /** Sólo los cargos manuales son borrables: el backend veta los de Beds24. */
    const deleteCargo = async (id: string): Promise<void> => {
        isSaving.value = true;
        try {
            await apiClient.delete(`/platform/pms/pms_cargo_financieros/${id}`);
            await recargar();
        } finally {
            isSaving.value = false;
        }
    };

    const createPago = async (payload: PmsPagoFinancieroCreate): Promise<void> => {
        isSaving.value = true;
        try {
            await apiClient.post('/platform/pms/pms_pago_financieros', payload);
            await recargar();
        } finally {
            isSaving.value = false;
        }
    };

    const patchPago = async (id: string, payload: PmsPagoFinancieroPatch): Promise<void> => {
        isSaving.value = true;
        try {
            await apiClient.patch(`/platform/pms/pms_pago_financieros/${id}`, payload);
            await recargar();
        } finally {
            isSaving.value = false;
        }
    };

    const deletePago = async (id: string): Promise<void> => {
        isSaving.value = true;
        try {
            await apiClient.delete(`/platform/pms/pms_pago_financieros/${id}`);
            await recargar();
        } finally {
            isSaving.value = false;
        }
    };

    const clear = (): void => {
        info.value = null;
    };

    return {
        isLoading,
        isSaving,
        info,
        tiposCargo,
        mediosPago,
        monedas,
        cobradores,
        fetchEnums,
        fetchCobradores,
        fetchPorReserva,
        recargar,
        patchCargo,
        createCargo,
        deleteCargo,
        setActiva,
        cambiarMonedaBase,
        createPago,
        patchPago,
        deletePago,
        clear,
    };
});
