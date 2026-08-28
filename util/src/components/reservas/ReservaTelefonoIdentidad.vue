<script setup lang="ts">
// ============================================================================
// El teléfono de contacto de una reserva, con sus acciones.
//
// Componente propio y no dos copias del mismo marcado en `ReservaEditDrawer.vue`: se pinta
// en los DOS modos del drawer —«Ver» y «Editar»— y son la misma información, porque el
// teléfono no es un dato de la reserva sino de la PERSONA.
//
// Estuvo sólo en el modo Ver, y en Editar quedaba una nota diciendo dónde mirarlo. Era un
// error de lectura del cambio: al mover el teléfono a las identidades se quitó su campo
// editable —correcto, editarlo aquí no cambiaría a dónde salen los mensajes— pero con él se
// fue también el número y el botón de vCard, que no eran edición sino consulta. Quien está
// editando una reserva sigue necesitando ver a qué número se le escribe.
//
// ⚠️ El número NO se resuelve aquí ni se pasa desde el formulario: lo da el backend
// (`TelefonoDeContacto`), que es quien sabe cuál de las identidades vale y si está vetada o
// retirada. Ver docs/Telefonos.md.
// ============================================================================
import { formatearTelefono } from '@/utils/telefono';

defineProps<{
    /** El número resuelto, ya elegido por el backend. `null` mientras carga o si no hay. */
    telefono: string | null;
    /**
     * De dónde salió: de una identidad de la persona, o la SEMILLA con la que se creó la
     * reserva. La semilla puede estar desfasada y por eso se marca — no es un aviso de error,
     * es que ese número todavía no lo ha confirmado nadie.
     */
    origen: 'identidad' | 'semilla' | null;
    /** Descarga del contacto. `null` mientras no haya reserva persistida. */
    vcardUrl: string | null;
    /** El botón de editar lleva al chat, y eso tarda: se bloquea mientras abre. */
    ocupado?: boolean;
    error?: string | null;
}>();

defineEmits<{ (e: 'editar'): void }>();
</script>

<template>
    <div>
        <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide flex items-center gap-1.5">
            Teléfono
            <span v-if="origen === 'semilla'"
                title="Es el número con el que se creó la reserva; la persona todavía no tiene identificador propio."
                class="px-1.5 py-0.5 bg-amber-100 text-amber-700 rounded normal-case tracking-normal">sin verificar</span>
        </p>
        <div class="flex items-center gap-2 mt-0.5 flex-wrap">
            <p class="text-sm font-bold text-slate-800">{{ formatearTelefono(telefono) || '—' }}</p>

            <button type="button" @click="$emit('editar')" :disabled="ocupado"
                title="Editar los identificadores de esta persona: añadir, retirar, marcar cuál se usa"
                class="inline-flex items-center gap-1 px-2 py-1 bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-200 rounded-lg text-[10px] font-black uppercase tracking-wide transition-colors shrink-0 disabled:opacity-40">
                <i class="fas" :class="ocupado ? 'fa-circle-notch fa-spin' : 'fa-pen'"></i> Editar
            </button>

            <a v-if="vcardUrl && telefono" :href="vcardUrl" target="_blank" title="Descargar contacto (vCard)"
                class="inline-flex items-center gap-1 px-2 py-1 bg-indigo-100 hover:bg-indigo-200 text-indigo-700 border border-indigo-200 rounded-lg text-[10px] font-black uppercase tracking-wide transition-colors shrink-0">
                <i class="fas fa-address-card"></i> vCard
            </a>
        </div>

        <!-- Aquí y no arriba del scroll: el error nace de pulsar «Editar», y puesto en la
             cabecera del drawer quedaba fuera de pantalla justo cuando se necesita leerlo. -->
        <p v-if="error" class="text-[11px] font-bold text-rose-600 mt-1.5 leading-snug">
            <i class="fas fa-exclamation-triangle mr-1"></i>{{ error }}
        </p>
    </div>
</template>
