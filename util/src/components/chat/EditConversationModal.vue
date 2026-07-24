<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue';
import { useChatStore, type ApiConversation } from '@/stores/chat/chatStore.ts';
import { useMaestroStore } from '@/stores/maestroStore';

const props = defineProps<{ conversation: ApiConversation }>();
const emit = defineEmits<{ close: [] }>();

const store = useChatStore();
const maestroStore = useMaestroStore();

const saving = ref(false);
const errorMsg = ref('');

const form = ref({
  guestName: '',
  guestPhone: '',
  idiomaId: '',
  idiomaFijado: false,
  whatsappDisabled: false,
  whatsappDisabledReason: ''
});

const resetForm = () => {
  const c = props.conversation;
  const idiomaRef = (c as any).idioma as string | undefined;
  form.value = {
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
  const c = props.conversation as any;
  return c.id || (c['@id'] ? String(c['@id']).split('/').pop() : null);
});

const handleSave = async () => {
  if (!conversationUuid.value) return;
  saving.value = true;
  errorMsg.value = '';

  const idiomaObj = maestroStore.idiomas.find((i: any) => i.id === form.value.idiomaId);

  const payload: Record<string, any> = {
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

          <p v-if="errorMsg" class="text-xs font-bold text-red-500">{{ errorMsg }}</p>

          <button @click="handleSave" :disabled="saving"
                  class="w-full py-3.5 bg-[#E07845] text-white rounded-xl text-xs font-black uppercase tracking-widest transition-colors shadow-md disabled:opacity-50">
            <i class="fas mr-2" :class="saving ? 'fa-circle-notch fa-spin' : 'fa-save'"></i> Guardar Cambios
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
            <div v-if="(conversation.contextMilestones as any)?.start"><dt class="text-slate-400 font-bold uppercase text-[9px]">Inicio</dt><dd class="font-bold text-slate-700">{{ formatDateTime((conversation.contextMilestones as any)?.start) }}</dd></div>
            <div v-if="(conversation.contextMilestones as any)?.end"><dt class="text-slate-400 font-bold uppercase text-[9px]">Fin</dt><dd class="font-bold text-slate-700">{{ formatDateTime((conversation.contextMilestones as any)?.end) }}</dd></div>
          </dl>
        </div>
      </div>
    </div>
  </div>
</template>
