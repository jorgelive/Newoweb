// src/stores/pmsReservaStore.ts
import { defineStore } from 'pinia';
import { paxService } from '@/services/paxService';
import type { PmsReserva } from '@/types/pms';

export const usePmsReservaStore = defineStore('pmsReservaStore', {
    state: () => ({
        reserva: null as PmsReserva | null,
        loading: false,
        error: null as string | null,
    }),

    actions: {
        async cargarReserva(localizador: string) {
            this.loading = true;
            this.error = null;
            try {
                const data = await paxService.getPmsReserva(localizador);

                // 🔥 PROTECCIÓN: Si la API devuelve el objeto envuelto (Hydra)
                // Usualmente el GET de un item por ID devuelve el objeto directo,
                // pero si es una búsqueda, viene en member.
                if (data && data['hydra:member']) {
                    this.reserva = data['hydra:member'][0] || null;
                } else {
                    this.reserva = data;
                }

                if (!this.reserva || !this.reserva.localizador) {
                    this.reserva = null;
                    this.error = "No se encontró la reserva.";
                }
            } catch (err: any) {
                this.error = err.message || 'Error al conectar con el servidor';
                this.reserva = null;
                console.error("Error en pmsReservaStore:", err);
            } finally {
                this.loading = false;
            }
        }
    },
    persist: true
});