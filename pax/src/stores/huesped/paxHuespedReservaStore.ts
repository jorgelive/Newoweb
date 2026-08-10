import { defineStore } from 'pinia';
import { ref } from 'vue';
import { paxHuespedService } from '@/services/paxHuespedService.ts';
import type { PmsReserva } from '@/types/paxHuespedModel.ts';
import type { PersistenceOptions } from 'pinia-plugin-persistedstate';

export const usePmsReservaStore = defineStore('pmsReservaStore', () => {

        // 1. STATE
        const reserva = ref<PmsReserva | null>(null);
        const loading = ref(false);
        const error = ref<string | null>(null);
        const lastUpdate = ref<number>(0);

        /**
         * ⏳ Cuánto se considera «fresca» la reserva antes de volver a pedirla.
         *
         * Diez segundos, no quince minutos. El momento en que el huésped mira esta pantalla
         * con más atención es JUSTO DESPUÉS DE PAGAR: quiere ver su saldo actualizado. Con
         * una caché de quince minutos veía el saldo viejo y volvía a pagar, o escribía al
         * chat preguntando si su pago entró — que es trabajo para el equipo por un problema
         * que no existía.
         *
         * No es «desactivar la caché»: son los diez segundos que hacen falta para que un
         * refresco compulsivo —el mismo huésped recargando cinco veces seguidas— no se
         * convierta en cinco peticiones. Cubre la ráfaga y deja de estorbar.
         *
         * ⚠️ Esto NO afecta al modo offline: el CASO B de abajo sigue devolviendo lo
         * guardado cuando no hay conexión, por viejo que sea. Un dato de ayer es infinitamente
         * mejor que una pantalla en blanco en un hotel sin wifi, y por eso la comprobación de
         * `navigator.onLine` va DESPUÉS de la de frescura y no antes.
         */
        const CACHE_TTL = 10 * 1000;

        // 2. ACTIONS
        const cargarReserva = async (localizador: string) => {
            const ahora = Date.now();
            const tiempoTranscurrido = ahora - lastUpdate.value;
            const datosExisten = reserva.value && reserva.value.localizador === localizador;
            const esFresco = tiempoTranscurrido < CACHE_TTL;
            const hayInternet = navigator.onLine;

            // CASO A: Cache válida y fresca
            if (datosExisten && esFresco) {
                return;
            }

            // CASO B: Sin internet pero con datos guardados (Salvavidas)
            if (datosExisten && !hayInternet) {
                console.warn('📡 ReservaStore: Usando datos offline.');
                return;
            }

            loading.value = true;
            // No limpiamos 'reserva.value' para evitar que la pantalla se quede en blanco
            // mientras descarga la actualización.

            try {
                const data = await paxHuespedService.getPmsReserva(localizador);
                let reservaData;

                if (data && data['hydra:member']) {
                    reservaData = data['hydra:member'][0] || null;
                } else {
                    reservaData = data;
                }

                if (!reservaData || !reservaData.localizador) {
                    throw new Error("No se encontró la reserva.");
                }

                // ÉXITO: Actualizamos todo
                reserva.value = reservaData;
                lastUpdate.value = Date.now();
                error.value = null;

            } catch (err: unknown) {
                console.error("Error en pmsReservaStore:", err);

                // CASO C: Error de red pero ya teníamos datos
                if (datosExisten) {
                    error.value = "Mostrando copia local (no se pudo actualizar).";
                } else {
                    // Si no había nada, mostramos el error fatal
                    error.value = (err as Error)?.message || 'Error al conectar con el servidor';
                    reserva.value = null;
                }
            } finally {
                loading.value = false;
            }
        };

        return {
            reserva,
            loading,
            error,
            lastUpdate,
            cargarReserva
        };
    },
    {
        persist: {
            // Guardamos la reserva y el timestamp para que el TTL funcione tras F5
            paths: ['reserva', 'lastUpdate'],
            storage: localStorage,
        } as PersistenceOptions
    });