<script setup lang="ts">
/*
 src/views/HomeView.vue
*/

const apiUrl = window.UMTIDO_CONFIG?.apiUrl || 'Esperando config...'

</script>
<template>
  <div style="display: flex; justify-content: center; align-items: center; height: 100vh; flex-direction: column;">
    <h1 style="color: #6366f1; font-size: 3rem;">¡Pax App Activa! 🚀</h1>
    <p>Si ves esto, el Build de producción funcionó.</p>

    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); margin-top: 20px;">
      <p><strong>API:</strong> {{ apiUrl }}</p>
    </div>
  </div>
</template>