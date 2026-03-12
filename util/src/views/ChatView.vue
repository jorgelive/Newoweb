<script setup lang="ts">
import { ref, onMounted, watch, nextTick, computed } from 'vue';
import { useChatStore } from '@/stores/chatStore';

const store = useChatStore();
const messagesContainer = ref<HTMLElement | null>(null);
const newMessageText = ref('');
const isMobileSidebarOpen = ref(true);

onMounted(() => store.fetchConversations());

const scrollToBottom = () => { if (messagesContainer.value) messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight; };
watch(() => store.messages, async () => { await nextTick(); scrollToBottom(); }, { deep: true });
watch(() => store.error, (v) => { if (v) setTimeout(() => store.error = null, 5000); });

const selectChat = async (id: string) => {
  await store.selectConversation(id);
  isMobileSidebarOpen.value = false;
};

const send = async () => {
  if (!newMessageText.value.trim()) return;
  const t = newMessageText.value; newMessageText.value = '';
  await store.sendMessage(t);
};

const formatTime = (iso?: string) => iso ? new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
const formatDate = (iso?: string) => iso ? new Date(iso).toLocaleDateString('es-ES', { day: '2-digit', month: 'short' }) : '';
const formatFullDate = (iso?: string) => iso ? new Date(iso).toLocaleDateString('es-ES', { day: 'numeric', month: 'long' }) : '';

const groupedMessages = computed(() => {
  const groups: Record<string, any[]> = {};
  store.messages.forEach(msg => {
    const d = new Date(msg.createdAt).toISOString().split('T')[0];
    if (!groups[d]) groups[d] = [];
    groups[d].push(msg);
  });
  return groups;
});

const formatDividerDate = (d: string) => {
  const today = new Date().toISOString().split('T')[0];
  if (d === today) return 'Hoy';
  return new Date(d).toLocaleDateString('es-ES', { weekday: 'long', day: 'numeric', month: 'short' });
};

const getOriginClass = (origin?: string | null) => {
  const colors: Record<string, string> = { booking: 'bg-[#003580]', airbnb: 'bg-[#FF5A5F]', expedia: 'bg-[#00355F]' };
  return colors[origin || ''] || 'bg-[#376875]';
};
</script>

<template>
  <div class="flex h-screen bg-[#F8FAFC] font-sans overflow-hidden relative text-slate-900 antialiased">

    <Transition name="fade-slide">
      <div v-if="store.error" class="fixed top-8 left-1/2 -translate-x-1/2 z-[100] bg-slate-900 text-white px-6 py-3 rounded-2xl shadow-2xl flex items-center gap-4 border border-white/10 backdrop-blur-xl">
        <div class="w-2 h-2 rounded-full bg-red-500 animate-pulse"></div>
        <span class="text-xs font-black uppercase tracking-wide">{{ store.error }}</span>
      </div>
    </Transition>

    <aside
        class="fixed inset-y-0 left-0 z-40 w-full md:relative md:w-80 lg:w-[380px] bg-white border-r border-slate-200 flex flex-col transition-all duration-500 md:translate-x-0"
        :class="isMobileSidebarOpen ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="px-6 pt-6 bg-white shrink-0">
        <div class="flex justify-between items-center mb-6">
          <h1 class="font-black text-2xl tracking-tight text-slate-800">Mensajes</h1>
          <button @click="store.fetchConversations" class="w-9 h-9 rounded-xl bg-slate-50 flex items-center justify-center hover:bg-slate-900 group transition-all shadow-sm">
            <i class="fas fa-sync-alt text-slate-400 group-hover:text-white text-xs" :class="{'fa-spin': store.loadingConversations}"></i>
          </button>
        </div>

        <div class="flex bg-slate-100 p-1 rounded-xl mb-4 shadow-inner">
          <button
              v-for="status in ['open', 'archived', 'closed']"
              :key="status"
              @click="store.filterStatus = status"
              class="flex-1 py-2 text-[10px] font-black uppercase tracking-wider rounded-lg transition-all"
              :class="store.filterStatus === status ? 'bg-white text-[#376875] shadow-sm' : 'text-slate-400 hover:text-slate-600'"
          >
            {{ status === 'open' ? 'Activos' : status === 'archived' ? 'Archivados' : 'Cerrados' }}
          </button>
        </div>
      </div>

      <div class="flex-1 overflow-y-auto scrollbar-hide py-2 px-3">
        <div v-if="store.loadingConversations" class="p-10 text-center">
          <i class="fas fa-circle-notch fa-spin text-slate-300"></i>
        </div>
        <div v-else-if="store.filteredConversations.length === 0" class="p-10 text-center opacity-30 italic text-xs">
          No hay conversaciones {{ store.filterStatus }}.
        </div>

        <div v-for="chat in store.filteredConversations" :key="chat.id" class="mb-1">
          <button
              @click="selectChat(chat.id)"
              class="w-full text-left p-4 rounded-2xl transition-all flex gap-4 relative group border border-transparent"
              :class="store.currentConversation?.id === chat.id ? 'bg-white border-slate-200 shadow-xl shadow-slate-200/50 translate-x-1' : 'hover:bg-slate-50'"
          >
            <div v-if="store.currentConversation?.id === chat.id" class="absolute left-0 top-4 bottom-4 w-1.5 bg-[#376875] rounded-r-full"></div>

            <div class="w-12 h-12 rounded-xl text-white flex items-center justify-center shrink-0 font-black text-lg shadow-sm" :class="getOriginClass(chat.contextOrigin)">
              {{ chat.guestName?.charAt(0).toUpperCase() || '?' }}
            </div>

            <div class="flex-1 min-w-0">
              <div class="flex justify-between items-baseline mb-0.5">
                <h3 class="font-bold truncate text-sm" :class="store.currentConversation?.id === chat.id ? 'text-[#376875]' : 'text-slate-800'">
                  {{ chat.guestName || 'Huésped' }}
                </h3>
                <span class="text-[9px] font-black uppercase text-slate-400 ml-2">
                    {{ formatDate(chat.lastMessageAt || chat.createdAt) }}
                </span>
              </div>

              <p class="text-[10px] font-black truncate text-[#E07845] mb-1 uppercase tracking-tight">
                {{ chat.contextItems && chat.contextItems.length ? chat.contextItems.join(', ') : 'Sin unidad' }}
              </p>

              <div class="flex items-center gap-2">
                <span class="text-[8px] px-1.5 py-0.5 rounded bg-slate-100 text-slate-500 font-black uppercase tracking-widest">
                    {{ chat.contextOrigin || 'directo' }}
                </span>
                <span v-if="chat.contextMilestones?.start" class="text-[9px] font-bold text-slate-300 italic">
                    {{ formatDate(chat.contextMilestones.start) }} - {{ formatDate(chat.contextMilestones.end) }}
                </span>
              </div>
            </div>

            <div v-if="chat.unreadCount > 0" class="absolute -right-1 -top-1 w-5 h-5 bg-[#E07845] text-white text-[10px] font-black rounded-full flex items-center justify-center border-2 border-white shadow-md">
              {{ chat.unreadCount }}
            </div>
          </button>
        </div>
      </div>
    </aside>

    <main class="flex-1 bg-[#F1F5F9] flex flex-col relative z-30 transition-all duration-300">
      <div v-if="!store.currentConversation" class="hidden md:flex flex-1 flex-col items-center justify-center bg-white text-slate-300">
        <i class="fas fa-paper-plane text-4xl mb-4 opacity-10"></i>
        <h2 class="text-xl font-black text-slate-800 tracking-tighter uppercase tracking-widest">Central de Operaciones</h2>
      </div>

      <template v-else>
        <header class="h-20 md:h-24 bg-white/80 backdrop-blur-md border-b border-slate-200 px-4 md:px-8 flex items-center justify-between shrink-0 sticky top-0 z-30">
          <div class="flex items-center gap-4 overflow-hidden">
            <button @click="isMobileSidebarOpen = true" class="md:hidden w-10 h-10 flex items-center justify-center bg-slate-50 rounded-xl text-slate-500 shadow-sm"><i class="fas fa-chevron-left"></i></button>
            <div class="truncate">
              <h2 class="font-black text-slate-900 text-lg md:text-2xl tracking-tight truncate leading-none mb-1">{{ store.currentConversation.guestName }}</h2>
              <div class="flex items-center gap-3 text-[10px] md:text-[11px] font-black uppercase tracking-widest text-slate-400">
                <span class="text-[#E07845]">{{ store.currentConversation.contextItems && store.currentConversation.contextItems.length ? store.currentConversation.contextItems.join(' + ') : 'PMS' }}</span>
                <span class="hidden sm:inline text-slate-200">/</span>
                <span class="hidden sm:inline">{{ formatFullDate(store.currentConversation.contextMilestones.start) }} - {{ formatFullDate(store.currentConversation.contextMilestones.end) }}</span>
              </div>
            </div>
          </div>

          <div class="flex items-center gap-3">
            <a v-if="store.getExternalContextUrl" :href="store.getExternalContextUrl" target="_blank"
               class="flex items-center gap-2 px-4 py-2.5 bg-slate-900 text-white rounded-xl shadow-xl hover:-translate-y-0.5 transition-all text-[10px] font-black uppercase tracking-wider">
              <i class="fas fa-external-link-alt"></i>
              <span class="hidden md:inline">Ver Reserva</span>
            </a>
            <span class="px-3 py-1.5 text-[10px] font-black uppercase rounded-lg border-2" :class="store.currentConversation.contextStatusTag === 'cancelled' ? 'bg-red-50 text-red-600 border-red-100' : 'bg-green-50 text-green-600 border-green-100'">
              {{ store.currentConversation.contextStatusTag || store.currentConversation.status }}
            </span>
          </div>
        </header>

        <div class="flex-1 overflow-y-auto p-4 md:p-10" ref="messagesContainer">
          <div class="max-w-4xl mx-auto">
            <div v-for="(group, date) in groupedMessages" :key="date">
              <div class="flex justify-center my-10 sticky top-4 z-20">
                <span class="px-5 py-2 bg-white/90 backdrop-blur-md border border-slate-200 shadow-sm rounded-full text-[10px] font-black text-slate-800 uppercase tracking-widest">
                  {{ formatDividerDate(date) }}
                </span>
              </div>
              <div class="space-y-8 mb-10 flex flex-col">
                <div v-for="msg in group" :key="msg.id" class="flex flex-col" :class="msg.direction === 'outgoing' ? 'items-end' : 'items-start'">
                  <div :class="msg.direction === 'outgoing' ? 'bg-[#376875] text-white rounded-3xl rounded-tr-none shadow-lg' : 'bg-white border border-slate-100 text-slate-800 rounded-3xl rounded-tl-none shadow-sm'" class="max-w-[85%] md:max-w-[70%] p-4 md:p-5 text-sm md:text-base font-medium leading-relaxed">
                    {{ msg.contentLocal || msg.contentExternal }}
                  </div>
                  <div class="flex items-center gap-2 mt-2 px-2 text-[9px] font-black text-slate-400 uppercase tracking-tighter">
                    <span>{{ formatTime(msg.createdAt) }}</span>
                    <i v-if="msg.direction === 'outgoing'" class="fas fa-check-double text-[10px]" :class="msg.status === 'read' ? 'text-[#E07845]' : 'opacity-20'"></i>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <footer class="bg-white border-t border-slate-100 p-4 md:p-8">
          <form @submit.prevent="send" class="max-w-4xl mx-auto flex items-end gap-3 bg-slate-50 border-2 border-slate-100 p-3 rounded-[32px] focus-within:bg-white transition-all shadow-inner">
            <button type="button" class="w-12 h-12 flex items-center justify-center text-slate-300 hover:text-slate-900 transition-all"><i class="fas fa-plus-circle text-xl"></i></button>
            <textarea v-model="newMessageText" @keydown.enter.exact.prevent="send" placeholder="Responder..." class="flex-1 bg-transparent border-0 focus:ring-0 resize-none py-3 text-sm font-semibold text-slate-800 scrollbar-hide" rows="1"></textarea>
            <button type="submit" :disabled="!newMessageText.trim() || store.sendingMessage" class="w-14 h-14 bg-[#E07845] text-white rounded-[24px] flex items-center justify-center shadow-xl hover:scale-105 active:scale-95 transition-all disabled:opacity-30">
              <i class="fas text-lg" :class="store.sendingMessage ? 'fa-circle-notch fa-spin' : 'fa-paper-plane'"></i>
            </button>
          </form>
        </footer>
      </template>
    </main>
  </div>
</template>

<style scoped>
.scrollbar-hide::-webkit-scrollbar { display: none; }
.fade-slide-enter-active, .fade-slide-leave-active { transition: all 0.4s ease; }
.fade-slide-enter-from, .fade-slide-leave-to { opacity: 0; transform: translate(-50%, -20px); }
textarea { outline: none; }
</style>