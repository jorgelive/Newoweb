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
 *
 * ⚠️ **Y las tres se dicen IGUAL, en lista.** La primera versión las metía en una frase corrida
 * con tres sujetos distintos —«tu sesión se salta», «ve propuestas», «no te pide»— y dos
 * polaridades mezcladas: una en positivo y dos en negativo. Se leía dos veces y seguía sin
 * entenderse. Ahora las tres son **cosas que ves de más**, con la misma forma y el mismo signo:
 * enumerar es gratis y leerlas de un vistazo es el único trabajo que este cartel tiene que hacer.
 */
</script>

<template>
  <div class="mb-6 rounded-2xl border-2 border-dashed border-amber-300 bg-amber-50 px-4 py-3">
    <p class="flex items-center gap-2 text-[11px] font-black uppercase tracking-widest text-amber-700">
      <i class="fas fa-user-shield"></i>
      Lo estás viendo como operador
    </p>

    <p class="mt-1.5 text-[11px] font-bold text-amber-900">Ves tres cosas de más:</p>

    <!-- ⚠️ Las tres empiezan por «las» / «la» / «el» y son COSAS QUE VES. Antes eran una frase
         corrida con tres sujetos distintos —«tu sesión se salta», «ve propuestas», «no te pide»—
         y dos polaridades mezcladas: se leía dos veces y aun así no se entendía. -->
    <ul class="mt-1 space-y-0.5 text-[11px] leading-snug text-amber-900">
      <li class="flex gap-1.5">
        <span class="text-amber-500">·</span>
        <span>las propuestas <strong>sin publicar</strong></span>
      </li>
      <li class="flex gap-1.5">
        <span class="text-amber-500">·</span>
        <span>la operativa <strong>sin que te pida el documento</strong></span>
      </li>
      <li class="flex gap-1.5">
        <span class="text-amber-500">·</span>
        <span>el viaje <strong>entero</strong>, no sólo lo de una persona</span>
      </li>
    </ul>

    <p class="mt-1.5 text-[11px] leading-snug text-amber-900/80">
      Para verlo como lo ve un pasajero, abre el enlace en una ventana de incógnito.
    </p>
  </div>
</template>
