// src/services/sessionState.ts
import { ref } from 'vue';

/**
 * Estado de sesión compartido entre el interceptor de Axios (apiClient.ts)
 * y la UI (chatStore.ts / componentes).
 *
 * Vive fuera de Pinia deliberadamente: apiClient.ts necesita poder marcar
 * la sesión como expirada sin importar el store (evita el import circular
 * apiClient <-> chatStore), y chatStore.ts solo necesita re-exponer este ref
 * reactivo para que la UI reaccione igual que antes.
 */
export const isSessionExpired = ref(false);