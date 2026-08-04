<script setup lang="ts">
/**
 * Marco de un vídeo o una imagen que todavía no se puede ver.
 *
 * Lo emite el BACKEND: `PmsGuiaInterpolador::resolverMediaVentana()` convierte
 * `{{ video_ventana: url }}` en `{{ videobloqueado: mensaje }}` cuando la
 * ventana está cerrada. La URL original **no llega hasta aquí** — no es que
 * este componente decida no pintarla, es que no la tiene. Esa es toda la
 * diferencia entre ocultar y proteger.
 *
 * Imita la silueta del medio (proporción, icono de play) para que se entienda
 * qué va a aparecer ahí, con el texto de disponibilidad en el centro.
 */
const props = defineProps<{
  /** Mensaje ya traducido y sin corchetes: "Disponible el 12/08/2026 a las 15:00". */
  value?: string;
  /** Clave con la que llegó el bloque; decide silueta e icono. */
  tipo?: string;
}>();

/** Vídeo salvo que sea explícitamente de imagen: la duda cae del lado 16:9. */
const esVideo = (): boolean => !String(props.tipo ?? '').startsWith('img');
</script>

<template>
  <div
      class="relative w-full overflow-hidden rounded-2xl border border-dashed border-slate-300 bg-slate-100 select-none"
      :class="esVideo() ? 'aspect-video' : 'aspect-[4/3]'"
  >
    <!-- Trama diagonal: lee como "hueco reservado" y no como imagen rota. -->
    <div
        class="absolute inset-0 opacity-[0.06]"
        style="background-image: repeating-linear-gradient(45deg, #0f172a 0 10px, transparent 10px 20px);"
    ></div>

    <div class="absolute inset-0 flex flex-col items-center justify-center gap-3 px-6 text-center">
      <span class="w-12 h-12 rounded-full bg-white shadow-sm flex items-center justify-center text-slate-400">
        <i class="fas" :class="esVideo() ? 'fa-play' : 'fa-image'"></i>
      </span>

      <span class="inline-flex items-center gap-2 text-[12px] font-bold text-slate-500 leading-snug">
        <i class="fas fa-lock text-[10px] text-slate-400"></i>
        {{ value }}
      </span>
    </div>
  </div>
</template>
