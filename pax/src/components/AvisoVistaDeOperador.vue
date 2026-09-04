<script setup lang="ts">
/**
 * El cartel de «esto lo estás viendo como operador».
 *
 * 🔥 **Nace de un falso fallo.** Con la operativa publicada, el operador abría `pax` y no le pedía
 * el documento: veía el expediente entero y concluía que la puerta estaba abierta para las 133
 * familias. No lo estaba —desde fuera la API contesta `403 IDENTIFICACION_REQUERIDA`—, pero nada
 * en pantalla lo decía.
 *
 * `util` y `pax` comparten dominio de cookie a propósito, así que la sesión del operador llega a
 * la API y se salta TRES puertas a la vez: `publicado`, la identificación y el filtrado por
 * persona. Es justo la familia de fallo que este proyecto persigue: el que no da error.
 *
 * ⚠️ **Dice las tres, no «vista previa» a secas.** El operador no necesita saber que previsualiza
 * —eso ya lo sabe—: necesita saber QUÉ está viendo de más, porque cada una engaña distinto. Que
 * ve borradores sin publicar, que no le piden documento y que no está filtrado a una persona.
 */
</script>

<template>
  <div class="mb-6 rounded-2xl border-2 border-dashed border-amber-300 bg-amber-50 px-4 py-3">
    <p class="flex items-center gap-2 text-[11px] font-black uppercase tracking-widest text-amber-700">
      <i class="fas fa-user-shield"></i>
      Lo estás viendo como operador
    </p>
    <p class="mt-1 text-[11px] leading-snug text-amber-900">
      Tu sesión se salta tres cosas que el cliente sí encuentra:
      <strong>ve propuestas sin publicar</strong>,
      <strong>no te pide documento</strong> en la operativa y
      <strong>no filtra a una persona</strong> — un pasajero sólo vería lo suyo.
      Para comprobarlo como cliente, abre el enlace en una ventana de incógnito.
    </p>
  </div>
</template>
