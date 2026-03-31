<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch, nextTick, computed } from 'vue';
import { useChatStore, ApiMessage, ApiTemplate } from '@/stores/chatStore';
import { useAttachmentStore } from '@/stores/attachmentStore';
import MessageStatusIcon from '@/components/MessageStatusIcon.vue';

const store = useChatStore();
const attachmentStore = useAttachmentStore();

const messagesContainer = ref<HTMLElement | null>(null);
const conversationsContainer = ref<HTMLElement | null>(null);
const newMessageText = ref('');

const isMobileSidebarOpen = ref(true);
const isTransitioning = ref(true);

// ============================================================================
// LÓGICA DE LOGIN PARA RENOVACIÓN DE SESIÓN (Intervención de modal flotante)
// ============================================================================
const loginUsername = ref('');
const loginPassword = ref('');
const isLoggingIn = ref(false);

/**
 * Maneja el envío del formulario del modal cuando la sesión expira (401).
 * Delega la validación al store, que reanudará las peticiones pausadas.
 * @returns {Promise<void>}
 */
const handleSessionRenewal = async () => {
  if (!loginUsername.value || !loginPassword.value) return;
  isLoggingIn.value = true;

  const success = await store.renewSession({
    _username: loginUsername.value,
    _password: loginPassword.value
  });

  if (success) {
    loginPassword.value = '';

    // Si había peticiones, el interceptor las soltará, pero reforzamos la UI
    await store.fetchConversations();

    if (store.currentConversation) {
      await store.selectConversation(store.currentConversation.id);
      await nextTick();
      scrollToBottom();
    }
  }

  isLoggingIn.value = false;
};

// ============================================================================
// LÓGICA ORIGINAL DE UI Y CHAT
// ============================================================================
const updateChatVisibility = () => {
  store.isChatVisible = window.innerWidth >= 768 || !isMobileSidebarOpen.value;
};

watch(isMobileSidebarOpen, updateChatVisibility);
const handleResize = () => updateChatVisibility();

const activeTab = ref<'history' | 'scheduled'>('history');

const selectedTemplateId = ref<string | null>(null);
const showTemplateDropdown = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);
const messageTextarea = ref<HTMLTextAreaElement | null>(null);
const selectedChannels = ref<string[]>([]);

const isPreviewModalOpen = ref(false);
const previewImageUrl = ref<string | null>(null);

const translatedMessages = ref<Record<string, boolean>>({});

const handlePopState = (event: PopStateEvent) => {
  if (window.innerWidth >= 768) return;
  isTransitioning.value = false;
  const targetView = event.state?.view;

  if (targetView === 'sidebar' || !targetView) {
    if (!isMobileSidebarOpen.value) isMobileSidebarOpen.value = true;
  } else if (targetView === 'chat') {
    if (isMobileSidebarOpen.value) isMobileSidebarOpen.value = false;
  }

  requestAnimationFrame(() => {
    setTimeout(() => { isTransitioning.value = true; }, 50);
  });
};

onMounted(() => {
  // Al ejecutarse, si no hay sesión, el interceptor capturará el 401
  // y activará store.isSessionExpired = true sin recargar la página.
  store.fetchConversations();
  store.fetchTemplates();
  store.initGlobalMercure();

  window.addEventListener('resize', handleResize);
  updateChatVisibility();

  if (window.innerWidth < 768) {
    history.replaceState({ view: 'sidebar' }, '');
  }
  window.addEventListener('popstate', handlePopState);
});

onUnmounted(() => {
  window.removeEventListener('popstate', handlePopState);
  window.removeEventListener('resize', handleResize);
});

let isAdjustingMessageScroll = false;

const onMessageScroll = async () => {
  if (activeTab.value !== 'history') return;

  const el = messagesContainer.value;
  if (!el || store.loadingMoreMessages || !store.hasMoreMessages || isAdjustingMessageScroll) return;

  if (el.scrollTop <= 50) {
    isAdjustingMessageScroll = true;
    const previousScrollHeight = el.scrollHeight;

    await store.loadMoreMessages();
    await nextTick();

    el.scrollTop = el.scrollTop + (el.scrollHeight - previousScrollHeight);

    setTimeout(() => { isAdjustingMessageScroll = false; }, 50);
  }
};

const onConversationScroll = async () => {
  const el = conversationsContainer.value;
  if (!el || store.loadingMoreConversations || !store.hasMoreConversations) return;
  const isBottom = Math.ceil(el.scrollTop + el.clientHeight) >= el.scrollHeight - 150;
  if (isBottom) await store.fetchConversations(true);
};

const scrollToBottom = () => {
  if (messagesContainer.value) {
    messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
  }
};

watch(() => store.activeChatMessages.length, async (newLen, oldLen) => {
  await nextTick();
  if (activeTab.value === 'history' && newLen > oldLen && !store.loadingMoreMessages) {
    scrollToBottom();
  }
});

watch(() => store.error, (v) => {
  if (v) setTimeout(() => store.error = null, 6000);
});

// ============================================================================
// NORMALIZACIÓN DE STATUS
// ============================================================================
const getMessageDisplayStatus = (msg: ApiMessage): string => {
  if (msg.status === 'cancelled') return 'cancelled';

  const allQueues = [
    ...(msg.whatsappMetaSendQueues ?? []),
    ...(msg.beds24SendQueues ?? [])
  ].filter((q): q is { status: string } => typeof q === 'object' && q !== null && 'status' in q);

  if (allQueues.length > 0 && allQueues.every(q => q.status === 'cancelled')) {
    return 'cancelled';
  }

  return msg.status;
};

// ============================================================================
// COMPUTED DE CANALES
// ============================================================================
const isBeds24Allowed = computed(() => {
  const chat = store.currentConversation;
  if (!chat) return false;

  if (chat.contextType !== 'pms_reserva') return false;
  const origin = (chat.contextOrigin || '').toLowerCase();
  const bannedOrigins = ['directo', 'whatsapp'];
  if (bannedOrigins.includes(origin)) return false;

  if (selectedTemplateId.value) {
    const tpl = store.templates.find(t => t['@id'] === selectedTemplateId.value);
    if (tpl && !tpl.beds24Active) return false;
  }

  return true;
});

const isWhatsappAllowed = computed(() => {
  const chat = store.currentConversation;
  if (!chat) return false;

  if (chat.whatsappDisabled) return false;

  const sessionActive = chat.whatsappSessionActive;

  if (selectedTemplateId.value) {
    const tpl = store.templates.find(t => t['@id'] === selectedTemplateId.value);
    if (!tpl) return false;

    if (!tpl.whatsappMetaActive) return false;

    if (!sessionActive && !tpl.whatsappMetaOfficial) return false;

    return true;
  }

  return sessionActive;
});

watch(() => store.currentConversation, (chat) => {
  selectedTemplateId.value = null;
  showTemplateDropdown.value = false;
  attachmentStore.clear();
  translatedMessages.value = {};

  activeTab.value = 'history';

  const newChannels: string[] = [];
  if (isBeds24Allowed.value) newChannels.push('beds24');
  if (chat?.whatsappSessionActive && !chat?.whatsappDisabled) newChannels.push('whatsapp_meta');

  selectedChannels.value = newChannels;
});

const selectChat = async (id: string) => {
  await store.selectConversation(id);
  isTransitioning.value = true;
  isMobileSidebarOpen.value = false;

  await nextTick();
  scrollToBottom();

  if (window.innerWidth < 768) {
    if (history.state?.view !== 'sidebar') {
      history.replaceState({ view: 'sidebar' }, '');
    }
    setTimeout(() => { history.pushState({ view: 'chat' }, ''); }, 300);
  }
};

const closeMobileChat = () => {
  if (history.state?.view === 'chat') {
    history.back();
  } else {
    isMobileSidebarOpen.value = true;
  }
};

const toggleChannel = (channel: string) => {
  if (selectedChannels.value.includes(channel)) {
    selectedChannels.value = selectedChannels.value.filter(c => c !== channel);
  } else {
    if (channel === 'beds24') {
      if (!isBeds24Allowed.value) return;
      if (attachmentStore.file && !attachmentStore.isImage) {
        store.error = 'No puedes activar Beds24 porque has adjuntado un documento. Beds24 solo admite imágenes.';
        return;
      }
    }
    if (channel === 'whatsapp_meta') {
      if (!isWhatsappAllowed.value) return;
    }
    selectedChannels.value.push(channel);
  }
};

const selectTemplate = (tpl: ApiTemplate) => {
  selectedTemplateId.value = tpl['@id'];
  showTemplateDropdown.value = false;

  let newChannels = tpl.channels || [];

  const chat = store.currentConversation;
  const sessionActive = chat?.whatsappSessionActive;

  if (chat?.whatsappDisabled || (!sessionActive && tpl.whatsappMetaOfficial === false)) {
    newChannels = newChannels.filter(c => c !== 'whatsapp_meta');
  }

  selectedChannels.value = newChannels;
};

const clearTemplate = () => {
  selectedTemplateId.value = null;
  const restoredChannels: string[] = [];
  const chat = store.currentConversation;
  if (chat) {
    if (isBeds24Allowed.value) restoredChannels.push('beds24');
    if (chat.whatsappSessionActive && !chat.whatsappDisabled) restoredChannels.push('whatsapp_meta');
  }
  selectedChannels.value = restoredChannels;
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

const adjustTextareaHeight = () => {
  if (!messageTextarea.value) return;
  messageTextarea.value.style.height = 'auto';
  const newHeight = Math.min(messageTextarea.value.scrollHeight, 150);
  messageTextarea.value.style.height = `${newHeight}px`;
};

const send = async () => {
  if (!newMessageText.value.trim() && !selectedTemplateId.value && !attachmentStore.file) return;
  if (selectedChannels.value.length === 0 && !selectedTemplateId.value) {
    store.error = 'Selecciona al menos un canal de envío.';
    return;
  }

  const isWhatsappSelected = selectedChannels.value.includes('whatsapp_meta');

  if (isWhatsappSelected && !isWhatsappAllowed.value) {
    store.error = 'WhatsApp no permitido (Revisa la sesión, la configuración de la plantilla o si el número fue bloqueado).';
    return;
  }

  await store.sendMessage(newMessageText.value, selectedTemplateId.value, selectedChannels.value);
  newMessageText.value = '';
  clearTemplate();
  showTemplateDropdown.value = false;

  await nextTick();
  if (messageTextarea.value) {
    messageTextarea.value.style.height = 'auto';
  }
};

const getChannelIcons = (msg: ApiMessage) => {
  if (msg.direction === 'incoming') {
    const channelId = getDirectChannelId(msg.channel);
    if (channelId === 'whatsapp_meta') return [{ class: 'fab fa-whatsapp', color: 'text-green-500' }];
    if (channelId === 'beds24') return [{ class: 'fas fa-bed', color: 'text-[#003580]' }];
    return [{ class: 'fas fa-comment-dots', color: 'text-slate-400' }];
  }

  const icons = [];
  const channelId = getDirectChannelId(msg.channel);

  if (channelId === 'whatsapp_meta') {
    icons.push({ class: 'fab fa-whatsapp', color: 'text-green-500' });
  } else if (channelId === 'beds24') {
    icons.push({ class: 'fas fa-bed', color: 'text-[#003580]' });
  }

  if (icons.length === 0) {
    if (msg.whatsappMetaSendQueues?.length || msg.metadata?.whatsappMeta?.received_at || msg.metadata?.whatsappMeta?.sent_at) {
      icons.push({ class: 'fab fa-whatsapp', color: 'text-green-500' });
    }
    if (msg.beds24SendQueues?.length || msg.metadata?.beds24?.received_at || msg.metadata?.beds24?.sent_at) {
      icons.push({ class: 'fas fa-bed', color: 'text-[#003580]' });
    }
  }

  return icons.length ? icons : [{ class: 'fas fa-comment-dots', color: 'text-slate-400' }];
};

const getTemplateName = (templateData: any) => {
  if (!templateData) return null;
  if (typeof templateData === 'string') {
    const found = store.templates.find(t => t['@id'] === templateData);
    return found ? found.name : 'Plantilla Automática';
  }
  return templateData.name || 'Plantilla Automática';
};

const formatDate = (iso?: string) => {
  if (!iso) return '';
  const [year, month, day] = iso.split('T')[0].split('-').map(Number);
  const date = new Date(year, month - 1, day);
  const currentYear = new Date().getFullYear();
  return date.toLocaleDateString('es-ES', {
    day: '2-digit',
    month: 'short',
    year: date.getFullYear() !== currentYear ? 'numeric' : undefined
  });
};

const formatTime = (iso?: string) => {
  if (!iso) return '';
  const timePart = iso.split('T')[1];
  if (!timePart) return '';
  const [h, m] = timePart.split(':');
  const date = new Date();
  date.setHours(Number(h), Number(m));
  return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
};

const formatFullDate = (iso?: string) => {
  if (!iso) return '';
  const [year, month, day] = iso.split('T')[0].split('-').map(Number);
  const date = new Date(year, month - 1, day);
  return date.toLocaleDateString('es-ES', { day: 'numeric', month: 'long' });
};

const groupedMessages = computed(() => {
  const groups: Record<string, any[]> = {};

  const sourceList = [...(activeTab.value === 'history' ? store.activeChatMessages : store.scheduledMessages)];

  sourceList.sort((a, b) => {
    const dateA = new Date(a.effectiveDateTime || a.createdAt).getTime();
    const dateB = new Date(b.effectiveDateTime || b.createdAt).getTime();
    return dateA - dateB;
  });

  sourceList.forEach(msg => {
    const dateStr = msg.effectiveDateTime || msg.createdAt;
    if(!dateStr) return;

    const dObj = new Date(dateStr);
    const year = dObj.getFullYear();
    const month = String(dObj.getMonth() + 1).padStart(2, '0');
    const day = String(dObj.getDate()).padStart(2, '0');

    const localDateKey = `${year}-${month}-${day}`;

    if (!groups[localDateKey]) {
      groups[localDateKey] = [];
    }
    groups[localDateKey].push(msg);
  });

  return groups;
});

const getOriginClass = (origin?: string | null) => {
  const colors: Record<string, string> = { booking: 'bg-[#003580]', airbnb: 'bg-[#FF5A5F]', expedia: 'bg-[#00355F]' };
  return colors[origin?.toLowerCase() || ''] || 'bg-[#376875]';
};

const isImageAttachment = (att: any): boolean => {
  if (typeof att === 'string') return false;
  if (att.mimeType && att.mimeType.startsWith('image/')) return true;
  const name = att.originalName || att.fileUrl || '';
  return /\.(jpg|jpeg|png|gif|webp)$/i.test(name);
};

const handleAttachmentClick = (att: any) => {
  if (typeof att === 'string') {
    window.open(att, '_blank');
    return;
  }

  if (isImageAttachment(att) && att.fileUrl) {
    previewImageUrl.value = att.fileUrl;
    isPreviewModalOpen.value = true;
  } else if (att.fileUrl) {
    window.open(att.fileUrl, '_blank');
  }
};

const closePreviewModal = () => {
  isPreviewModalOpen.value = false;
  previewImageUrl.value = null;
};

// ============================================================================
// LÓGICA DE TRADUCCIÓN Y FORMATEO DE ENLACES
// ============================================================================
const hasTranslation = (msg: ApiMessage): boolean => {
  if (!msg.contentLocal || !msg.contentExternal) return false;
  return msg.contentLocal.trim() !== msg.contentExternal.trim();
};

const toggleTranslation = (msg: ApiMessage, event?: Event) => {
  if (event && (event.target as HTMLElement).tagName === 'A') {
    return;
  }
  if (!hasTranslation(msg)) return;
  translatedMessages.value[msg.id] = !translatedMessages.value[msg.id];
};

const isShowingTranslation = (msgId: string): boolean => !!translatedMessages.value[msgId];

const formatMessageText = (text: string | null | undefined): string => {
  if (!text) return '';

  const escapeHtml = (unsafe: string) => {
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
  };

  const safeText = escapeHtml(text);

  const urlRegex = /(https?:\/\/[^\s]+)/g;
  return safeText.replace(urlRegex, (url) => {
    return `<a href="${url}" target="_blank" rel="noopener noreferrer" class="underline font-bold hover:opacity-70 transition-opacity">${url}</a>`;
  });
};

const getDispatchError = (msg: ApiMessage, channelKeyword: string): string | null => {
  const meta = msg.metadata;
  if (!meta) return null;
  const searchKey = channelKeyword.toLowerCase();
  if (meta.dispatch_errors) {
    const error = meta.dispatch_errors.find((e: string) => e.toLowerCase().includes(searchKey));
    if (error) return error;
  }
  if (meta.dispatch_warnings) {
    const warning = meta.dispatch_warnings.find((e: string) => e.toLowerCase().includes(searchKey));
    if (warning) return warning;
  }
  return null;
};

const getQueueStatus = (queues?: any[]) => {
  if (!queues || queues.length === 0) return null;
  const lastQueue = queues[queues.length - 1];
  if (typeof lastQueue === 'string') return 'sent';
  if (lastQueue.status === 'failed') return 'failed';
  if (lastQueue.status === 'cancelled') return 'cancelled';
  if (lastQueue.deliveryStatus === 'read') return 'read';
  if (lastQueue.deliveryStatus === 'delivered') return 'delivered';
  return lastQueue.status || 'sent';
};

const getWhatsappStatus = (msg: ApiMessage) => {
  const waMeta = msg.metadata?.whatsappMeta;
  if (waMeta && !Array.isArray(waMeta)) {
    if (waMeta.error_code || waMeta.error_reason) return 'failed';
    if (waMeta.read_at) return 'read';
    if (waMeta.delivered_at) return 'delivered';
    if (waMeta.sent_at) return 'sent';
  }
  return getQueueStatus(msg.whatsappMetaSendQueues) || 'queued';
};

const getBeds24Status = (msg: ApiMessage) => {
  const bedsMeta = msg.metadata?.beds24;
  if (bedsMeta && !Array.isArray(bedsMeta)) {
    if (bedsMeta.error) return 'failed';
    if (bedsMeta.read_at) return 'read';
    if (bedsMeta.delivered_at) return 'delivered';
    if (bedsMeta.sent_at) return 'sent';
  }
  return getQueueStatus(msg.beds24SendQueues) || 'queued';
};

const getDirectChannelId = (channel?: any): string | null => {
  if (!channel) return null;
  if (typeof channel === 'string') {
    if (channel.includes('whatsapp')) return 'whatsapp_meta';
    if (channel.includes('beds24')) return 'beds24';
    return 'unknown';
  }
  return channel.id || null;
};
</script>

<template>
  <div class="fixed inset-0 flex bg-[#F8FAFC] font-sans overflow-hidden text-slate-900 antialiased">

    <Transition name="fade-slide">
      <div v-if="store.isSessionExpired" class="fixed inset-0 z-[300] flex items-center justify-center bg-slate-900/60 backdrop-blur-md p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden border border-slate-200">
          <div class="bg-slate-50 px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="font-black text-slate-800 text-lg flex items-center gap-2">
              <i class="fas fa-lock text-[#E07845]"></i> Sesión Expirada
            </h3>
            <button @click="store.cancelRenewal()" class="text-slate-400 hover:text-slate-600 transition-colors">
              <i class="fas fa-times"></i>
            </button>
          </div>
          <form @submit.prevent="handleSessionRenewal" class="p-6">
            <p class="text-sm text-slate-500 mb-6 leading-relaxed">
              Por seguridad, tu sesión ha caducado. Ingresa tus credenciales para reanudar el trabajo exactamente donde te quedaste.
            </p>

            <div class="space-y-4">
              <div>
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wide mb-1.5">Usuario</label>
                <div class="relative">
                  <i class="fas fa-user absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"></i>
                  <input v-model="loginUsername" type="text" required class="w-full bg-slate-50 border border-slate-200 text-slate-800 rounded-xl pl-10 pr-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#376875]/50 focus:border-[#376875] transition-all text-sm font-medium" placeholder="tu_usuario">
                </div>
              </div>

              <div>
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wide mb-1.5">Contraseña</label>
                <div class="relative">
                  <i class="fas fa-key absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"></i>
                  <input v-model="loginPassword" type="password" required class="w-full bg-slate-50 border border-slate-200 text-slate-800 rounded-xl pl-10 pr-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#376875]/50 focus:border-[#376875] transition-all text-sm font-medium" placeholder="••••••••">
                </div>
              </div>
            </div>

            <div v-if="store.error && store.isSessionExpired" class="mt-4 text-xs font-bold text-red-500 bg-red-50 p-3 rounded-lg flex items-center gap-2">
              <i class="fas fa-exclamation-circle shrink-0"></i>
              <span>{{ store.error }}</span>
            </div>

            <div class="mt-8 flex gap-3">
              <button type="button" @click="store.cancelRenewal()" class="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 hover:bg-slate-200 font-bold rounded-xl text-sm transition-colors">
                Cancelar
              </button>
              <button type="submit" :disabled="isLoggingIn || !loginUsername || !loginPassword" class="flex-1 px-4 py-2.5 bg-[#376875] text-white hover:bg-[#2c535d] font-bold rounded-xl text-sm transition-colors disabled:opacity-50 flex items-center justify-center gap-2 shadow-md">
                <i v-if="isLoggingIn" class="fas fa-circle-notch fa-spin"></i>
                <span v-else>Entrar</span>
              </button>
            </div>
          </form>
        </div>
      </div>
    </Transition>

    <Transition name="fade-slide">
      <div v-if="store.error && !store.isSessionExpired" class="fixed top-8 left-1/2 -translate-x-1/2 z-[100] bg-slate-900 text-white px-6 py-3 rounded-2xl shadow-2xl flex items-center gap-4 backdrop-blur-xl border border-white/10 max-w-[90vw] text-center">
        <div class="w-2 h-2 rounded-full bg-red-500 animate-pulse shrink-0"></div>
        <span class="text-xs font-black uppercase tracking-wide leading-tight">{{ store.error }}</span>
      </div>
    </Transition>

    <aside class="fixed inset-y-0 left-0 z-40 w-full md:relative md:w-80 lg:w-[380px] bg-white border-r border-slate-200 flex flex-col md:translate-x-0" :class="[isMobileSidebarOpen ? 'translate-x-0' : '-translate-x-full', isTransitioning ? 'transition-transform duration-300 ease-in-out' : '']">
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
          <button @click="selectChat(chat.id)"
                  class="w-full text-left p-3 rounded-2xl transition-all flex gap-3 relative group border border-transparent items-center"
                  :class="store.currentConversation?.id === chat.id ? 'bg-white border-slate-200 shadow-xl translate-x-1' : 'hover:bg-slate-50'">

            <span v-if="store.currentConversation?.id === chat.id" class="absolute left-0 top-3 bottom-3 w-1.5 bg-[#376875] rounded-r-full block"></span>

            <span class="w-11 h-11 rounded-xl text-white flex items-center justify-center shrink-0 font-black text-lg shadow-sm relative" :class="getOriginClass(chat.contextOrigin)">
              {{ chat.guestName?.charAt(0).toUpperCase() || '?' }}
              <span v-if="chat.whatsappDisabled" class="absolute -bottom-1 -right-1 bg-white rounded-full p-0.5 shadow-sm">
                 <i class="fas fa-exclamation-triangle text-red-500 text-[10px]" title="WhatsApp bloqueado/inválido"></i>
              </span>
            </span>

            <span class="flex-1 min-w-0 flex flex-col justify-center">
              <span class="font-bold truncate text-sm leading-tight mb-1" :class="store.currentConversation?.id === chat.id ? 'text-[#376875]' : 'text-slate-800'">
                {{ chat.guestName || 'Huésped' }}
              </span>

              <span class="flex flex-col gap-[5px]">
                <span class="text-[10px] font-black truncate text-[#E07845] uppercase tracking-tight leading-none">
                  {{ chat.contextItems?.length ? chat.contextItems.join(', ') : (chat.contextOrigin === 'whatsapp' ? 'Chat Directo' : 'Reserva PMS') }}
                </span>
                <span v-if="chat.contextMilestones?.start && chat.contextMilestones?.end" class="text-[10px] font-bold text-slate-400 flex items-center gap-1 truncate leading-none">
                  <i class="far fa-calendar-alt opacity-70 text-[8px]"></i>
                  {{ formatDate(chat.contextMilestones.start) }} - {{ formatDate(chat.contextMilestones.end) }}
                </span>
              </span>
            </span>

            <span class="flex flex-col items-end text-slate-400 shrink-0 gap-0.5 self-start pt-0.5">
              <span class="text-[11px] font-black uppercase leading-none">{{ formatDate(chat.lastMessageAt || chat.createdAt) }}</span>
              <span class="text-[10px] font-bold opacity-70 tracking-tighter leading-none">{{ formatTime(chat.lastMessageAt || chat.createdAt) }}</span>
            </span>

            <span v-if="chat.unreadCount > 0" class="absolute -right-1 -top-1 w-5 h-5 bg-[#E07845] text-white text-[10px] font-black rounded-full flex items-center justify-center border-2 border-white shadow-md">
              {{ chat.unreadCount }}
            </span>
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
        <div class="flex flex-col shrink-0 sticky top-0 z-30 shadow-sm">
          <header class="h-16 md:h-24 bg-white/90 backdrop-blur-md border-b border-slate-200 px-4 md:px-8 flex items-center justify-between">
            <div class="flex items-center gap-4 overflow-hidden">
              <button @click="closeMobileChat" class="md:hidden w-10 h-10 flex items-center justify-center bg-slate-50 rounded-xl text-slate-500 shadow-sm transition-colors"><i class="fas fa-chevron-left"></i></button>
              <div class="truncate">
                <h2 class="font-black text-slate-900 text-lg md:text-2xl tracking-tight truncate leading-none mb-1">
                  {{ store.currentConversation?.guestName || 'Huésped Sin Nombre' }}
                </h2>
                <div class="flex items-center gap-3 text-[10px] md:text-[11px] font-black uppercase tracking-widest text-slate-400">
                  <span class="text-[#E07845]">
                    {{ store.currentConversation?.contextItems?.length ? store.currentConversation.contextItems.join(' + ') : (store.currentConversation?.contextOrigin === 'whatsapp' ? 'CHAT DIRECTO' : 'PMS') }}
                  </span>
                  <span class="text-slate-200">/</span>
                  <span class="hidden sm:inline">{{ formatFullDate(store.currentConversation?.contextMilestones?.start) }} - {{ formatFullDate(store.currentConversation?.contextMilestones?.end) }}</span>
                  <span class="inline sm:hidden truncate">{{ formatDate(store.currentConversation?.contextMilestones?.start) }} - {{ formatDate(store.currentConversation?.contextMilestones?.end) }}</span>
                </div>
              </div>
            </div>

            <div class="flex items-center gap-3 shrink-0 ml-4">
              <span v-if="store.currentConversation?.contextStatusTag" class="hidden lg:inline-flex items-center px-2.5 py-1 bg-slate-100 border border-slate-200 text-slate-500 rounded-lg text-[9px] font-black uppercase tracking-widest">
                {{ store.currentConversation.contextStatusTag }}
              </span>
              <a v-if="store.getExternalContextUrl" :href="store.getExternalContextUrl" target="_blank" class="flex items-center gap-2 px-3 py-2 bg-slate-900 text-white rounded-xl shadow-xl hover:-translate-y-0.5 hover:shadow-2xl hover:bg-slate-800 transition-all text-[10px] font-black uppercase tracking-wider">
                <i class="fas fa-external-link-alt"></i><span class="hidden md:inline">Ver Reserva</span>
              </a>
            </div>
          </header>

          <div v-if="store.scheduledMessages.length > 0" class="bg-slate-50 border-b border-slate-200 px-4 md:px-8 py-2 flex gap-6 text-xs font-black uppercase tracking-widest">
            <button @click="activeTab = 'history'" class="pb-1 transition-colors" :class="activeTab === 'history' ? 'text-[#376875] border-b-2 border-[#376875]' : 'text-slate-400 hover:text-slate-600'">
              <i class="fas fa-history mr-1"></i> Historial
            </button>
            <button @click="activeTab = 'scheduled'" class="pb-1 transition-colors" :class="activeTab === 'scheduled' ? 'text-[#E07845] border-b-2 border-[#E07845]' : 'text-slate-400 hover:text-slate-600'">
              <i class="far fa-calendar-alt mr-1"></i> Programados ({{ store.scheduledMessages.length }})
            </button>
          </div>
        </div>

        <div class="flex-1 overflow-y-auto p-4 md:px-12 md:py-8" ref="messagesContainer" @scroll="onMessageScroll">
          <div v-if="store.loadingMoreMessages" class="flex justify-center py-4">
            <i class="fas fa-circle-notch fa-spin text-slate-300"></i>
          </div>

          <div class="max-w-4xl mx-auto flex flex-col">
            <div v-if="activeTab === 'scheduled' && store.scheduledMessages.length === 0" class="text-center py-10 opacity-50">
              <i class="far fa-check-circle text-4xl mb-3 block"></i>
              <p class="text-sm font-bold uppercase tracking-widest">No hay envíos pendientes</p>
            </div>

            <div v-for="(group, date) in groupedMessages" :key="date" class="flex flex-col">
              <div class="flex justify-center my-6 sticky top-2 z-10">
                <span :class="activeTab === 'scheduled' ? 'bg-[#E07845] text-white' : 'bg-white/90 text-slate-800'" class="px-4 py-1.5 backdrop-blur-md border border-slate-200 shadow-sm rounded-full text-[10px] font-black uppercase tracking-widest">
                  {{
                    activeTab === 'history' && date === `${new Date().getFullYear()}-${String(new Date().getMonth() + 1).padStart(2, '0')}-${String(new Date().getDate()).padStart(2, '0')}`
                        ? 'Hoy'
                        : formatDate(date + 'T00:00:00')
                  }}
                </span>
              </div>
              <div class="space-y-6 flex flex-col mb-8">
                <div v-for="msg in group" :key="msg.id" class="flex w-full" :class="msg.direction === 'outgoing' ? 'justify-end' : 'justify-start'">

                  <div
                      class="relative max-w-[85%] md:max-w-[70%] flex flex-col"
                      :class="[
                      msg.direction === 'outgoing' ? 'items-end pl-10' : 'items-start pr-10',
                      getMessageDisplayStatus(msg) === 'cancelled' ? 'msg-cancelled' : ''
                    ]"
                  >

                    <div class="absolute top-1 flex flex-col gap-1.5" :class="msg.direction === 'outgoing' ? 'left-0' : 'right-0'">
                      <div v-for="icon in getChannelIcons(msg)" :key="icon.class" class="w-8 h-8 rounded-full bg-white shadow-md flex items-center justify-center border border-slate-100">
                        <i :class="[icon.class, icon.color]" class="text-sm"></i>
                      </div>
                    </div>

                    <div v-if="msg.template && msg.direction === 'outgoing'" :class="activeTab === 'scheduled' ? 'bg-orange-50 border-orange-200' : 'bg-slate-100 border-slate-200 text-slate-600'" class="rounded-2xl p-4 border text-sm font-medium leading-relaxed relative w-full shadow-sm text-center">
                      <i class="fas fa-robot text-lg mb-2 block opacity-50"></i>
                      <span class="block text-[10px] font-black uppercase tracking-widest opacity-80 mb-2">{{ getTemplateName(msg.template) }}</span>
                      <span class="opacity-90 italic">"{{ msg.contentLocal || msg.contentExternal }}"</span>
                    </div>

                    <div v-else :class="[
                      msg.direction === 'outgoing' ? 'rounded-tr-none' : 'rounded-tl-none',
                      activeTab === 'scheduled' ? 'bg-orange-50 border-2 border-orange-200 text-slate-800 shadow-sm' :
                      (msg.direction === 'outgoing' ? 'bg-[#376875] text-white shadow-lg' : 'bg-white border border-slate-200 text-slate-800 shadow-sm')
                    ]" class="rounded-3xl p-4 md:p-5 text-sm md:text-base font-medium leading-relaxed whitespace-pre-wrap relative w-full break-words">

                      <div v-if="msg.attachments?.length" class="mb-3 space-y-2">
                        <div
                            v-for="att in msg.attachments"
                            :key="(typeof att === 'string') ? att : att.id"
                            @click="handleAttachmentClick(att)"
                            class="flex items-center gap-3 p-3 rounded-xl bg-black/10 cursor-pointer hover:bg-black/20 transition-colors"
                        >
                          <div class="w-10 h-10 bg-white/20 rounded-lg flex items-center justify-center shrink-0 overflow-hidden">
                            <img v-if="typeof att !== 'string' && att.fileUrl && isImageAttachment(att)" :src="att.fileUrl ?? undefined" class="w-full h-full object-cover" />
                            <i v-else class="fas fa-file-alt text-lg"></i>
                          </div>
                          <div class="min-w-0">
                            <p class="text-xs font-bold truncate">{{ (typeof att === 'string') ? 'Archivo Adjunto' : att.originalName || 'Archivo Adjunto' }}</p>
                            <p class="text-[9px] font-mono opacity-70">{{ (typeof att === 'string') ? 'Documento' : att.mimeType || 'Documento' }}</p>
                          </div>
                        </div>
                      </div>

                      <div
                          @click="toggleTranslation(msg, $event)"
                          class="transition-opacity select-none"
                          :class="hasTranslation(msg) ? 'cursor-pointer hover:opacity-90' : ''"
                          :title="hasTranslation(msg) ? 'Toca para alternar traducción' : ''"
                      >
                        <template v-if="isShowingTranslation(msg.id)">
                          <i class="fas fa-globe text-[12px] opacity-70 mr-1.5"></i>
                          <span v-html="formatMessageText(msg.contentExternal)"></span>
                        </template>
                        <template v-else>
                          <span v-html="formatMessageText(msg.contentLocal || msg.contentExternal || 'Mensaje enviado')"></span>
                          <i v-if="hasTranslation(msg)" class="fas fa-language text-[12px] opacity-40 ml-1.5 hover:opacity-100 transition-opacity"></i>
                        </template>
                      </div>

                    </div>

                    <div class="flex items-center gap-1.5 mt-1.5 px-2 text-[10px] font-black uppercase tracking-tighter" :class="[msg.direction === 'outgoing' ? 'flex-row-reverse' : 'flex-row', activeTab === 'scheduled' ? 'text-orange-400' : 'text-slate-400']">

                      <span>{{ formatTime(msg.effectiveDateTime || msg.createdAt) }}</span>

                      <template v-if="msg.direction === 'outgoing'">

                        <template v-if="msg.channel">
                          <div class="flex items-center gap-1 text-slate-400" title="Mensaje enviado desde plataforma externa (Sincronizado)">
                            <i class="fas fa-cloud-download-alt text-[9px] opacity-50 mr-0.5" title="Sincronizado externamente"></i>
                            <i v-if="getDirectChannelId(msg.channel) === 'beds24'" class="fas fa-bed text-[#003580] opacity-60 text-[9px]"></i>
                            <i v-else-if="getDirectChannelId(msg.channel) === 'whatsapp_meta'" class="fab fa-whatsapp text-green-500 opacity-70 text-[10px]"></i>
                            <MessageStatusIcon :status="getMessageDisplayStatus(msg)" />
                          </div>
                        </template>

                        <template v-else>
                          <div class="flex items-center gap-2">

                            <span v-if="getDispatchError(msg, 'whatsapp') || msg.whatsappMetaSendQueues?.length > 0 || msg.metadata?.whatsappMeta?.sent_at || msg.metadata?.whatsappMeta?.error_code"
                                  class="flex items-center gap-0.5 ml-2 cursor-help"
                                  :title="getDispatchError(msg, 'whatsapp') || 'WhatsApp'">

                              <i class="fab fa-whatsapp text-[10px]"
                                 :class="getDispatchError(msg, 'whatsapp') ? 'text-red-500' : 'text-green-500 opacity-70'"></i>

                              <i v-if="getDispatchError(msg, 'whatsapp')" class="fas fa-exclamation-circle text-red-500 text-[9px]"></i>
                              <MessageStatusIcon v-else :status="getWhatsappStatus(msg)" />
                            </span>

                            <span v-if="getDispatchError(msg, 'beds24') || msg.beds24SendQueues?.length > 0 || msg.metadata?.beds24?.sent_at || msg.metadata?.beds24?.error"
                                  class="flex items-center gap-0.5 ml-2 cursor-help"
                                  :title="getDispatchError(msg, 'beds24') || 'Beds24'">

                              <i class="fas fa-bed text-[9px]"
                                 :class="getDispatchError(msg, 'beds24') ? 'text-red-500' : 'text-[#003580] opacity-60'"></i>

                              <i v-if="getDispatchError(msg, 'beds24')" class="fas fa-exclamation-circle text-red-500 text-[9px]"></i>
                              <MessageStatusIcon v-else :status="getBeds24Status(msg)" />
                            </span>

                            <span v-if="(!msg.whatsappMetaSendQueues?.length && !msg.metadata?.whatsappMeta?.sent_at && !getDispatchError(msg, 'whatsapp')) && (!msg.beds24SendQueues?.length && !msg.metadata?.beds24?.sent_at && !getDispatchError(msg, 'beds24'))">
                              <MessageStatusIcon :status="getMessageDisplayStatus(msg)" />
                            </span>

                          </div>
                        </template>

                      </template>

                      <template v-else>
                        <span class="flex items-center gap-1 opacity-50" :title="'Recibido vía: ' + (getDirectChannelId(msg.channel) || 'Desconocido')">
                          <i v-if="getDirectChannelId(msg.channel) === 'beds24'" class="fas fa-bed text-[#003580] text-[9px]"></i>
                          <i v-else-if="getDirectChannelId(msg.channel) === 'whatsapp_meta'" class="fab fa-whatsapp text-green-600 text-[10px]"></i>
                        </span>
                      </template>

                    </div>

                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <footer v-show="activeTab === 'history'" class="bg-white border-t border-slate-100 p-3 md:p-6 shrink-0 relative flex flex-col gap-3 min-w-0 pb-[calc(0.75rem+env(safe-area-inset-bottom,0px))]">

          <div class="flex items-center gap-2 px-1 max-w-4xl mx-auto w-full overflow-x-auto scrollbar-hide">
            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest shrink-0"><i class="fas fa-satellite-dish mr-1"></i> Salida:</span>

            <button
                @click="isBeds24Allowed ? toggleChannel('beds24') : null"
                :disabled="!isBeds24Allowed"
                :class="[
                  !isBeds24Allowed
                    ? 'bg-slate-50 text-slate-300 border-slate-100 opacity-60 cursor-not-allowed'
                    : selectedChannels.includes('beds24')
                      ? 'bg-[#003580]/10 text-[#003580] border-[#003580]/30 shadow-sm'
                      : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50 hover:text-slate-800 shadow-sm'
                ]"
                class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all border flex items-center gap-2 shrink-0"
                :title="!isBeds24Allowed ? 'Beds24 no está disponible o la plantilla lo tiene inactivo' : ''"
            >
              <i class="fas fa-bed"></i> Beds24
              <i v-if="!isBeds24Allowed" class="fas fa-ban text-[9px] ml-0.5 opacity-50"></i>
            </button>

            <div class="relative group">
              <button
                  @click="isWhatsappAllowed ? toggleChannel('whatsapp_meta') : null"
                  :disabled="!isWhatsappAllowed"
                  :class="[
                    store.currentConversation?.whatsappDisabled
                      ? 'bg-red-50 text-red-400 border-red-100 opacity-60 cursor-not-allowed'
                      : !isWhatsappAllowed
                        ? 'bg-slate-50 text-slate-300 border-slate-100 opacity-60 cursor-not-allowed'
                        : selectedChannels.includes('whatsapp_meta')
                          ? 'bg-green-50 text-green-700 border-green-200 shadow-sm'
                          : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50 hover:text-slate-800 shadow-sm'
                  ]"
                  class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all border flex items-center gap-2 shrink-0 relative"
              >
                <i class="fab fa-whatsapp text-sm"></i> WhatsApp

                <i v-if="store.currentConversation?.whatsappDisabled" class="fas fa-exclamation-triangle text-red-500 animate-pulse ml-1 text-[10px]" title="Canal Bloqueado"></i>
                <i v-else-if="!store.currentConversation?.whatsappSessionActive" class="fas fa-lock text-[10px] ml-1" :class="selectedChannels.includes('whatsapp_meta') ? 'text-green-700/50' : 'text-slate-400'" title="Sesión de 24h inactiva"></i>
              </button>

              <div v-if="store.currentConversation?.whatsappDisabled" class="absolute bottom-full mb-2 left-1/2 -translate-x-1/2 hidden group-hover:block bg-red-600 text-white text-[9px] p-2 rounded shadow-lg z-[100] w-48 text-center font-black uppercase tracking-tighter whitespace-normal">
                {{ store.currentConversation?.whatsappDisabledReason || 'Canal bloqueado por error de Meta' }}
              </div>
            </div>

          </div>

          <Transition name="fade-slide">
            <div v-if="showTemplateDropdown" class="absolute bottom-[90px] left-2 right-2 md:left-auto md:right-auto z-50 bg-white border border-slate-200 shadow-2xl rounded-2xl p-2 md:w-96 max-h-64 overflow-y-auto">
              <div class="px-3 py-2 text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 mb-2 flex justify-between items-center">
                <span>Plantillas Permitidas</span>
                <button @click="showTemplateDropdown = false" class="text-slate-300 hover:text-red-400"><i class="fas fa-times"></i></button>
              </div>
              <button
                  v-for="tpl in store.validTemplates"
                  :key="tpl.id"
                  @click="selectTemplate(tpl)"
                  class="w-full text-left px-4 py-3 hover:bg-slate-50 rounded-xl transition-colors mb-1 group flex items-center justify-between"
              >
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
                <button type="button" @click="clearTemplate()" class="w-6 h-6 shrink-0 rounded-full hover:bg-red-50 text-red-400 flex items-center justify-center ml-2"><i class="fas fa-times text-xs"></i></button>
              </div>
              <textarea
                  v-else
                  ref="messageTextarea"
                  v-model="newMessageText"
                  @input="adjustTextareaHeight"
                  placeholder="Escribe tu mensaje..."
                  class="w-full bg-transparent border-0 focus:ring-0 resize-none py-2 px-2 text-sm font-semibold text-slate-800 scrollbar-hide overflow-y-auto"
                  rows="1"
              ></textarea>
            </div>

            <button type="submit" :disabled="(!newMessageText.trim() && !selectedTemplateId && !attachmentStore.file) || store.sendingMessage" class="w-10 h-10 shrink-0 bg-[#E07845] text-white rounded-full flex items-center justify-center shadow-md hover:scale-105 transition-all disabled:opacity-30 mb-0.5">
              <i class="fas text-xs" :class="store.sendingMessage ? 'fa-circle-notch fa-spin' : 'fa-paper-plane'"></i>
            </button>
          </form>
        </footer>
      </template>
    </main>

    <Transition name="fade-slide">
      <div
          v-if="isPreviewModalOpen"
          class="fixed inset-0 z-[200] flex items-center justify-center bg-black/80 backdrop-blur-sm p-4 cursor-pointer"
          @click="closePreviewModal"
      >
        <button
            @click="closePreviewModal"
            class="absolute top-4 right-4 text-white hover:text-red-400 bg-black/50 hover:bg-black/70 rounded-full w-10 h-10 flex items-center justify-center transition-all shadow-lg"
        >
          <i class="fas fa-times text-xl"></i>
        </button>
        <img
            v-if="previewImageUrl"
            :src="previewImageUrl"
            class="max-w-full max-h-full object-contain rounded-xl shadow-2xl cursor-default"
            @click.stop
        />
      </div>
    </Transition>

    <Transition name="fade-slide">
      <div
          v-if="store.newNotification"
          @click="selectChat(store.newNotification.conversationId)"
          class="fixed top-4 right-4 md:top-6 md:right-8 z-[150] bg-white border border-slate-200 shadow-2xl rounded-2xl p-4 flex items-center gap-4 cursor-pointer hover:bg-slate-50 hover:scale-105 transition-all max-w-sm w-full"
      >
        <div class="w-10 h-10 rounded-full bg-green-500 flex items-center justify-center text-white shrink-0 shadow-md">
          <i class="fab fa-whatsapp text-lg"></i>
        </div>
        <div class="min-w-0 flex-1">
          <p class="text-[10px] font-black text-green-600 uppercase tracking-widest mb-0.5">Nuevo Mensaje</p>
          <p class="text-sm font-bold text-slate-800 truncate">{{ store.newNotification.title }}</p>
        </div>
        <button @click.stop="store.newNotification = null" class="w-8 h-8 rounded-full flex items-center justify-center text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors shrink-0">
          <i class="fas fa-times"></i>
        </button>
      </div>
    </Transition>

  </div>
</template>

<style scoped>
.scrollbar-hide::-webkit-scrollbar { display: none; }
.fade-slide-enter-active, .fade-slide-leave-active { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
.fade-slide-enter-from, .fade-slide-leave-to { opacity: 0; transform: translateY(10px) scale(0.98); }
.animate-fade-in { animation: fadeIn 0.2s ease-out forwards; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
textarea { outline: none; }
.whitespace-pre-wrap span { white-space: pre-wrap; word-break: break-word; }

.msg-cancelled {
  opacity: 0.45;
  transition: opacity 0.2s ease;
}
.msg-cancelled:hover {
  opacity: 0.65;
}
</style>