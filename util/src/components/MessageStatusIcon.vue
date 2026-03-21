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
  </div>
</template>

<script setup>
import { defineProps } from 'vue';

const props = defineProps({
  status: {
    type: String,
    required: true,
    validator(value) {
      // Estos deben coincidir con tus constantes Message::STATUS_* del backend
      return ['pending', 'queued', 'sent', 'delivered', 'read', 'failed', 'received'].includes(value);
    }
  }
});
</script>

<style scoped>
.message-status {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-left: 4px;
  vertical-align: bottom;
}

/* Colores estilo WhatsApp */
.icon-gray {
  color: #8696a0; /* Gris sutil de WhatsApp para check no leído */
}

.icon-blue {
  color: #53bdeb; /* Celeste clásico de los checks leídos */
}

.icon-red {
  color: #f15c6d; /* Rojo para mensajes que fallaron */
}
</style>