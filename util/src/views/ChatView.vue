<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch, nextTick, computed } from 'vue';
import { useChatStore, ApiMessage } from '@/stores/chatStore';
import { useAttachmentStore } from '@/stores/attachmentStore';

const store = useChatStore();
const attachmentStore = useAttachmentStore();

const messagesContainer = ref<HTMLElement | null>(null);
const conversationsContainer = ref<HTMLElement | null>(null);
const newMessageText = ref('');

const isMobileSidebarOpen = ref(true);

// Estados del Composer
const selectedTemplateId = ref<string | null>(null);
const showTemplateDropdown = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);

// Hook Multicanal
const selectedChannels = ref<string[]>(['whatsapp_gupshup']);

// 🔥 GESTIÓN DE HISTORIAL MÓVIL (BOTÓN ATRÁS NATIVO)
const handlePopState = (event: PopStateEvent) => {
  // Solo alteramos la UI si el navegador retrocedió. NO inyectamos pushState aquí.
  if (window.innerWidth < 768 && !isMobileSidebarOpen.value) {
    isMobileSidebarOpen.value = true;
  }
};

onMounted(() => {
  store.fetchConversations();
  store.fetchTemplates();

  // Plantamos el estado inicial reemplazando el actual (no apilando uno nuevo)
  if (window.innerWidth < 768) {
    history.replaceState({ view: 'sidebar' }, '');
  }
  window.addEventListener('popstate', handlePopState);
});

onUnmounted(() => {
  window.removeEventListener('popstate', handlePopState);
});

// 🔥 CERROJOS LOCALES PARA PAGINACIÓN Y SCROLL
let isAdjustingMessageScroll = false;

// SCROLL INFINITO: Mensajes antiguos (Hacia arriba)
const onMessageScroll = async () => {
  const el = messagesContainer.value;
  // Abortamos si no hay scroll, si ya está cargando, o si cerramos el candado de ajuste
  if (!el || store.loadingMoreMessages || !store.hasMoreMessages || isAdjustingMessageScroll) return;

  // Tolerancia ampliada a 50px para detectar el techo antes de chocar (ideal para Mac/iOS)
  if (el.scrollTop <= 50) {
    isAdjustingMessageScroll = true; // 🔒 CERRAMOS CANDADO
    const previousScrollHeight = el.scrollHeight;

    await store.loadMoreMessages();
    await nextTick();

    // Empujamos el scroll hacia abajo para compensar los mensajes nuevos arriba
    el.scrollTop = el.scrollTop + (el.scrollHeight - previousScrollHeight);

    // 🔓 ABRIMOS CANDADO después de que el DOM asiente el movimiento
    setTimeout(() => {
      isAdjustingMessageScroll = false;
    }, 50);
  }
};

// SCROLL INFINITO: Lista de chats (Hacia abajo)
const onConversationScroll = async () => {
  const el = conversationsContainer.value;
  if (!el || store.loadingMoreConversations || !store.hasMoreConversations) return;

  // Usamos Math.ceil() por los monitores retina y ampliamos el margen de detección a 150px.
  // De esta forma, detectará que llegaste al final INCLUSO antes de rebotar.
  const isBottom = Math.ceil(el.scrollTop + el.clientHeight) >= el.scrollHeight - 150;

  if (isBottom) {
    // El Store ya tiene su propio seguro interno, por lo que nunca pedirá la misma página dos veces.
    await store.fetchConversations(true);
  }
};

const scrollToBottom = () => {
  if (messagesContainer.value) {
    messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
  }
};

// Control inteligente de auto-scroll
watch(() => store.messages.length, async (newLen, oldLen) => {
  await nextTick();
  // Solo auto-scroll si el array creció (mensaje nuevo) y NO estamos paginando hacia atrás
  if (newLen > oldLen && !store.loadingMoreMessages) {
    scrollToBottom();
  }
});

watch(() => store.error, (v) => {
  if (v) setTimeout(() => store.error = null, 6000);
});

watch(() => store.currentConversation, (chat) => {
  selectedTemplateId.value = null;
  showTemplateDropdown.value = false;
  attachmentStore.clear();

  if (chat?.contextOrigin && !['manual', 'web', 'directo'].includes(chat.contextOrigin.toLowerCase())) {
    selectedChannels.value = ['beds24', 'whatsapp_gupshup'];
  } else {
    selectedChannels.value = ['whatsapp_gupshup'];
  }
});

const selectChat = async (id: string) => {
  await store.selectConversation(id);
  isMobileSidebarOpen.value = false;
  await nextTick();
  scrollToBottom();

  // Inyectamos el estado "chat_abierto" para que el gesto atrás de iOS/Android funcione
  if (window.innerWidth < 768) {
    history.pushState({ view: 'chat' }, '');
  }
};

const closeMobileChat = () => {
  // Dejamos que el navegador retroceda de forma nativa (disparará handlePopState)
  history.back();
};

// ============================================================================
// LÓGICA DE CANALES Y ADJUNTOS
// ============================================================================

const toggleChannel = (channel: string) => {
  if (selectedChannels.value.includes(channel)) {
    selectedChannels.value = selectedChannels.value.filter(c => c !== channel);
  } else {
    if (channel === 'beds24' && attachmentStore.file && !attachmentStore.isImage) {
      store.error = 'No puedes activar Beds24 porque has adjuntado un documento. Beds24 solo admite imágenes.';
      return;
    }
    selectedChannels.value.push(channel);
  }
};

const onFileSelected = (event: Event) => {
  const target = event.target as HTMLInputElement;
  if (target.files && target.files.length > 0) {
    const success = attachmentStore.setFile(target.files[0]);

    if (success) {
      if (!attachmentStore.isImage && selectedChannels.value.includes('beds24')) {
        store.error = 'Beds24 solo permite el envío de imágenes. Se ha desmarcado este canal automáticamente.';
        selectedChannels.value = selectedChannels.value.filter(c => c !== 'beds24');
      }
    } else {
      store.error = attachmentStore.error;
    }
  }

  if (fileInput.value) fileInput.value.value = '';
};

const send = async () => {
  if (!newMessageText.value.trim() && !selectedTemplateId.value && !attachmentStore.file) return;
  if (selectedChannels.value.length === 0 && !selectedTemplateId.value) {
    store.error = 'Selecciona al menos un canal de envío.';
    return;
  }

  await store.sendMessage(newMessageText.value, selectedTemplateId.value, selectedChannels.value);
  newMessageText.value = '';
  selectedTemplateId.value = null;
  showTemplateDropdown.value = false;
};

// ============================================================================
// HELPERS VISUALES
// ============================================================================

const getChannelIcons = (msg: ApiMessage) => {
  const icons = [];
  if (msg.whatsappGupshupSendQueues?.length) icons.push({ class: 'fab fa-whatsapp', color: 'text-green-500' });
  if (msg.beds24SendQueues?.length) icons.push({ class: 'fas fa-bed', color: 'text-[#003580]' });

  if (icons.length === 0) {
    const waMeta = msg.metadata?.whatsappGupshup || msg.metadata?.gupshup;
    if (waMeta?.received_at || waMeta?.external_id) icons.push({ class: 'fab fa-whatsapp', color: 'text-green-500' });
    if (msg.metadata?.beds24?.received_at) icons.push({ class: 'fas fa-bed', color: 'text-[#003580]' });
  }
  return icons.length ? icons : [{ class: 'fas fa-comment-dots', color: 'text-slate-400' }];
};

const getTemplateName = (templateData: any) => {
  if (!templateData) return null;
  if (typeof templateData === 'string') {
    const found = store.templates.find(t => t['@id'] === templateData);
    return found ? found.name : 'Plantilla';
  }
  return templateData.name || 'Plantilla';
};

const getMessageTicks = (msg: ApiMessage) => {
  if (msg.status === 'failed') return { class: 'fas fa-exclamation-circle', color: 'text-red-500', title: 'Error general' };

  if (msg.whatsappGupshupSendQueues?.length) {
    const q = msg.whatsappGupshupSendQueues[msg.whatsappGupshupSendQueues.length - 1];
    const delivery = typeof q === 'string' ? null : q.deliveryStatus;
    const qStatus = typeof q === 'string' ? null : q.status;

    if (delivery === 'read') return { class: 'fas fa-check-double', color: 'text-blue-500', title: 'Leído en WhatsApp' };
    if (delivery === 'delivered') return { class: 'fas fa-check-double', color: 'text-slate-400', title: 'Entregado al teléfono' };
    if (delivery === 'submitted') return { class: 'fas fa-check', color: 'text-slate-400', title: 'Enviado a Meta' };
    if (qStatus === 'failed') return { class: 'fas fa-exclamation-circle', color: 'text-red-500', title: 'Error en WhatsApp' };
  }
  if (msg.status === 'read') return { class: 'fas fa-check-double', color: 'text-blue-500' };
  if (msg.status === 'sent') return { class: 'fas fa-check', color: 'text-slate-400' };
  if (msg.status === 'queued') return { class: 'far fa-clock', color: 'text-slate-300' };

  return { class: 'far fa-clock', color: 'text-slate-200' };
};

const formatTime = (iso?: string) => iso ? new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
const formatDate = (iso?: string) => iso ? new Date(iso).toLocaleDateString('es-ES', { day: '2-digit', month: 'short' }) : '';
const formatFullDate = (iso?: string) => iso ? new Date(iso).toLocaleDateString('es-ES', { day: 'numeric', month: 'long' }) : '';

const groupedMessages = computed(() => {
  const groups: Record<string, any[]> = {};
  store.messages.forEach(msg => {
    if(!msg.createdAt) return;
    const d = new Date(msg.createdAt).toISOString().split('T')[0];
    if (!groups[d]) groups[d] = [];
    groups[d].push(msg);
  });
  return groups;
});

const getOriginClass = (origin?: string | null) => {
  const colors: Record<string, string> = { booking: 'bg-[#003580]', airbnb: 'bg-[#FF5A5F]', expedia: 'bg-[#00355F]' };
  return colors[origin?.toLowerCase() || ''] || 'bg-[#376875]';
};
</script>

<template>
  <div class="flex h-[100dvh] w-full bg-[#F8FAFC] font-sans overflow-hidden relative text-slate-900 antialiased">

    <Transition name="fade-slide">
      <div v-if="store.error" class="fixed top-8 left-1/2 -translate-x-1/2 z-[100] bg-slate-900 text-white px-6 py-3 rounded-2xl shadow-2xl flex items-center gap-4 backdrop-blur-xl border border-white/10 max-w-[90vw] text-center">
        <div class="w-2 h-2 rounded-full bg-red-500 animate-pulse shrink-0"></div>
        <span class="text-xs font-black uppercase tracking-wide leading-tight">{{ store.error }}</span>
      </div>
    </Transition>

    <aside
        class="fixed inset-y-0 left-0 z-40 w-full md:relative md:w-80 lg:w-[380px] bg-white border-r border-slate-200 flex flex-col transition-all duration-500 ease-in-out md:translate-x-0"
        :class="isMobileSidebarOpen ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="px-6 pt-6 bg-white shrink-0">
        <div class="flex justify-between items-center mb-6">
          <h1 class="font-black text-2xl tracking-tight text-slate-800">Inbox</h1>
          <button @click="store.fetchConversations()" class="w-9 h-9 rounded-xl bg-slate-50 flex items-center justify-center hover:bg-slate-900 group transition-all shadow-sm">
            <i class="fas fa-sync-alt text-slate-400 group-hover:text-white text-xs" :class="{'fa-spin': store.loadingConversations}"></i>
          </button>
        </div>
        <div class="flex bg-slate-100 p-1 rounded-xl mb-4 shadow-inner">
          <button v-for="status in ['open', 'archived', 'closed']" :key="status" @click="store.filterStatus = status" class="flex-1 py-2 text-[10px] font-black uppercase tracking-wider rounded-lg transition-all" :class="store.filterStatus === status ? 'bg-white text-[#376875] shadow-sm' : 'text-slate-400 hover:text-slate-600'">
            {{ status === 'open' ? 'Activos' : status === 'archived' ? 'Archivados' : 'Cerrados' }}
          </button>
        </div>
      </div>

      <div class="flex-1 overflow-y-auto scrollbar-hide py-2 px-3" ref="conversationsContainer" @scroll="onConversationScroll">
        <div v-if="store.loadingConversations" class="p-10 text-center"><i class="fas fa-circle-notch fa-spin text-slate-300"></i></div>
        <div v-else-if="store.filteredConversations.length === 0" class="p-10 text-center opacity-30 italic text-xs font-bold uppercase tracking-widest">Bandeja Vacía</div>

        <div v-for="chat in store.filteredConversations" :key="chat?.id" class="mb-1">
          <button @click="selectChat(chat.id)" class="w-full text-left p-4 rounded-2xl transition-all flex gap-4 relative group border border-transparent" :class="store.currentConversation?.id === chat.id ? 'bg-white border-slate-200 shadow-xl translate-x-1' : 'hover:bg-slate-50'">
            <span v-if="store.currentConversation?.id === chat.id" class="absolute left-0 top-4 bottom-4 w-1.5 bg-[#376875] rounded-r-full block"></span>
            <span class="w-12 h-12 rounded-xl text-white flex items-center justify-center shrink-0 font-black text-lg shadow-sm" :class="getOriginClass(chat.contextOrigin)">
              {{ chat.guestName?.charAt(0).toUpperCase() || '?' }}
            </span>
            <span class="flex-1 min-w-0 block">
              <span class="flex justify-between items-baseline mb-0.5">
                <span class="font-bold truncate text-sm block" :class="store.currentConversation?.id === chat.id ? 'text-[#376875]' : 'text-slate-800'">{{ chat.guestName || 'Huésped' }}</span>
                <span class="text-[9px] font-black uppercase text-slate-400 ml-2 block">{{ formatDate(chat.lastMessageAt || chat.createdAt) }}</span>
              </span>
              <span class="text-[10px] font-black truncate text-[#E07845] mb-1 uppercase tracking-tight block">{{ chat.contextItems?.length ? chat.contextItems.join(', ') : 'Reserva PMS' }}</span>
            </span>
            <span v-if="chat.unreadCount > 0" class="absolute -right-1 -top-1 w-5 h-5 bg-[#E07845] text-white text-[10px] font-black rounded-full flex items-center justify-center border-2 border-white shadow-md">{{ chat.unreadCount }}</span>
          </button>
        </div>

        <div v-if="store.loadingMoreConversations" class="py-4 text-center">
          <i class="fas fa-circle-notch fa-spin text-slate-300"></i>
        </div>
      </div>
    </aside>

    <main class="flex-1 bg-[#F1F5F9] flex flex-col relative z-30 transition-all duration-300 min-w-0">
      <div v-if="!store.currentConversation" class="hidden md:flex flex-1 flex-col items-center justify-center bg-white text-slate-300">
        <i class="fas fa-paper-plane text-4xl mb-4 opacity-10"></i>
        <h2 class="text-xl font-black text-slate-800 tracking-tighter uppercase tracking-widest">Selecciona un chat</h2>
      </div>

      <template v-else>
        <header class="h-16 md:h-24 bg-white/90 backdrop-blur-md border-b border-slate-200 px-4 md:px-8 flex items-center justify-between shrink-0 sticky top-0 z-30">
          <div class="flex items-center gap-4 overflow-hidden">
            <button @click="closeMobileChat" class="md:hidden w-10 h-10 flex items-center justify-center bg-slate-50 rounded-xl text-slate-500 shadow-sm transition-colors"><i class="fas fa-chevron-left"></i></button>
            <div class="truncate">
              <h2 class="font-black text-slate-900 text-lg md:text-2xl tracking-tight truncate leading-none mb-1">
                {{ store.currentConversation?.guestName || 'Huésped Sin Nombre' }}
              </h2>
              <div class="flex items-center gap-3 text-[10px] md:text-[11px] font-black uppercase tracking-widest text-slate-400">
                <span class="text-[#E07845]">{{ store.currentConversation?.contextItems?.length ? store.currentConversation.contextItems.join(' + ') : 'PMS' }}</span>
                <span class="hidden sm:inline text-slate-200">/</span>
                <span class="hidden sm:inline">{{ formatFullDate(store.currentConversation?.contextMilestones?.start) }} - {{ formatFullDate(store.currentConversation?.contextMilestones?.end) }}</span>
              </div>
            </div>
          </div>

          <div class="flex items-center gap-3 shrink-0 ml-4">
            <span v-if="store.currentConversation?.contextStatusTag"
                  class="hidden lg:inline-flex items-center px-2.5 py-1 bg-slate-100 border border-slate-200 text-slate-500 rounded-lg text-[9px] font-black uppercase tracking-widest">
              {{ store.currentConversation.contextStatusTag }}
            </span>

            <a v-if="store.getExternalContextUrl"
               :href="store.getExternalContextUrl"
               target="_blank"
               class="flex items-center gap-2 px-3 py-2 bg-slate-900 text-white rounded-xl shadow-xl hover:-translate-y-0.5 hover:shadow-2xl hover:bg-slate-800 transition-all text-[10px] font-black uppercase tracking-wider">
              <i class="fas fa-external-link-alt"></i><span class="hidden md:inline">Ver Reserva</span>
            </a>
          </div>
        </header>

        <div class="flex-1 overflow-y-auto p-4 md:px-12 md:py-8" ref="messagesContainer" @scroll="onMessageScroll">

          <div v-if="store.loadingMoreMessages" class="flex justify-center py-4">
            <i class="fas fa-circle-notch fa-spin text-slate-300"></i>
          </div>

          <div class="max-w-4xl mx-auto flex flex-col">
            <div v-for="(group, date) in groupedMessages" :key="date" class="flex flex-col">
              <div class="flex justify-center my-6 sticky top-2 z-10">
                <span class="px-4 py-1.5 bg-white/90 backdrop-blur-md border border-slate-200 shadow-sm rounded-full text-[10px] font-black text-slate-800 uppercase tracking-widest">
                  {{ date === new Date().toISOString().split('T')[0] ? 'Hoy' : formatDate(date) }}
                </span>
              </div>

              <div class="space-y-6 flex flex-col mb-8">
                <div v-for="msg in group" :key="msg.id" class="flex w-full" :class="msg.direction === 'outgoing' ? 'justify-end' : 'justify-start'">
                  <div class="relative max-w-[85%] md:max-w-[70%] flex flex-col" :class="msg.direction === 'outgoing' ? 'items-end pl-10' : 'items-start pr-10'">

                    <div class="absolute top-1 flex flex-col gap-1.5" :class="msg.direction === 'outgoing' ? 'left-0' : 'right-0'">
                      <div v-for="icon in getChannelIcons(msg)" :key="icon.class" class="w-8 h-8 rounded-full bg-white shadow-md flex items-center justify-center border border-slate-100">
                        <i :class="[icon.class, icon.color]" class="text-sm"></i>
                      </div>
                    </div>

                    <div :class="msg.direction === 'outgoing' ? 'bg-[#376875] text-white rounded-3xl rounded-tr-none shadow-lg' : 'bg-white border border-slate-200 text-slate-800 rounded-3xl rounded-tl-none shadow-sm'" class="p-4 md:p-5 text-sm md:text-base font-medium leading-relaxed whitespace-pre-wrap relative w-full break-words">

                      <div v-if="msg.template" class="flex items-center gap-2 mb-2 pb-2 border-b" :class="msg.direction === 'outgoing' ? 'border-white/20' : 'border-slate-100'">
                        <i class="fas fa-robot text-xs opacity-70"></i>
                        <span class="text-[10px] font-black uppercase tracking-widest opacity-80 truncate">{{ getTemplateName(msg.template) }}</span>
                      </div>

                      <div v-if="msg.attachments?.length" class="mb-3 space-y-2">
                        <div v-for="att in msg.attachments" :key="(typeof att === 'string') ? att : att.id" class="flex items-center gap-3 p-3 rounded-xl bg-black/10">
                          <div class="w-10 h-10 bg-white/20 rounded-lg flex items-center justify-center shrink-0 overflow-hidden">
                            <img v-if="typeof att !== 'string' && att.fileUrl" :src="att.fileUrl" class="w-full h-full object-cover" />
                            <i v-else class="fas fa-file-alt text-lg"></i>
                          </div>
                          <div class="min-w-0">
                            <p class="text-xs font-bold truncate">{{ (typeof att === 'string') ? 'Archivo Adjunto' : att.originalName || 'Archivo Adjunto' }}</p>
                            <p class="text-[9px] font-mono opacity-70">{{ (typeof att === 'string') ? 'Documento' : att.mimeType || 'Documento' }}</p>
                          </div>
                        </div>
                      </div>

                      <span>{{ msg.contentLocal || msg.contentExternal || 'Mensaje enviado' }}</span>
                    </div>

                    <div class="flex items-center gap-2 mt-1.5 px-2 text-[10px] font-black text-slate-400 uppercase tracking-tighter" :class="msg.direction === 'outgoing' ? 'flex-row-reverse' : 'flex-row'">
                      <span>{{ formatTime(msg.createdAt) }}</span>
                      <template v-if="msg.direction === 'outgoing'">
                        <i :class="[getMessageTicks(msg).class, getMessageTicks(msg).color]" :title="getMessageTicks(msg).title" class="text-[11px]"></i>
                      </template>
                    </div>

                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <footer class="bg-white border-t border-slate-100 p-3 pb-[max(1rem,env(safe-area-inset-bottom))] md:p-6 shrink-0 relative flex flex-col gap-3 min-w-0">

          <div class="flex items-center gap-2 px-1 max-w-4xl mx-auto w-full overflow-x-auto scrollbar-hide">
            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest shrink-0"><i class="fas fa-satellite-dish mr-1"></i> Salida:</span>
            <button @click="toggleChannel('beds24')" :class="selectedChannels.includes('beds24') ? 'text-[#003580] bg-[#003580]/10 border-[#003580]/30' : 'text-slate-400 border-transparent hover:bg-slate-100'" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all border flex items-center gap-2 shrink-0">
              <i class="fas fa-bed"></i> Beds24
            </button>
            <button @click="toggleChannel('whatsapp_gupshup')" :class="selectedChannels.includes('whatsapp_gupshup') ? 'text-green-600 bg-green-50 border-green-200' : 'text-slate-400 border-transparent hover:bg-slate-100'" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all border flex items-center gap-2 shrink-0">
              <i class="fab fa-whatsapp text-sm"></i> WhatsApp
            </button>
          </div>

          <Transition name="fade-slide">
            <div v-if="showTemplateDropdown" class="absolute bottom-[90px] left-2 right-2 md:left-auto md:right-auto z-50 bg-white border border-slate-200 shadow-2xl rounded-2xl p-2 md:w-96 max-h-64 overflow-y-auto">
              <div class="px-3 py-2 text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 mb-2 flex justify-between items-center">
                <span>Plantillas Permitidas</span>
                <button @click="showTemplateDropdown = false" class="text-slate-300 hover:text-red-400"><i class="fas fa-times"></i></button>
              </div>
              <button v-for="tpl in store.validTemplates" :key="tpl.id" @click="selectedTemplateId = tpl['@id']; showTemplateDropdown = false" class="w-full text-left px-4 py-3 hover:bg-slate-50 rounded-xl transition-colors mb-1 group flex items-center justify-between">
                <span class="block min-w-0">
                  <span class="block text-sm font-bold text-slate-800 truncate">{{ tpl.name }}</span>
                  <span class="block text-[10px] font-mono text-slate-400 truncate">{{ tpl.code }}</span>
                </span>
              </button>
              <div v-if="store.validTemplates.length === 0" class="p-4 text-center text-xs text-slate-400 italic">No hay plantillas para este canal.</div>
            </div>
          </Transition>

          <input type="file" ref="fileInput" class="hidden" @change="onFileSelected" />

          <form @submit.prevent="send" class="max-w-4xl mx-auto flex items-end gap-2 md:gap-3 bg-slate-50 border-2 border-slate-100 p-2 rounded-[24px] focus-within:bg-white focus-within:border-[#376875]/30 transition-all w-full relative min-w-0">

            <div v-if="attachmentStore.file" class="absolute -top-14 left-0 bg-white border border-slate-200 shadow-lg rounded-xl px-3 py-2 flex items-center gap-3 z-10 animate-fade-in max-w-full">
              <img v-if="attachmentStore.isImage" :src="attachmentStore.previewUrl ?? undefined" class="w-8 h-8 object-cover rounded shadow-sm shrink-0" />
              <i v-else class="fas fa-file-pdf text-red-500 text-2xl shrink-0"></i>
              <div class="flex flex-col min-w-0">
                <span class="text-xs font-bold text-slate-800 truncate">{{ attachmentStore.fileName }}</span>
                <span class="text-[9px] text-slate-400">{{ attachmentStore.fileSizeKB }} KB</span>
              </div>
              <button type="button" @click="attachmentStore.clear()" class="text-slate-400 hover:text-red-500 ml-2 shrink-0"><i class="fas fa-times"></i></button>
            </div>

            <div class="flex items-center gap-1 shrink-0 pb-1">
              <button type="button" @click="showTemplateDropdown = !showTemplateDropdown" class="w-8 h-8 flex items-center justify-center text-slate-400 hover:text-[#376875] bg-white shadow-sm border border-slate-100 rounded-full transition-all">
                <i class="fas fa-robot text-xs"></i>
              </button>
              <button type="button" @click="fileInput?.click()" class="w-8 h-8 flex items-center justify-center text-slate-400 hover:text-[#376875] bg-white shadow-sm border border-slate-100 rounded-full transition-all">
                <i class="fas fa-paperclip text-xs"></i>
              </button>
            </div>

            <div class="flex-1 min-h-[40px] flex items-center min-w-0">
              <div v-if="selectedTemplateId" class="w-full bg-white border border-[#376875]/20 rounded-xl px-3 py-1.5 flex justify-between items-center shadow-sm overflow-hidden min-w-0 mr-1">
                <div class="flex items-center gap-2 min-w-0">
                  <i class="fas fa-robot text-[#376875] shrink-0 text-xs"></i>
                  <div class="min-w-0 truncate">
                    <span class="block text-[9px] font-black uppercase text-slate-400">Plantilla</span>
                    <span class="block text-xs font-bold text-slate-800 truncate">{{ getTemplateName(selectedTemplateId) }}</span>
                  </div>
                </div>
                <button type="button" @click="selectedTemplateId = null" class="w-6 h-6 shrink-0 rounded-full hover:bg-red-50 text-red-400 flex items-center justify-center ml-2"><i class="fas fa-times text-xs"></i></button>
              </div>
              <textarea v-else v-model="newMessageText" @keydown.enter.exact.prevent="send" placeholder="Escribe tu mensaje..." class="w-full bg-transparent border-0 focus:ring-0 resize-none py-2 px-2 text-sm font-semibold text-slate-800 scrollbar-hide" rows="1"></textarea>
            </div>

            <button type="submit" :disabled="(!newMessageText.trim() && !selectedTemplateId && !attachmentStore.file) || store.sendingMessage" class="w-10 h-10 shrink-0 bg-[#E07845] text-white rounded-full flex items-center justify-center shadow-md hover:scale-105 transition-all disabled:opacity-30 mb-0.5">
              <i class="fas text-xs" :class="store.sendingMessage ? 'fa-circle-notch fa-spin' : 'fa-paper-plane'"></i>
            </button>
          </form>
        </footer>
      </template>
    </main>
  </div>
</template>

<style scoped>
.scrollbar-hide::-webkit-scrollbar { display: none; }
.fade-slide-enter-active, .fade-slide-leave-active { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
.fade-slide-enter-from, .fade-slide-leave-to { opacity: 0; transform: translateY(10px) scale(0.98); }
.animate-fade-in { animation: fadeIn 0.2s ease-out forwards; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
textarea { outline: none; }
</style>