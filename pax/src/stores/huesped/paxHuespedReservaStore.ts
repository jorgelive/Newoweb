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

        // ⏳ TTL: 15 minutos para reservas (un poco más frecuente que la guía)
        const CACHE_TTL = 15 * 60 * 1000;

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

            } catch (err: any) {
                console.error("Error en pmsReservaStore:", err);

                // CASO C: Error de red pero ya teníamos datos
                if (datosExisten) {
                    error.value = "Mostrando copia local (no se pudo actualizar).";
                } else {
                    // Si no había nada, mostramos el error fatal
                    error.value = err.message || 'Error al conectar con el servidor';
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