<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue';
import { useChatStore, type ApiConversation } from '@/stores/chat/chatStore.ts';
import { useMaestroStore } from '@/stores/maestroStore';
import { uuidDe } from '@/services/hydra';

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

const nuevoTipo = ref('telefono');
const nuevoValor = ref('');
const errorIdent = ref('');
const ocupado = ref(false);

/** Vienen serializadas con la conversación (`conversation:read`). */
interface IdentidadDelPanel {
  id: string;
  tipo: string;
  valor: string;
  principal: boolean;
  bloqueado: boolean;
  bloqueadoMotivo: string | null;
  retirada: boolean;
}

const identidades = computed<IdentidadDelPanel[]>(() => {
  const filas = (props.conversation as unknown as { identidades?: unknown[] }).identidades ?? [];

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
    };
  });
});

const anadir = async () => {
  if (!nuevoValor.value.trim()) return;

  ocupado.value = true;
  errorIdent.value = await store.anadirIdentidad(nuevoTipo.value, nuevoValor.value) ?? '';
  ocupado.value = false;

  if (!errorIdent.value) nuevoValor.value = '';
};

const cambiar = async (id: string, cambios: { principal?: boolean; bloqueado?: boolean; retirada?: boolean }) => {
  ocupado.value = true;
  errorIdent.value = await store.cambiarIdentidad(id, cambios) ?? '';
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
  guestPhone: '',
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
    guestPhone: c.guestPhone || '',
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
    guestPhone: form.value.guestPhone.trim() || null,
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

          <div>
            <label class="block text-[10px] font-black text-slate-500 uppercase mb-1.5 ml-1">Teléfono</label>
            <input v-model="form.guestPhone" type="text" placeholder="Sin teléfono"
                   class="w-full bg-white border border-slate-300 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:ring-2 focus:ring-[#376875] shadow-sm">
          </div>

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
              <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Identificadores</h3>
              <span class="text-[9px] font-bold text-slate-300 uppercase">de la persona, no de la reserva</span>
            </div>

            <p v-if="!identidades.length" class="text-xs text-slate-400 font-bold mb-2">Ninguno todavía.</p>

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

                <template v-if="!ident.retirada">
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
                <button v-else @click="cambiar(ident.id, {})" disabled
                        title="Retirada. Para reactivarla, vuelve a añadir el mismo valor."
                        class="w-6 h-6 rounded-lg text-slate-200"><i class="fas fa-clock-rotate-left text-[10px]"></i></button>
              </li>
            </ul>

            <div class="flex gap-1.5">
              <select v-model="nuevoTipo"
                      class="bg-white border border-slate-300 rounded-xl px-2 py-2 text-xs font-bold outline-none focus:ring-2 focus:ring-[#376875]">
                <option value="telefono">Teléfono</option>
                <option value="email">Correo</option>
              </select>
              <input v-model="nuevoValor" type="text" placeholder="Añadir identificador" @keyup.enter="anadir"
                     class="flex-1 min-w-0 bg-white border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold outline-none focus:ring-2 focus:ring-[#376875]">
              <button @click="anadir" :disabled="ocupado || !nuevoValor.trim()"
                      class="px-3 py-2 bg-[#376875] text-white rounded-xl text-xs font-black disabled:opacity-40">
                <i class="fas fa-plus"></i>
              </button>
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
