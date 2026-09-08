<script setup lang="ts">
/**
 * El pasajero fotografía sus documentos desde su propio móvil.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * Perseguir 133 pasaportes por WhatsApp —y luego renombrarlos y subirlos uno a uno— es el trabajo
 * que esto quita. Quien tiene el documento delante es él.
 *
 * ── La previsualización NO es un adorno ─────────────────────────────────────
 * 🔥 Es la única oportunidad de ver si la foto salió movida, cortada o con el flash reventando el
 * holograma. **Después no se puede mirar**: por seguridad, un escaneo de identidad no se le
 * devuelve nunca a quien lo subió —no le damos nada que no tenga ya, y cada vía por la que esa
 * imagen puede salir es una vía de más—. Así que la comprobación es aquí o no es.
 *
 * Por eso el flujo es *elegir → mirar en grande → confirmar*, y no un `input` que envía al soltar.
 *
 * ── `capture` y no una cámara propia ────────────────────────────────────────
 * ⚠️ `<input type="file" accept="image/*" capture="environment">` abre **la cámara del sistema**,
 * con su enfoque, su HDR y su recorte. Una cámara hecha con `getUserMedia` da peor foto, pide un
 * permiso que asusta y falla en la mitad de los navegadores embebidos —el de Instagram, el de
 * Gmail—, que es justo por donde llega un enlace de viaje.
 *
 * Y sin `capture` fijo en cámara: mucha gente ya tiene la foto del pasaporte en la galería.
 */
import { ref, computed } from 'vue';
import { useMaestroStore } from '@/stores/maestroStore';

const props = defineProps<{ localizador: string }>();

const maestroStore = useMaestroStore();

/**
 * Los tres que se piden, y el porqué de cada uno en su propia línea.
 *
 * ⚠️ El DNI son **dos**: en un control migratorio no vale sólo el anverso. Pedirlos por separado
 * —en vez de «sube tu DNI»— es lo que hace que se note cuál falta.
 */
const DOCUMENTOS = [
  { tipo: 'pasaporte', titulo: 'Pasaporte', ayuda: 'La página de la foto, entera y sin reflejos.' },
  { tipo: 'dni_anverso', titulo: 'DNI — anverso', ayuda: 'La cara con tu foto.' },
  { tipo: 'dni_reverso', titulo: 'DNI — reverso', ayuda: 'La cara de atrás.' },
] as const;

type TipoDoc = (typeof DOCUMENTOS)[number]['tipo'];

const eligiendo = ref<TipoDoc | null>(null);
const vistaPrevia = ref<string | null>(null);
const ficheroElegido = ref<File | null>(null);
const enviando = ref(false);
const error = ref<string | null>(null);
const subidos = ref<Set<string>>(new Set());

const tituloDe = (tipo: TipoDoc) => DOCUMENTOS.find(d => d.tipo === tipo)?.titulo ?? '';

const alElegir = (tipo: TipoDoc, evento: Event) => {
  const archivo = (evento.target as HTMLInputElement).files?.[0];
  if (!archivo) return;

  error.value = null;
  ficheroElegido.value = archivo;
  eligiendo.value = tipo;

  // ⚠️ `createObjectURL` y no un `FileReader` con base64: una foto de móvil son varios megas y
  // pasarlos a texto los infla un tercio y bloquea el hilo mientras tanto. Esto es instantáneo.
  vistaPrevia.value = URL.createObjectURL(archivo);
};

const descartar = () => {
  if (vistaPrevia.value) URL.revokeObjectURL(vistaPrevia.value);
  vistaPrevia.value = null;
  ficheroElegido.value = null;
  eligiendo.value = null;
  error.value = null;
};

const confirmar = async () => {
  if (!ficheroElegido.value || !eligiendo.value) return;

  enviando.value = true;
  error.value = null;

  const cuerpo = new FormData();
  cuerpo.append('documento', ficheroElegido.value);
  cuerpo.append('tipo', eligiendo.value);

  try {
    const respuesta = await fetch(`/file/${props.localizador}/mis-documentos`, {
      method: 'POST',
      body: cuerpo,
      // La identidad va en la sesión, así que la cookie tiene que viajar.
      credentials: 'same-origin',
    });

    const datos = await respuesta.json().catch(() => ({}));

    if (!respuesta.ok) {
      error.value = datos.error || 'No se pudo enviar. Inténtalo otra vez.';
      enviando.value = false;

      return;
    }

    subidos.value = new Set([...subidos.value, eligiendo.value]);
    descartar();
  } catch {
    // Sin conexión: el mensaje dice qué hacer, no qué falló.
    error.value = 'No hay conexión. Inténtalo cuando vuelvas a tener señal.';
  }

  enviando.value = false;
};

const quedanPorSubir = computed(() => DOCUMENTOS.filter(d => !subidos.value.has(d.tipo)).length);
</script>

<template>
  <section class="bg-white rounded-[2rem] shadow-lg shadow-slate-200/50 border border-slate-100 p-6 mb-6">
    <h3 class="text-gray-900 font-black text-base mb-1">
      {{ maestroStore.t('cot_mis_documentos') || 'Tus documentos' }}
    </h3>
    <p class="text-slate-500 text-xs leading-relaxed mb-5">
      {{ maestroStore.t('cot_mis_documentos_motivo')
        || 'Los necesitamos para emitir tus boletos y para el control migratorio. Sólo los ve el equipo que arma tu viaje, y se borran un mes después de tu regreso.' }}
    </p>

    <div class="space-y-2">
      <div v-for="doc in DOCUMENTOS" :key="doc.tipo"
           class="flex items-center gap-3 p-3 rounded-2xl border transition-colors"
           :class="subidos.has(doc.tipo) ? 'bg-emerald-50 border-emerald-200' : 'bg-slate-50 border-slate-200'">
        <span class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0"
              :class="subidos.has(doc.tipo) ? 'bg-emerald-100 text-emerald-600' : 'bg-white text-slate-400 border border-slate-200'">
          <i class="fas" :class="subidos.has(doc.tipo) ? 'fa-check' : 'fa-camera'"></i>
        </span>

        <div class="min-w-0 flex-1">
          <p class="text-sm font-black text-gray-800">{{ doc.titulo }}</p>
          <p class="text-[11px] text-slate-500 leading-snug">
            {{ subidos.has(doc.tipo) ? 'Recibido, gracias.' : doc.ayuda }}
          </p>
        </div>

        <!-- El `label` es el botón: un `input file` estilizado se rompe en cuanto el navegador
             decide pintarlo a su manera. -->
        <label class="shrink-0 px-4 py-2 rounded-xl text-[11px] font-black uppercase tracking-wider cursor-pointer transition-colors"
               :class="subidos.has(doc.tipo)
                 ? 'text-emerald-700 hover:bg-emerald-100'
                 : 'bg-[#376875] hover:bg-[#2b525d] text-white'">
          {{ subidos.has(doc.tipo) ? 'Cambiar' : 'Subir' }}
          <input type="file" accept="image/*,application/pdf" class="hidden"
                 @change="alElegir(doc.tipo, $event)" />
        </label>
      </div>
    </div>

    <p v-if="quedanPorSubir === 0" class="mt-4 text-[11px] font-bold text-emerald-700 text-center">
      <i class="fas fa-circle-check mr-1"></i> Ya está todo. No tienes que hacer nada más.
    </p>
  </section>

  <!-- ═══ LA PREVISUALIZACIÓN ═══
       A pantalla completa y en grande, porque es la ÚNICA vez que se puede comprobar que la foto
       vale: después no se devuelve. -->
  <div v-if="vistaPrevia" class="fixed inset-0 z-50 bg-slate-900/90 flex flex-col p-4">
    <div class="flex items-center justify-between text-white mb-3 shrink-0">
      <p class="font-black text-sm">{{ tituloDe(eligiendo!) }}</p>
      <button @click="descartar" class="w-9 h-9 rounded-full bg-white/10 hover:bg-white/20 transition-colors">
        <i class="fas fa-xmark"></i>
      </button>
    </div>

    <div class="flex-1 min-h-0 flex items-center justify-center overflow-hidden rounded-2xl bg-black/30">
      <img v-if="ficheroElegido?.type !== 'application/pdf'" :src="vistaPrevia" alt=""
           class="max-h-full max-w-full object-contain" />
      <p v-else class="text-white/70 text-xs font-bold text-center px-6">
        <i class="far fa-file-pdf text-3xl block mb-2"></i>
        {{ ficheroElegido?.name }}
      </p>
    </div>

    <p class="text-white/70 text-[11px] text-center leading-snug my-3 shrink-0">
      Míralo bien antes de enviar: que se lea todo, sin reflejos y sin cortar los bordes.
      <strong class="text-white">Después no vas a poder verlo otra vez.</strong>
    </p>

    <p v-if="error" class="text-amber-200 bg-amber-900/40 rounded-xl py-2.5 px-4 text-xs font-bold text-center mb-2 shrink-0">
      {{ error }}
    </p>

    <div class="flex gap-2 shrink-0">
      <button @click="descartar" :disabled="enviando"
              class="flex-1 py-3.5 rounded-2xl bg-white/10 hover:bg-white/20 text-white font-black text-xs uppercase tracking-widest transition-colors disabled:opacity-50">
        Repetir
      </button>
      <button @click="confirmar" :disabled="enviando"
              class="flex-1 py-3.5 rounded-2xl bg-[#E07845] hover:bg-[#c96835] text-white font-black text-xs uppercase tracking-widest transition-colors disabled:opacity-50">
        <i v-if="enviando" class="fas fa-spinner fa-spin mr-1"></i>
        {{ enviando ? 'Enviando…' : 'Enviar' }}
      </button>
    </div>
  </div>
</template>
