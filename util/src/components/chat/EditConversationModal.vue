<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue';
import { RouterLink } from 'vue-router';
import { useDuenioDeIdentificador, type TipoIdentificador } from '@/composables/useDuenioDeIdentificador';
import { useChatStore, type ApiConversation } from '@/stores/chat/chatStore.ts';
import { useMaestroStore } from '@/stores/maestroStore';
import { uuidDe } from '@/services/hydra';
import InfoTooltip from '@/components/common/InfoTooltip.vue';

const props = defineProps<{ conversation: ApiConversation }>();
const emit = defineEmits<{ close: [] }>();

const store = useChatStore();
const maestroStore = useMaestroStore();

const saving = ref(false);
const deleting = ref(false);
const errorMsg = ref('');

// ══ IDENTIFICADORES ════════════════════════════════════════════════════════
// Son de la PERSONA, no de la reserva: la misma gente vuelve, y repetirlos por reserva se
// contradice solo. El backend los normaliza y valida —aquí no se toca el valor— porque la
// normalización tiene que ser LA MISMA que la de la resolución de hilos, o se parten en dos.

// El tipo cerrado y no `string`: el composable y el backend sólo entienden estos dos, y dejarlo
// abierto convertía una errata en una consulta que siempre contesta «no es de nadie».
const nuevoTipo = ref<TipoIdentificador>('telefono');
const nuevoValor = ref('');
const errorIdent = ref('');
const ocupado = ref(false);

/**
 * Cuántos mensajes tiene el hilo, y su id para enlazarlo.
 *
 * Lo sirve el backend en `conversation:read` ({@see MessageConversation::getTotalMensajes()}).
 * Con `0` no se ofrece enlace: llevar a un chat vacío no aclara nada, y decirlo con palabras sí.
 */
const totalMensajes = computed(() =>
  Number((hilo.value as unknown as { totalMensajes?: number }).totalMensajes ?? 0));

const idDelHilo = computed(() => uuidDe(hilo.value) ?? '');

/**
 * ¿El identificador que se está tecleando ya es de otro hilo?
 *
 * `yaTieneHilo` es SIEMPRE `true` aquí: estamos dentro de una conversación, así que el desenlace
 * no es la unión sino el rechazo —un identificador no se le quita a su dueño— y la salida es
 * fusionar. El composable redacta esa versión de la frase.
 */
const duenioNuevo = useDuenioDeIdentificador(() => true);

const previaFusion = ref<Awaited<ReturnType<typeof duenioNuevo.previaDeFusion>>>(null);
const fusionando = ref(false);

const pedirPreviaFusion = async (): Promise<void> => {
  fusionando.value = true;
  previaFusion.value = await duenioNuevo.previaDeFusion(conversationUuid.value ?? '');
  fusionando.value = false;

  if (!previaFusion.value) errorIdent.value = 'No se pudo preparar la fusión.';
};

const aplicarFusion = async (): Promise<void> => {
  fusionando.value = true;
  const fallo = await duenioNuevo.fusionar(conversationUuid.value ?? '');
  fusionando.value = false;

  if (fallo) { errorIdent.value = fallo; return; }

  // El identificador que provocó el choque ya es de este hilo: se limpia el formulario y se
  // relee, porque la lista de identidades acaba de cambiar.
  previaFusion.value = null;
  nuevoValor.value = '';
  duenioNuevo.limpiar();
  await refrescarHilo();
};

/** Vienen serializadas con la conversación (`conversation:read`). */
interface IdentidadDelPanel {
  id: string;
  tipo: string;
  valor: string;
  principal: boolean;
  bloqueado: boolean;
  bloqueadoMotivo: string | null;
  retirada: boolean;
  /** Alias que emitió una OTA para UNA reserva: no puede ser la salida por defecto de todo. */
  deLaPlataforma: boolean;
}

/**
 * Los alias de plataforma del hilo, en minúsculas para comparar.
 *
 * 🪞 Espejo de `AliasDePlataforma`, que es quien de verdad lo impide —y quien además los aparta
 * del destino de los envíos—. Si la regla cambia allí, cambia aquí: esto sólo evita ofrecer un
 * botón que va a responder 409.
 *
 * Mientras `fetchAsuntos` no haya respondido esto está vacío y la estrella se ofrece; el 409 la
 * frena. Es una degradación aceptable y no un caso que valga la pena bloquear con un spinner.
 */
const aliasDePlataforma = computed<Set<string>>(() => new Set(
  store.asuntosDelChat
    .map(a => a.correoExclusivo?.trim().toLowerCase())
    .filter((c): c is string => !!c)
));

/**
 * Copia local del hilo, y **no se lee del prop a secas**.
 *
 * ⚠️ Dentro del chat, tras tocar una identidad el store refresca `currentConversation` y el prop
 * llega nuevo. Fuera del chat no hay tal cosa: quien abre este modal desde el expediente le pasa
 * un objeto suelto, y sin esta copia la lista se quedaba mostrando el estado anterior — el
 * operador retiraba un número, no veía el cambio, y lo retiraba otra vez.
 */
const hilo = ref<ApiConversation>(props.conversation);

watch(() => props.conversation, (v) => { hilo.value = v; });

/** Relee la cabecera —sin el historial— y repinta. */
const refrescarHilo = async (): Promise<void> => {
  const id = conversationUuid.value;

  if (!id) return;

  const fresco = await store.cargarCabecera(id);

  if (fresco) hilo.value = fresco;
};

const identidades = computed<IdentidadDelPanel[]>(() => {
  const filas = (hilo.value as unknown as { identidades?: unknown[] }).identidades ?? [];

  return filas.map(f => {
    const i = f as Record<string, unknown>;

    return {
      id: uuidDe(i) ?? '',
      tipo: String(i.tipo ?? 'telefono'),
      valor: String(i.valor ?? ''),
      principal: i.principal === true,
      bloqueado: i.bloqueado === true,
      bloqueadoMotivo: typeof i.bloqueadoMotivo === 'string' ? i.bloqueadoMotivo : null,
      // `retiradoEn` viaja en `conversation:read`: una retirada se pinta tachada y sin
      // acciones, no desaparece. Que siga a la vista es el punto — sigue resolviendo el
      // historial, y esconderla haría creer que se borró.
      retirada: i.retiradoEn != null,
      deLaPlataforma: aliasDePlataforma.value.has(String(i.valor ?? '').trim().toLowerCase()),
    };
  });
});

/**
 * ¿Hay algún teléfono vetado y vivo?
 *
 * Es lo que decide si se enseña la ayuda de «cómo se corrige un número malo». Sólo cuando hay
 * uno: el procedimiento no interesa hasta que hace falta, y una franja de ayuda permanente es
 * ruido en el 95 % de las aperturas del panel.
 */
const hayVetados = computed(() => identidades.value.some(i => i.tipo === 'telefono' && i.bloqueado));

/**
 * El teléfono del hilo cuando NO figura entre sus identidades.
 *
 * `guestPhone` es la copia denormalizada por la que salen los envíos. Lo normal es que sea el de
 * la identidad principal; cuando no lo es, hay un número operando fuera de la lista y no se
 * puede ni vetar ni retirar. `null` en el caso normal, para no repetir el dato en pantalla.
 */
const telefonoHuerfano = computed<string | null>(() => {
  const actual = (props.conversation.guestPhone ?? '').replace(/\D/g, '');
  if (!actual) return null;

  const registrado = identidades.value.some(i => i.tipo === 'telefono' && i.valor.replace(/\D/g, '') === actual);

  return registrado ? null : props.conversation.guestPhone ?? null;
});

const anadir = async () => {
  if (!nuevoValor.value.trim()) return;

  ocupado.value = true;
  // ⚠️ Con el id EXPLÍCITO: este modal se abre también fuera del chat —desde el expediente o la
  // reserva—, y ahí `currentConversation` no es ésta (o no hay ninguna).
  errorIdent.value = await store.anadirIdentidad(nuevoTipo.value, nuevoValor.value, conversationUuid.value ?? undefined) ?? '';
  await refrescarHilo();
  ocupado.value = false;

  if (!errorIdent.value) nuevoValor.value = '';
};

const cambiar = async (id: string, cambios: { principal?: boolean; bloqueado?: boolean; retirada?: boolean }) => {
  ocupado.value = true;
  errorIdent.value = await store.cambiarIdentidad(id, cambios, conversationUuid.value ?? undefined) ?? '';
  await refrescarHilo();
  ocupado.value = false;
};

/**
 * Retirar NO borra: el identificador sigue resolviendo el historial y deja de ser salida.
 * Se confirma porque afecta a TODOS los asuntos de esa persona, no sólo a la reserva desde la
 * que se abrió el chat.
 */
const retirar = async (ident: IdentidadDelPanel) => {
  const aviso = `¿Retirar ${ident.valor}?\n\nDeja de usarse para escribir a esta persona en TODOS sus asuntos. `
    + 'Los mensajes que lleguen desde ese número seguirán entrando en este hilo.';

  if (!window.confirm(aviso)) return;

  await cambiar(ident.id, { retirada: true });
};

const STATUS_OPTIONS = [
  { value: 'open', label: 'Abierta' },
  { value: 'closed', label: 'Cerrada' },
  { value: 'archived', label: 'Archivada' }
];

const form = ref({
  status: 'open',
  guestName: '',
  idiomaId: '',
  idiomaFijado: false,
  whatsappDisabled: false,
  whatsappDisabledReason: ''
});

const resetForm = () => {
  const c = props.conversation;
  const idiomaRef = c.idioma;
  form.value = {
    status: c.status || 'open',
    guestName: c.guestName || '',
    idiomaId: idiomaRef ? idiomaRef.split('/').pop() || '' : '',
    idiomaFijado: !!c.idiomaFijado,
    whatsappDisabled: !!c.whatsappDisabled,
    whatsappDisabledReason: c.whatsappDisabledReason || ''
  };
};

onMounted(() => {
  maestroStore.fetchMaestros();
  resetForm();
});

watch(() => props.conversation, resetForm);

const conversationUuid = computed(() => {
  return uuidDe(props.conversation);
});

const handleSave = async () => {
  if (!conversationUuid.value) return;
  saving.value = true;
  errorMsg.value = '';

  const idiomaObj = maestroStore.idiomas.find((i) => i.id === form.value.idiomaId);

  const payload: Record<string, unknown> = {
    status: form.value.status,
    guestName: form.value.guestName.trim() || null,
    idiomaFijado: form.value.idiomaFijado,
    whatsappDisabled: form.value.whatsappDisabled,
    whatsappDisabledReason: form.value.whatsappDisabled ? (form.value.whatsappDisabledReason.trim() || null) : null
  };
  if (idiomaObj) payload.idioma = idiomaObj['@id'];

  const ok = await store.updateConversation(conversationUuid.value, payload);
  saving.value = false;

  if (ok) emit('close');
  else errorMsg.value = 'No se pudo guardar. Intenta de nuevo.';
};

const handleDelete = async () => {
  if (!conversationUuid.value) return;
  const guest = props.conversation.guestName || 'este huésped';
  if (!confirm(`¿Eliminar la conversación con ${guest}? Se borrarán también todos sus mensajes. Esta acción no se puede deshacer.`)) return;

  deleting.value = true;
  errorMsg.value = '';

  const ok = await store.deleteConversation(conversationUuid.value);
  deleting.value = false;

  if (ok) emit('close');
  else errorMsg.value = 'No se pudo eliminar. Intenta de nuevo.';
};

const formatDateTime = (iso?: string | null) => {
  if (!iso) return '—';
  return new Date(iso).toLocaleString('es-ES', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};
</script>

<template>
  <div class="fixed inset-0 z-[200] bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4" @click.self="emit('close')">
    <div class="bg-white w-full max-w-lg rounded-3xl shadow-2xl overflow-hidden max-h-[90vh] flex flex-col">
      <header class="bg-slate-900 text-white px-6 py-4 flex justify-between items-center shrink-0">
        <h2 class="font-black text-base"><i class="fas fa-pen mr-2 text-[#E07845]"></i> Editar Conversación</h2>
        <button @click="emit('close')" class="w-8 h-8 rounded-full bg-slate-800 hover:bg-slate-700 flex items-center justify-center transition-colors">
          <i class="fas fa-times"></i>
        </button>
      </header>

      <div class="p-6 space-y-6 overflow-y-auto min-h-0">
        <!-- Formulario editable -->
        <div class="space-y-4">
          <div>
            <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Estado</label>
            <select v-model="form.status"
                    class="w-full bg-white border border-slate-300 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:ring-2 focus:ring-[#376875] shadow-sm">
              <option v-for="opt in STATUS_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>
          </div>

          <div>
            <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Nombre del Huésped</label>
            <input v-model="form.guestName" type="text" placeholder="Sin nombre"
                   class="w-full bg-white border border-slate-300 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:ring-2 focus:ring-[#376875] shadow-sm">
          </div>

          <!-- ⚠️ El campo «Teléfono» YA NO SE EDITA AQUÍ.
               `guestPhone` es la copia denormalizada de la identidad PRINCIPAL —lo recalcula
               `recalcularTelefonoPrincipal()` en cuanto se toca una identidad—, así que
               editarlo a mano duraba hasta el siguiente cambio y de paso se veía dos veces en
               la misma pantalla: aquí y en la lista de abajo con su insignia. Se edita donde
               vive la verdad, en «Identificadores». -->

          <div>
            <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Idioma</label>
            <div class="flex items-center gap-3">
              <select v-model="form.idiomaId"
                      class="flex-1 bg-white border border-slate-300 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:ring-2 focus:ring-[#376875] shadow-sm">
                <option v-for="idioma in maestroStore.idiomas" :key="idioma.id" :value="idioma.id">
                  {{ idioma.bandera }} {{ idioma.nombre }}
                </option>
              </select>
              <label class="flex items-center gap-2 text-[10px] font-black text-slate-500 uppercase whitespace-nowrap">
                <input v-model="form.idiomaFijado" type="checkbox" class="rounded border-slate-300">
                Fijado
              </label>
            </div>
          </div>

          <div>
            <label class="flex items-center gap-2 text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">
              <input v-model="form.whatsappDisabled" type="checkbox" class="rounded border-slate-300">
              WhatsApp deshabilitado
            </label>
            <input v-if="form.whatsappDisabled" v-model="form.whatsappDisabledReason" type="text" placeholder="Motivo"
                   class="w-full bg-white border border-slate-300 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:ring-2 focus:ring-[#376875] shadow-sm">
          </div>

          <!-- ══ IDENTIFICADORES DE LA PERSONA ══════════════════════════════
               Por dónde se le reconoce y por dónde se le escribe. Vive aquí y no en la
               reserva porque son de la PERSONA: la misma gente vuelve, y repetirlos por
               reserva se contradice solo. Ver docs/Mensajeria.md §24. -->
          <div class="pt-4 border-t border-slate-200">
            <div class="flex items-center justify-between mb-2">
              <div class="flex items-center gap-1.5">
                <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Identificadores</h3>

                <!-- La ayuda aparece SÓLO con algún teléfono vetado, que es cuando hay algo que
                     hacer. El procedimiento no es evidente —lo natural es buscar un lápiz que no
                     existe— y equivocarse tiene dos formas de salir mal, las dos silenciosas:
                     desmarcar la casilla revive el número muerto, y retirar sin añadir cae a la
                     semilla, que suele ser el mismo número malo. -->
                <InfoTooltip v-if="hayVetados" lado="derecha">
                  <b class="text-white">Un número vetado no se edita: se sustituye.</b>
                  El valor lo sembró el canal (Booking, Airbnb) y el siguiente pull lo
                  reescribiría, así que no hay lápiz — por eso existe la lápida de «retirado».
                  <br><br>
                  <b class="text-white">1.</b> Añade el número bueno abajo, en
                  «Añadir identificador».<br>
                  <b class="text-white">2.</b> Márcalo principal con la ⭐.<br>
                  <b class="text-white">3.</b> Retira el malo con la ✕.
                  <br><br>
                  La casilla de <b class="text-white">WhatsApp deshabilitado</b> se levanta sola
                  al quedar un teléfono vivo sin vetar.
                  <b class="text-white">No la desmarques a mano</b>: eso levanta el veto de
                  todos, incluido el que no funciona.
                  <br><br>
                  ⚠️ <b class="text-white">Añade antes de retirar.</b> Si sólo retiras el malo, el
                  sistema cae al teléfono que vino en la reserva — que normalmente es ese mismo
                  número malo.
                  <br><br>
                  Los mensajes ya programados <b class="text-white">sí saldrán al nuevo</b>: el
                  destino guardado en la cola es sólo un respaldo y se reescribe al enviar.
                </InfoTooltip>
              </div>
              <span class="text-[9px] font-bold text-slate-300 uppercase">de la persona, no de la reserva</span>
            </div>

            <!-- 🔥 **CUÁNTOS MENSAJES LLEVA ESTE HILO.**
                 Un identificador retirado se conserva por dos motivos, y el primero —«quien
                 escriba desde el número viejo tiene que seguir cayendo en su hilo»— **sólo existe
                 si alguien escribió**. Sin este dato se decide a ciegas: un número de prueba que
                 no ha tocado nadie y uno con 247 mensajes se ven exactamente igual en esta lista,
                 y piden decisiones opuestas — uno se puede descartar, el otro no se toca jamás.
                 ⚠️ «Sin mensajes» se dice, no se calla: un hueco se lee como «no lo he mirado». -->
            <div class="mb-2">
              <RouterLink v-if="totalMensajes > 0" :to="{ name: 'chat_conversation', params: { conversationId: idDelHilo } }"
                          class="inline-flex items-center gap-1.5 text-[11px] font-black text-[#376875] hover:underline">
                <i class="fas fa-comments text-[10px]"></i>
                Ver la conversación · {{ totalMensajes }} {{ totalMensajes === 1 ? 'mensaje' : 'mensajes' }}
              </RouterLink>
              <span v-else class="inline-flex items-center gap-1.5 text-[11px] font-bold text-slate-400">
                <i class="fas fa-comment-slash text-[10px]"></i>
                Sin mensajes: nadie ha escrito por ninguno de estos identificadores
              </span>
            </div>

            <p v-if="!identidades.length" class="text-xs text-slate-400 font-bold mb-2">Ninguno todavía.</p>

            <!-- El hilo tiene teléfono pero NADIE lo reclama como identidad: pasa en los hilos
                 anteriores a esta tabla. Se avisa porque es justo el número por el que se le
                 escribe hoy y no se puede ni retirar ni vetar hasta registrarlo. -->
            <p v-if="telefonoHuerfano" class="text-[11px] font-bold text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mb-2 leading-snug">
              <i class="fas fa-triangle-exclamation mr-1"></i>
              Se le escribe a <strong>{{ telefonoHuerfano }}</strong>, que no está en esta lista.
              Añádelo para poder marcarlo, vetarlo o retirarlo.
            </p>

            <ul class="flex flex-col gap-1.5 mb-3">
              <li v-for="ident in identidades" :key="ident.id"
                  class="flex items-center gap-2 bg-white border border-slate-200 rounded-xl px-3 py-2">
                <i class="text-slate-400 text-[11px] w-3.5 text-center"
                   :class="ident.tipo === 'email' ? 'fas fa-envelope' : ident.tipo === 'beds24' ? 'fas fa-bed' : 'fas fa-mobile-screen'"></i>

                <span class="flex-1 text-xs font-bold truncate"
                      :class="ident.retirada ? 'text-slate-300 line-through' : 'text-slate-700'">{{ ident.valor }}</span>

                <span v-if="ident.principal" class="text-[8px] font-black text-[#376875] bg-[#376875]/10 px-1.5 py-0.5 rounded uppercase">Principal</span>
                <span v-if="ident.bloqueado" :title="ident.bloqueadoMotivo || 'Bloqueado'"
                      class="text-[8px] font-black text-red-600 bg-red-50 px-1.5 py-0.5 rounded uppercase">Vetado</span>

                <!-- ⚠️ Beds24 no lleva acciones, y no es un olvido: un `bookId` es la
                     dirección de UNA ESTANCIA en un canal, no un punto de contacto de la
                     persona. No se le puede marcar como salida por defecto, ni vetarlo —Meta
                     no lo rechaza—, ni retirarlo: o el booking existe o no existe. Se enseña
                     porque es lo único que ancla a los huéspedes de OTA que llegan sin
                     teléfono ni correo. Ver docs/Mensajeria.md §24. -->
                <span v-if="ident.tipo === 'beds24'" class="text-[8px] font-black text-slate-300 uppercase tracking-wide pr-1"
                      title="Dirección de la estancia en Beds24. No es un contacto de la persona.">estancia</span>

                <!-- El alias de una OTA tampoco es un contacto de la persona: Booking emite
                     uno POR RESERVA y sólo vale para ésa. Se enseña —hace falta para que su
                     correo entrante caiga en este hilo— y **sin acciones**, como Beds24:
                     marcarlo como salida por defecto mandaría la reserva de mañana al buzón de
                     la de ayer, y retirarlo dejaría de resolver el hilo cuando Booking escriba.
                     El backend sólo impide lo primero; esto es más estricto a propósito. -->
                <span v-else-if="ident.deLaPlataforma" class="text-[8px] font-black text-slate-300 uppercase tracking-wide pr-1"
                      title="Alias que emite la plataforma para una reserva concreta. Sólo se usa para ese asunto.">plataforma</span>

                <template v-else-if="!ident.retirada">
                  <button v-if="!ident.principal" @click="cambiar(ident.id, { principal: true })" :disabled="ocupado"
                          title="Marcar como salida por defecto"
                          class="w-6 h-6 rounded-lg text-slate-300 hover:text-[#376875] hover:bg-slate-100 disabled:opacity-40">
                    <i class="fas fa-star text-[10px]"></i>
                  </button>
                  <button @click="cambiar(ident.id, { bloqueado: !ident.bloqueado })" :disabled="ocupado"
                          :title="ident.bloqueado ? 'Levantar el veto de este número' : 'Vetar sólo este número'"
                          class="w-6 h-6 rounded-lg hover:bg-slate-100 disabled:opacity-40"
                          :class="ident.bloqueado ? 'text-red-500 hover:text-green-600' : 'text-slate-300 hover:text-red-500'">
                    <i class="fas text-[10px]" :class="ident.bloqueado ? 'fa-circle-check' : 'fa-ban'"></i>
                  </button>
                  <button @click="retirar(ident)" :disabled="ocupado"
                          title="Retirar: deja de ser salida, pero sigue resolviendo el historial"
                          class="w-6 h-6 rounded-lg text-slate-300 hover:text-red-500 hover:bg-slate-100 disabled:opacity-40">
                    <i class="fas fa-xmark text-[11px]"></i>
                  </button>
                </template>
                <span v-else class="w-6 h-6 flex items-center justify-center text-slate-200"
                      title="Retirada. Para reactivarla, vuelve a añadir el mismo valor.">
                  <i class="fas fa-clock-rotate-left text-[10px]"></i>
                </span>
              </li>
            </ul>

            <div class="flex gap-1.5">
              <select v-model="nuevoTipo"
                      class="bg-white border border-slate-300 rounded-xl px-2 py-2 text-xs font-bold outline-none focus:ring-2 focus:ring-[#376875]">
                <option value="telefono">Teléfono</option>
                <option value="email">Correo</option>
              </select>
              <input v-model="nuevoValor" type="text" placeholder="Añadir identificador" @keyup.enter="anadir"
                     @input="duenioNuevo.comprobar(nuevoTipo, nuevoValor)"
                     class="flex-1 min-w-0 bg-white border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold outline-none focus:ring-2 focus:ring-[#376875]">
              <button @click="anadir" :disabled="ocupado || !nuevoValor.trim()"
                      class="px-3 py-2 bg-[#376875] text-white rounded-xl text-xs font-black disabled:opacity-40">
                <i class="fas fa-plus"></i>
              </button>
            </div>

            <!-- 🔥 **El aviso, MIENTRAS se teclea.** Antes esto sólo se sabía al pulsar «+», y en
                 forma de error rojo: se descubría después de decidir. Y aquí el desenlace es el
                 duro —«no se guardará»— porque este hilo ya existe y un identificador no se le
                 quita a su dueño: la salida es fusionar. Decirlo antes convierte un error en una
                 decisión informada. -->
            <div v-if="duenioNuevo.aviso.value" class="text-[11px] font-bold text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-2.5 py-1.5 mt-2 leading-snug">
              <p>
                <i class="fas fa-circle-info mr-1"></i>{{ duenioNuevo.aviso.value }}
                <RouterLink v-if="duenioNuevo.duenio.value"
                            :to="{ name: 'chat_conversation', params: { conversationId: duenioNuevo.duenio.value.conversacionId } }"
                            class="underline hover:no-underline">Ver ese hilo</RouterLink>
              </p>

              <!-- 🔥 **La salida que el sistema recomienda, con un botón.** El mensaje decía «hay
                   que fusionar» y fusionar sólo existía en la consola: quien se topa con esto es
                   un operador en una pantalla, así que en la práctica la salida recomendada no
                   existía y se acababa borrando algo. -->
              <button v-if="!previaFusion" type="button" @click="pedirPreviaFusion" :disabled="fusionando"
                      class="mt-1.5 px-2 py-1 bg-amber-600 hover:bg-amber-700 text-white rounded-lg text-[10px] font-black uppercase tracking-wider transition-colors disabled:opacity-50">
                <i class="fas fa-code-merge mr-1"></i>Fusionar los dos hilos
              </button>

              <!-- ⚠️ Se enseña QUÉ va a pasar antes de aplicarlo, y quién sobrevive: lo decide la
                   ANTIGÜEDAD, no quien pulsa. Fusionar no se deshace — los mensajes quedan en una
                   sola línea de tiempo y no hay forma de saber cuál venía de dónde. -->
              <div v-else class="mt-1.5 bg-white border border-amber-300 rounded-lg p-2">
                <p class="text-slate-600 font-bold leading-snug">
                  Sobrevive <b class="text-slate-800">{{ previaFusion.superviviente.nombre || 'el hilo más antiguo' }}</b>
                  ({{ previaFusion.superviviente.mensajes }} mensajes) y absorbe
                  <b class="text-slate-800">{{ previaFusion.absorbido.nombre || 'el otro' }}</b>
                  ({{ previaFusion.absorbido.mensajes }} mensajes, {{ previaFusion.absorbido.asuntos }} asuntos).
                  <span class="text-amber-700">Esto no se deshace.</span>
                </p>
                <div class="flex gap-1.5 mt-1.5">
                  <button type="button" @click="previaFusion = null"
                          class="px-2 py-1 border border-slate-200 rounded-lg text-[10px] font-bold text-slate-500">Cancelar</button>
                  <button type="button" @click="aplicarFusion" :disabled="fusionando"
                          class="px-2 py-1 bg-amber-600 hover:bg-amber-700 text-white rounded-lg text-[10px] font-black uppercase tracking-wider disabled:opacity-50">
                    <i v-if="fusionando" class="fas fa-circle-notch fa-spin mr-1"></i>Fusionar
                  </button>
                </div>
              </div>
            </div>

            <p v-if="errorIdent" class="text-[11px] font-bold text-red-500 mt-2 leading-snug">{{ errorIdent }}</p>
          </div>

          <p v-if="errorMsg" class="text-xs font-bold text-red-500">{{ errorMsg }}</p>

          <button @click="handleSave" :disabled="saving || deleting"
                  class="w-full py-3.5 bg-[#E07845] text-white rounded-xl text-xs font-black uppercase tracking-widest transition-colors shadow-md disabled:opacity-50">
            <i class="fas mr-2" :class="saving ? 'fa-circle-notch fa-spin' : 'fa-save'"></i> Guardar Cambios
          </button>

          <button @click="handleDelete" :disabled="saving || deleting"
                  class="w-full py-3.5 bg-white border border-red-300 text-red-600 hover:bg-red-50 rounded-xl text-xs font-black uppercase tracking-widest transition-colors disabled:opacity-50">
            <i class="fas mr-2" :class="deleting ? 'fa-circle-notch fa-spin' : 'fa-trash'"></i> Eliminar Conversación
          </button>
        </div>

        <!-- Datos adicionales (solo lectura) -->
        <div class="pt-5 border-t border-slate-200">
          <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">Datos Adicionales</h3>
          <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-xs">
            <div><dt class="text-slate-400 font-bold uppercase text-[9px]">Estado</dt><dd class="font-bold text-slate-700">{{ conversation.status }}</dd></div>
            <div><dt class="text-slate-400 font-bold uppercase text-[9px]">Origen</dt><dd class="font-bold text-slate-700">{{ conversation.contextType }} / {{ conversation.contextOrigin || '—' }}</dd></div>
            <div><dt class="text-slate-400 font-bold uppercase text-[9px]">Referencia</dt><dd class="font-bold text-slate-700 truncate">{{ conversation.contextId }}</dd></div>
            <div><dt class="text-slate-400 font-bold uppercase text-[9px]">Tag</dt><dd class="font-bold text-slate-700">{{ conversation.contextStatusTag || '—' }}</dd></div>
            <div><dt class="text-slate-400 font-bold uppercase text-[9px]">No leídos</dt><dd class="font-bold text-slate-700">{{ conversation.unreadCount ?? 0 }}</dd></div>
            <div><dt class="text-slate-400 font-bold uppercase text-[9px]">Sesión WhatsApp</dt><dd class="font-bold" :class="conversation.whatsappSessionActive ? 'text-green-600' : 'text-red-500'">{{ conversation.whatsappSessionActive ? 'Activa' : 'Cerrada' }}</dd></div>
            <div><dt class="text-slate-400 font-bold uppercase text-[9px]">Creada</dt><dd class="font-bold text-slate-700">{{ formatDateTime(conversation.createdAt) }}</dd></div>
            <div><dt class="text-slate-400 font-bold uppercase text-[9px]">Último mensaje</dt><dd class="font-bold text-slate-700">{{ formatDateTime(conversation.lastMessageAt) }}</dd></div>
            <div><dt class="text-slate-400 font-bold uppercase text-[9px]">Último entrante</dt><dd class="font-bold text-slate-700">{{ formatDateTime(conversation.lastInboundAt) }}</dd></div>
            <div><dt class="text-slate-400 font-bold uppercase text-[9px]">Vence sesión WA</dt><dd class="font-bold text-slate-700">{{ formatDateTime(conversation.whatsappSessionValidUntil) }}</dd></div>
            <div v-if="conversation.contextFinancialTotal != null"><dt class="text-slate-400 font-bold uppercase text-[9px]">Total</dt><dd class="font-bold text-slate-700">{{ conversation.contextFinancialTotal }} ({{ conversation.contextFinancialIsCleared ? 'saldado' : 'pendiente' }})</dd></div>
            <div v-if="conversation.contextItems?.length"><dt class="text-slate-400 font-bold uppercase text-[9px]">Items</dt><dd class="font-bold text-slate-700">{{ conversation.contextItems.join(', ') }}</dd></div>
            <div v-if="conversation.contextMilestones?.start"><dt class="text-slate-400 font-bold uppercase text-[9px]">Inicio</dt><dd class="font-bold text-slate-700">{{ formatDateTime(conversation.contextMilestones?.start) }}</dd></div>
            <div v-if="conversation.contextMilestones?.end"><dt class="text-slate-400 font-bold uppercase text-[9px]">Fin</dt><dd class="font-bold text-slate-700">{{ formatDateTime(conversation.contextMilestones?.end) }}</dd></div>
          </dl>
        </div>
      </div>
    </div>
  </div>
</template>
