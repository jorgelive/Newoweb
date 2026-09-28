<template>
  <div class="message-status">
    <svg v-if="status === 'pending' || status === 'queued'" viewBox="0 0 16 16" width="12" height="12" class="icon-gray">
      <path fill="currentColor" d="M8 1.5a6.5 6.5 0 100 13 6.5 6.5 0 000-13zM0 8a8 8 0 1116 0A8 8 0 010 8z"/>
      <path fill="currentColor" d="M8 3.5a.5.5 0 00-.5.5v4a.5.5 0 00.146.354l2.5 2.5a.5.5 0 00.708-.708L8.5 7.793V4a.5.5 0 00-.5-.5z"/>
    </svg>

    <svg v-else-if="status === 'sent'" viewBox="0 0 16 15" width="16" height="15" class="icon-gray">
      <path fill="currentColor" d="M10.91 3.316l-4.2 4.2-1.82-1.82a.75.75 0 10-1.06 1.06l2.35 2.35a.75.75 0 001.06 0l4.73-4.73a.75.75 0 00-1.06-1.06z"/>
    </svg>

    <svg v-else-if="status === 'delivered'" viewBox="0 0 16 15" width="16" height="15" class="icon-gray">
      <path fill="currentColor" d="M15.01 3.316l-4.2 4.2-1.82-1.82a.75.75 0 10-1.06 1.06l2.35 2.35a.75.75 0 001.06 0l4.73-4.73a.75.75 0 00-1.06-1.06z"/>
      <path fill="currentColor" d="M10.81 3.316l-4.2 4.2-1.82-1.82a.75.75 0 10-1.06 1.06l2.35 2.35a.75.75 0 001.06 0l4.73-4.73a.75.75 0 00-1.06-1.06z" transform="translate(-4, 0)"/>
    </svg>

    <svg v-else-if="status === 'read'" viewBox="0 0 16 15" width="16" height="15" class="icon-blue">
      <path fill="currentColor" d="M15.01 3.316l-4.2 4.2-1.82-1.82a.75.75 0 10-1.06 1.06l2.35 2.35a.75.75 0 001.06 0l4.73-4.73a.75.75 0 00-1.06-1.06z"/>
      <path fill="currentColor" d="M10.81 3.316l-4.2 4.2-1.82-1.82a.75.75 0 10-1.06 1.06l2.35 2.35a.75.75 0 001.06 0l4.73-4.73a.75.75 0 00-1.06-1.06z" transform="translate(-4, 0)"/>
    </svg>

    <svg v-else-if="status === 'failed'" viewBox="0 0 16 16" width="12" height="12" class="icon-red">
      <path fill="currentColor" d="M8 15A7 7 0 118 1a7 7 0 010 14zm0 1A8 8 0 108 0a8 8 0 000 16z"/>
      <path fill="currentColor" d="M7.002 11a1 1 0 112 0 1 1 0 01-2 0zM7.1 4.995a.905.905 0 111.8 0l-.35 3.507a.552.552 0 01-1.1 0L7.1 4.995z"/>
    </svg>

    <!-- en_espera — reloj de arena ámbar: no ha salido porque espera a que el cliente abra la
         ventana de WhatsApp. No es un fallo ni un «en camino»: depende de él. -->
    <svg v-else-if="status === 'en_espera'" viewBox="0 0 16 16" width="12" height="12" class="icon-amber">
      <title>Esperando a que conteste para enviarse por WhatsApp</title>
      <path fill="currentColor" d="M3 1h10v1.5h-1v1.8c0 1.3-.7 2.5-1.8 3.2L9.3 8l.9.5c1.1.7 1.8 1.9 1.8 3.2v1.8h1V15H3v-1.5h1v-1.8c0-1.3.7-2.5 1.8-3.2L6.7 8l-.9-.5C4.7 6.8 4 5.6 4 4.3V2.5H3V1zm2.5 1.5v1.8c0 .8.4 1.5 1.1 1.9L8 7l1.4-.8c.7-.4 1.1-1.1 1.1-1.9V2.5h-5zM8 9l-1.4.8c-.7.4-1.1 1.1-1.1 1.9v1.8h5v-1.8c0-.8-.4-1.5-1.1-1.9L8 9z"/>
    </svg>

    <!-- NUEVO: cancelled — círculo con X rojo -->
    <svg v-else-if="status === 'cancelled'" viewBox="0 0 16 16" width="12" height="12" class="icon-red">
      <circle cx="8" cy="8" r="7" fill="none" stroke="currentColor" stroke-width="1.2"/>
      <line x1="5" y1="5" x2="11" y2="11" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
      <line x1="11" y1="5" x2="5" y2="11" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
    </svg>
  </div>
</template>

<script setup lang="ts">
import type { EstadoMensaje } from '@/types/mensajeEstadoModel';

defineProps<{ status: EstadoMensaje }>();
</script>

<style scoped>
.message-status {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-left: 4px;
  vertical-align: bottom;
}

.icon-gray {
  color: #8696a0;
}

.icon-blue {
  color: #53bdeb;
}

.icon-red {
  color: #f15c6d;
}

.icon-amber {
  color: #d97706;
}
</style>