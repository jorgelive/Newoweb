<script setup lang="ts">
// ============================================================================
// EL CONTACTO DE UN ASUNTO — teléfono y correo, resueltos desde la IDENTIDAD
//
// ── Por qué el campo dejó de ser editable donde estaba ──────────────────────
// El teléfono y el correo que guarda un asunto —una reserva, un expediente, una organización—
// son la SEMILLA con la que se creó la identidad de esa persona. A partir de ahí dejan de ser la
// verdad: el dato bueno vive en las identidades, que es donde se puede corregir, retirar, vetar
// y marcar cuál se usa.
//
// Dejar el `<input>` puesto invitaba a corregir ahí, y eso no arregla nada: el envío sale de la
// identidad, así que el operador cambiaba el número, veía su cambio guardado, y los mensajes
// seguían yendo al viejo. Un dato que se puede editar y no sirve es peor que uno que no se puede
// editar.
//
// ── Vale para los tres dominios ─────────────────────────────────────────────
// Lo resuelve un solo endpoint (`ContactoDelAsuntoController`) a partir de `contextType` +
// `contextId`, así que este componente no sabe qué es una reserva ni un expediente.
// ============================================================================

import { ref, watch, computed } from 'vue';
import { useRouter } from 'vue-router';
import { apiClient } from '@/services/apiClient';
import { useChatStore } from '@/stores/chat/chatStore.ts';
import { uuidDe } from '@/services/hydra';
import { formatearTelefono } from '@/utils/telefono';

const props = defineProps<{
    /** `pms_reserva`, `cotizacion_file`, `travel_organizacion`… Opaco: aquí no se interpreta. */
    contextType: string;
    contextId: string | null | undefined;
    /** Ocultar el correo donde el dominio no lo use. Por defecto se enseñan los dos. */
    conCorreo?: boolean;
    /**
     * La SEMILLA que guarda el asunto, en dos direcciones.
     *
     * ⚠️ **Mientras no haya identidad, el campo se sigue pudiendo escribir**, y esto no es una
     * concesión: es lo que la semilla ES. Un expediente antiguo sin correo —o sin ningún dato de
     * contacto— no tiene hilo, y sin hilo no hay editor de identificadores al que mandar a
     * nadie. Bloquearlo dejaba un callejón sin salida: ni se podía teclear aquí, ni abrir el
     * hilo allí, porque abrirlo exige justamente un dato de contacto.
     *
     * En cuanto el dato pasa a ser una identidad, el `<input>` desaparece: a partir de ahí
     * escribir aquí se guardaría y no cambiaría a dónde sale el mensaje.
     *
     * Quien persiste es el PADRE, con su propio «Guardar»: este componente no sabe qué es un
     * expediente ni una organización.
     */
    telefono?: string | null;
    correo?: string | null;
}>();

const emit = defineEmits<{
    'update:telefono': [string];
    'update:correo': [string];
}>();

interface ContactoResuelto {
    telefono: string | null;
    telefonoOrigen: 'identidad' | 'semilla' | null;
    correo: string | null;
    correoOrigen: 'identidad' | 'semilla' | null;
    conversacionId: string | null;
}

const contacto = ref<ContactoResuelto | null>(null);
const cargando = ref(false);
const abriendo = ref(false);
const error = ref<string | null>(null);

const chatStore = useChatStore();
const router = useRouter();

const muestraCorreo = computed(() => props.conCorreo !== false);

/**
 * ¿Este dato ya lo manda una identidad?
 *
 * Sólo entonces se deja de editar. `semilla` y «no hay nada» son el mismo estado a estos
 * efectos: el asunto todavía es el dueño del dato.
 */
const telefonoEsIdentidad = computed(() => contacto.value?.telefonoOrigen === 'identidad');
const correoEsIdentidad = computed(() => contacto.value?.correoOrigen === 'identidad');

/** ¿El padre nos pasó los modelos? Sin ellos no se puede ofrecer edición de la semilla. */
const editable = computed(() => props.telefono !== undefined || props.correo !== undefined);

// ══ ¿ESTE IDENTIFICADOR YA ES DE ALGUIEN? ══════════════════════════════════
//
// ── Lo que pasa sin este aviso, medido ──────────────────────────────────────
// Teclear un número que ya existe tiene DOS finales y ninguno se ve:
//
//   · el asunto todavía sin hilo → se engancha al hilo de ESA persona, y el expediente pasa a
//     ser un asunto suyo. Correcto si es el cliente que vuelve; invisible si fue un dedazo.
//   · el asunto ya con hilo propio → el identificador **se descarta**, porque `(tipo, valor)` es
//     único y no se le roba a su dueño. Queda un warning en el log y nada en pantalla: el
//     operador guarda, ve el dato en el campo —es la semilla, sigue ahí— y da por hecho que se
//     registró.
//
// Así que se pregunta ANTES de guardar, que es cuando se puede corregir. Mismo recurso que usa
// el alta de reservas (`ReservaEditDrawer`).
const duenioTelefono = ref<{ nombre: string | null } | null>(null);
const duenioCorreo = ref<{ nombre: string | null } | null>(null);

let temporizador: ReturnType<typeof setTimeout> | null = null;

/** Con retardo: se teclea dígito a dígito y no hay que preguntar por cada uno. */
const comprobarDuenios = (): void => {
    if (temporizador) clearTimeout(temporizador);

    temporizador = setTimeout(async () => {
        duenioTelefono.value = telefonoEsIdentidad.value || !props.telefono
            ? null
            : await chatStore.fetchDuenioDeIdentificador('telefono', props.telefono);

        duenioCorreo.value = correoEsIdentidad.value || !props.correo
            ? null
            : await chatStore.fetchDuenioDeIdentificador('email', props.correo);
    }, 400);
};

watch(() => [props.telefono, props.correo], comprobarDuenios);

const cargar = async (): Promise<void> => {
    contacto.value = null;
    error.value = null;

    if (!props.contextId) return;

    cargando.value = true;
    try {
        const { data } = await apiClient.get('/platform/message/contacto', {
            params: { contextType: props.contextType, contextId: props.contextId },
        });
        contacto.value = data as ContactoResuelto;
    } catch {
        error.value = 'No se pudieron cargar los datos de contacto.';
    } finally {
        cargando.value = false;
    }
};

watch(() => [props.contextType, props.contextId], cargar, { immediate: true });

/**
 * Lleva al editor de identificadores, abriendo el hilo si todavía no existe.
 *
 * Sin la apertura, un asunto al que nadie ha escrito no tendría dónde editar: el editor vive en
 * la conversación. Es idempotente, así que llamarlo con hilo ya creado devuelve el que hay.
 */
const editar = async (): Promise<void> => {
    if (!props.contextId) return;

    error.value = null;
    abriendo.value = true;

    try {
        let id = contacto.value?.conversacionId ?? null;

        if (!id) {
            const abierto = await chatStore.abrirConversacion(props.contextType, props.contextId);

            if ('error' in abierto) {
                // El motivo lo redacta el backend —«no hay ni teléfono ni correo registrados»—
                // y aquí se pinta tal cual: sabe mejor que nosotros qué falta.
                error.value = abierto.error;

                return;
            }

            id = uuidDe(abierto.conversacion);
        }

        if (id) await router.push({ path: '/chat', query: { id, editar: 'identidades' } });
    } catch {
        error.value = 'No se pudo abrir el editor de identificadores.';
    } finally {
        abriendo.value = false;
    }
};

defineExpose({ recargar: cargar });
</script>

<template>
    <div class="rounded-xl border border-slate-200 bg-slate-50/60 divide-y divide-slate-100">
        <div class="px-3 py-2.5">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide flex items-center gap-1.5">
                Teléfono
                <!-- ⚠️ «Sin verificar» sólo cuando lo que se ve es la SEMILLA. No se puede
                     deducir comparando valores: lo normal es que coincidan, porque la identidad
                     se sembró de ahí. Lo dice el backend. -->
                <span v-if="contacto?.telefonoOrigen === 'semilla'"
                      title="Es el número con el que se creó el asunto; la persona todavía no tiene identificador propio."
                      class="px-1.5 py-0.5 bg-amber-100 text-amber-700 rounded normal-case tracking-normal">sin verificar</span>
            </p>
            <!-- Con identidad: se enseña. Sin ella el asunto sigue siendo el dueño del dato,
                 así que se escribe aquí — es la semilla, y es de donde nacerá la identidad. -->
            <p v-if="telefonoEsIdentidad || !editable"
               class="text-sm font-bold mt-0.5" :class="contacto?.telefono ? 'text-slate-800' : 'text-slate-300'">
                <i v-if="contacto?.telefono" class="fas fa-phone text-[#376875]/40 text-xs mr-1.5"></i>
                {{ contacto?.telefono ? formatearTelefono(contacto.telefono) : (cargando ? '…' : '—') }}
            </p>
            <template v-else>
                <input :value="telefono ?? ''" @input="emit('update:telefono', ($event.target as HTMLInputElement).value)"
                       type="tel" placeholder="+51 987 654 321"
                       class="mt-1 w-full bg-white border rounded-lg px-3 py-2 text-sm font-bold outline-none focus:ring-2 focus:ring-[#376875]"
                       :class="duenioTelefono ? 'border-amber-300 bg-amber-50/50' : 'border-slate-200 bg-white'" />
                <p v-if="duenioTelefono" class="mt-1 text-[10px] font-bold text-amber-700 leading-snug">
                    <i class="fas fa-triangle-exclamation mr-1"></i>
                    Este número ya es de <strong>{{ duenioTelefono.nombre || 'otra persona' }}</strong>.
                    Si es la misma, este asunto pasará a su conversación; si no, no se guardará como identificador.
                </p>
            </template>
        </div>

        <div v-if="muestraCorreo" class="px-3 py-2.5">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-wide flex items-center gap-1.5">
                Correo
                <span v-if="contacto?.correoOrigen === 'semilla'"
                      title="Es el correo con el que se creó el asunto; la persona todavía no tiene identificador propio."
                      class="px-1.5 py-0.5 bg-amber-100 text-amber-700 rounded normal-case tracking-normal">sin verificar</span>
            </p>
            <p v-if="correoEsIdentidad || !editable"
               class="text-sm font-bold mt-0.5 break-all" :class="contacto?.correo ? 'text-slate-800' : 'text-slate-300'">
                <i v-if="contacto?.correo" class="fas fa-envelope text-[#376875]/40 text-xs mr-1.5"></i>
                {{ contacto?.correo ?? (cargando ? '…' : '—') }}
            </p>
            <template v-else>
                <input :value="correo ?? ''" @input="emit('update:correo', ($event.target as HTMLInputElement).value)"
                       type="email" placeholder="cliente@ejemplo.com"
                       class="mt-1 w-full border rounded-lg px-3 py-2 text-sm font-bold outline-none focus:ring-2 focus:ring-[#376875]"
                       :class="duenioCorreo ? 'border-amber-300 bg-amber-50/50' : 'border-slate-200 bg-white'" />
                <p v-if="duenioCorreo" class="mt-1 text-[10px] font-bold text-amber-700 leading-snug">
                    <i class="fas fa-triangle-exclamation mr-1"></i>
                    Este correo ya es de <strong>{{ duenioCorreo.nombre || 'otra persona' }}</strong>.
                    Si es la misma, este asunto pasará a su conversación; si no, no se guardará como identificador.
                </p>
            </template>
        </div>

        <div class="px-3 py-2 flex items-center gap-2 flex-wrap">
            <button type="button" @click="editar" :disabled="abriendo || !contextId"
                    title="Editar los identificadores de esta persona: añadir, retirar, marcar cuál se usa"
                    class="inline-flex items-center gap-1 px-2 py-1 bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-200 rounded-lg text-[10px] font-black uppercase tracking-wide transition-colors disabled:opacity-40">
                <i class="fas" :class="abriendo ? 'fa-circle-notch fa-spin' : 'fa-pen'"></i> Editar
            </button>
            <p class="text-[10px] text-slate-400 leading-snug">
                <template v-if="telefonoEsIdentidad && (correoEsIdentidad || !muestraCorreo)">
                    Se edita en los identificadores de la persona, no aquí.
                </template>
                <template v-else>
                    Lo que escribas aquí se guarda con el asunto y creará el identificador; a
                    partir de ahí se edita en los identificadores.
                </template>
            </p>
        </div>

        <p v-if="error" class="px-3 py-2 text-[11px] font-bold text-rose-600 leading-snug">
            <i class="fas fa-triangle-exclamation mr-1"></i>{{ error }}
        </p>
    </div>
</template>
