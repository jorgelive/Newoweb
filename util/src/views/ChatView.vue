<script setup lang="ts">
import { ref, onMounted, watch, nextTick, computed } from 'vue';
import { useChatStore } from '@/stores/chatStore';

const store = useChatStore();

// Referencias del DOM y UI
const messagesContainer = ref<HTMLElement | null>(null);
const newMessageText = ref('');
const isMobileSidebarOpen = ref(true); // Para responsive: controla si vemos la lista o el chat

// --- LIFECYCLE ---
onMounted(() => {
  store.fetchConversations();
});

// --- SCROLL AUTOMÁTICO ---
const scrollToBottom = () => {
  if (messagesContainer.value) {
    messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
  }
};

// Observamos cambios en la lista de mensajes para hacer scroll hacia abajo
watch(() => store.messages, async () => {
  await nextTick();
  scrollToBottom();
}, { deep: true });

// --- MÉTODOS ---
const selectChat = async (id: string) => {
  await store.selectConversation(id);
  isMobileSidebarOpen.value = false; // Oculta sidebar en móviles al seleccionar
};

const send = async () => {
  if (!newMessageText.value.trim()) return;
  const textToSend = newMessageText.value;
  newMessageText.value = ''; // Limpiamos el input inmediatamente por UX
  await store.sendMessage(textToSend);
};

const goBackToList = () => {
  store.currentConversation = null;
  isMobileSidebarOpen.value = true;
};

// --- FORMATTERS ---
const formatTime = (isoString: string) => {
  if (!isoString) return '';
  const date = new Date(isoString);
  return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
};

const formatDate = (isoString: string) => {
  if (!isoString) return '';
  const date = new Date(isoString);
  return date.toLocaleDateString([], { day: '2-digit', month: 'short' });
};

// --- COMPUTADOS ---
const activeConversationId = computed(() => store.currentConversation?.id);
</script>

<template>
  <div class="flex h-screen bg-slate-50 font-sans overflow-hidden">

    <aside
        class="w-full md:w-80 lg:w-96 bg-white border-r border-slate-200 flex flex-col transition-all duration-300 z-10"
        :class="isMobileSidebarOpen ? 'block' : 'hidden md:flex'"
    >
      <div class="p-4 border-b border-slate-100 bg-white flex justify-between items-center shrink-0 h-16">
        <h1 class="font-black text-xl text-slate-800">Mensajes</h1>
        <button @click="store.fetchConversations" class="w-8 h-8 rounded-full bg-slate-100 text-slate-500 hover:bg-[#376875] hover:text-white transition-colors flex items-center justify-center">
          <i class="fas fa-sync-alt text-xs" :class="{'fa-spin': store.loadingConversations}"></i>
        </button>
      </div>

      <div class="flex-1 overflow-y-auto scrollbar-hide">
        <div v-if="store.loadingConversations" class="p-8 text-center text-slate-400">
          <i class="fas fa-circle-notch fa-spin text-2xl mb-2 text-[#376875]"></i>
          <p class="text-sm">Cargando chats...</p>
        </div>

        <div v-else-if="store.conversations.length === 0" class="p-8 text-center text-slate-400">
          <div class="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fas fa-inbox text-2xl text-slate-300"></i>
          </div>
          <p class="text-sm font-medium">No hay conversaciones activas.</p>
        </div>

        <ul v-else class="divide-y divide-slate-50">
          <li v-for="chat in store.conversations" :key="chat.id">
            <button
                @click="selectChat(chat.id)"
                class="w-full text-left p-4 hover:bg-slate-50 transition-colors flex items-start gap-3 relative"
                :class="{'bg-slate-50 border-l-4 border-[#E07845]': activeConversationId === chat.id}"
            >
              <div class="w-12 h-12 rounded-full bg-gradient-to-br from-[#376875] to-slate-600 text-white flex items-center justify-center shrink-0 font-bold shadow-sm">
                {{ chat.guestName ? chat.guestName.charAt(0).toUpperCase() : '?' }}
              </div>
              <div class="flex-1 min-w-0">
                <div class="flex justify-between items-baseline mb-1">
                  <h3 class="font-bold text-slate-900 truncate" :class="{'text-[#376875]': activeConversationId === chat.id}">
                    {{ chat.guestName || 'Huésped' }}
                  </h3>
                  <span class="text-[10px] text-slate-400 whitespace-nowrap ml-2">
                                        {{ formatDate(chat.createdAt) }}
                                    </span>
                </div>
                <p class="text-xs text-slate-500 truncate flex items-center gap-1">
                  <i class="fas fa-hashtag text-[9px] text-slate-300"></i>
                  {{ chat.contextId || 'Sin Reserva' }}
                </p>
              </div>
            </button>
          </li>
        </ul>
      </div>
    </aside>

    <main
        class="flex-1 bg-[#F8FAFC] flex flex-col relative"
        :class="!isMobileSidebarOpen ? 'block' : 'hidden md:flex'"
    >
      <div v-if="!store.currentConversation" class="absolute inset-0 flex flex-col items-center justify-center text-slate-400 bg-white/50">
        <div class="w-24 h-24 bg-slate-50 rounded-full flex items-center justify-center mb-6 shadow-inner border border-slate-100">
          <i class="fas fa-comments text-4xl text-slate-300"></i>
        </div>
        <h2 class="text-xl font-black text-slate-700">Central de Mensajería</h2>
        <p class="text-sm mt-2">Selecciona una conversación del panel izquierdo.</p>
      </div>

      <template v-else>
        <header class="h-16 bg-white border-b border-slate-200 px-6 flex items-center justify-between shrink-0 shadow-sm z-10">
          <div class="flex items-center gap-4">
            <button @click="goBackToList" class="md:hidden w-8 h-8 flex items-center justify-center text-slate-500 bg-slate-100 rounded-full">
              <i class="fas fa-chevron-left"></i>
            </button>
            <div>
              <h2 class="font-black text-slate-800 text-lg leading-tight">
                {{ store.currentConversation.guestName || 'Huésped' }}
              </h2>
              <p class="text-[11px] text-[#E07845] font-bold tracking-wide uppercase">
                <i class="fas fa-bed mr-1"></i> {{ store.currentConversation.contextId }}
              </p>
            </div>
          </div>
          <div class="flex items-center gap-2">
                        <span class="px-2 py-1 bg-green-50 text-green-600 text-[10px] font-bold uppercase rounded-md border border-green-100">
                            {{ store.currentConversation.status }}
                        </span>
          </div>
        </header>

        <div class="flex-1 overflow-y-auto p-4 md:p-6 scrollbar-hide scroll-smooth" ref="messagesContainer">
          <div v-if="store.loadingMessages" class="flex justify-center py-10">
                        <span class="px-4 py-2 bg-white rounded-full shadow-sm text-xs font-bold text-[#376875] border border-slate-100 animate-pulse">
                            Cargando historial...
                        </span>
          </div>

          <div v-else class="space-y-4 max-w-3xl mx-auto flex flex-col">
            <div
                v-for="msg in store.messages"
                :key="msg.id"
                class="flex flex-col w-full"
            >
              <div v-if="msg.direction === 'outgoing'" class="self-end max-w-[85%] md:max-w-[70%]">
                <div class="bg-[#376875] text-white p-3 rounded-2xl rounded-tr-sm shadow-sm relative group">
                  <p class="text-sm whitespace-pre-wrap leading-relaxed">{{ msg.contentLocal || msg.contentExternal }}</p>
                </div>
                <div class="flex justify-end items-center gap-1 mt-1 px-1">
                  <span class="text-[10px] text-slate-400">{{ formatTime(msg.createdAt) }}</span>
                  <i class="fas fa-check-double text-[10px]" :class="msg.status === 'read' ? 'text-[#E07845]' : 'text-slate-300'"></i>
                </div>
              </div>

              <div v-else class="self-start max-w-[85%] md:max-w-[70%]">
                <div class="bg-white border border-slate-100 text-slate-800 p-3 rounded-2xl rounded-tl-sm shadow-sm">
                  <p class="text-sm whitespace-pre-wrap leading-relaxed">{{ msg.contentLocal || msg.contentExternal }}</p>
                </div>
                <div class="flex justify-start items-center mt-1 px-1">
                  <span class="text-[10px] text-slate-400">{{ formatTime(msg.createdAt) }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <footer class="bg-white border-t border-slate-200 p-4 shrink-0">
          <form @submit.prevent="send" class="max-w-3xl mx-auto flex items-end gap-2 bg-slate-50 border border-slate-200 p-1.5 rounded-3xl focus-within:border-[#376875]/50 focus-within:ring-2 focus-within:ring-[#376875]/10 transition-all">

            <button type="button" class="w-10 h-10 shrink-0 text-slate-400 hover:text-[#376875] rounded-full transition-colors flex items-center justify-center">
              <i class="fas fa-paperclip"></i>
            </button>

            <textarea
                v-model="newMessageText"
                @keydown.enter.exact.prevent="send"
                placeholder="Escribe un mensaje..."
                class="flex-1 bg-transparent border-0 focus:ring-0 resize-none max-h-32 min-h-[40px] py-2.5 text-sm text-slate-800 placeholder-slate-400 scrollbar-hide"
                rows="1"
            ></textarea>

            <button
                type="submit"
                :disabled="!newMessageText.trim() || store.sendingMessage"
                class="w-10 h-10 shrink-0 bg-[#E07845] text-white rounded-full flex items-center justify-center hover:bg-orange-600 disabled:opacity-50 disabled:cursor-not-allowed transition-all shadow-sm"
            >
              <i class="fas" :class="store.sendingMessage ? 'fa-circle-notch fa-spin' : 'fa-paper-plane'"></i>
            </button>
          </form>
          <div class="text-center mt-2">
            <span class="text-[9px] text-slate-400 font-medium">Presiona <b>Enter</b> para enviar, <b>Shift + Enter</b> para salto de línea.</span>
          </div>
        </footer>
      </template>
    </main>
  </div>
</template>

<style scoped>
.scrollbar-hide::-webkit-scrollbar { display: none; }
.scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
textarea { outline: none; }
</style>