<script lang="ts">
/**
 * Los tres que se piden, y el porqué de cada uno en su propia línea.
 *
 * ⚠️ El DNI son **dos**: en un control migratorio no vale sólo el anverso. Pedirlos por separado
 * —en vez de «sube tu DNI»— es lo que hace que se note cuál falta.
 *
 * ⚠️ **Se EXPORTA, y por eso vive en un `<script>` normal y no en el `setup`.** Quien pinta esta
 * tarjeta necesita saber si queda algo pendiente para decidir dónde ponerla —arriba si le pide
 * algo, al final si ya cumplió— y la única forma de saberlo es comparar lo enviado contra esta
 * lista. Copiarla en la vista serían dos verdades sobre qué documentos se piden, y el día que se
 * añada uno se olvidaría la copia.
 */
export const DOCUMENTOS_PEDIDOS = [
  { tipo: 'pasaporte', titulo: 'Pasaporte', ayuda: 'La página de la foto, entera y sin reflejos.', icono: 'camera' },
  { tipo: 'dni_anverso', titulo: 'DNI — anverso', ayuda: 'La cara con tu foto.', icono: 'camera' },
  { tipo: 'dni_reverso', titulo: 'DNI — reverso', ayuda: 'La cara de atrás.', icono: 'camera' },
  {
    tipo: 'eticket',
    titulo: 'E-ticket del vuelo',
    // ⚠️ Se dice DÓNDE está, no qué es. «Sube tu e-ticket» hace pensar en un trámite; «el PDF que
    // te mandó la aerolínea» lo manda directo a buscar el correo, que es el único sitio donde
    // está. El nombre técnico ya lo lleva el título.
    ayuda: 'El PDF que te mandó la aerolínea al comprar.',
    icono: 'file-pdf',
  },
] as const;

export type TipoDoc = (typeof DOCUMENTOS_PEDIDOS)[number]['tipo'];

/** ¿Le queda algo por mandar? Lo pregunta la vista para colocar la tarjeta. */
export const faltanDocumentos = (yaEnviados?: readonly string[] | null): boolean =>
  DOCUMENTOS_PEDIDOS.some(d => !(yaEnviados ?? []).includes(d.tipo));
</script>

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

const props = defineProps<{
  localizador: string;
  /**
   * Los que ya mandó, **de una visita anterior**.
   *
   * 🔥 Sin esto la pantalla se olvida: sube el pasaporte, vuelve al día siguiente y los tres
   * botones dicen «Subir» otra vez. O lo manda de nuevo, o da por hecho que no funcionó y deja de
   * intentarlo — y eso segundo no se descubre nunca, porque nadie escribe para decir que se
   * rindió.
   *
   * ⚠️ Llega sólo el TIPO, sin enlace: un escaneo de identidad no se le devuelve ni a su dueño.
   */
  yaEnviados?: string[];
}>();

const maestroStore = useMaestroStore();


const eligiendo = ref<TipoDoc | null>(null);
const vistaPrevia = ref<string | null>(null);
const ficheroElegido = ref<File | null>(null);
const enviando = ref(false);
const error = ref<string | null>(null);
/**
 * Lo que ya tiene, venga de esta sesión o de la anterior.
 *
 * Se une en vez de sustituir: el prop llega con la respuesta del servidor y lo que acaba de subir
 * todavía no está en ella.
 */
const recienSubidos = ref<Set<string>>(new Set());

const subidos = computed(() => new Set<string>([...(props.yaEnviados ?? []), ...recienSubidos.value]));

const tituloDe = (tipo: TipoDoc) => DOCUMENTOS_PEDIDOS.find(d => d.tipo === tipo)?.titulo ?? '';

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

    recienSubidos.value = new Set([...recienSubidos.value, eligiendo.value]);
    descartar();
  } catch {
    // Sin conexión: el mensaje dice qué hacer, no qué falló.
    error.value = 'No hay conexión. Inténtalo cuando vuelvas a tener señal.';
  }

  enviando.value = false;
};

const quedanPorSubir = computed(() => DOCUMENTOS_PEDIDOS.filter(d => !subidos.value.has(d.tipo)).length);

/**
 * Cuántos le faltan. Lo lee el panel de fuera para su cabecera y para abrirse o no.
 *
 * ⚠️ **Aquí vivía un plegado propio** —una línea compacta que se abría al pulsarla— y el
 * contenedor tenía otro. Dos mecanismos para el mismo gesto: uno se creía abierto y el otro
 * seguía cerrado. Ahora pliega `PanelPlegable` y esto se limita a su contenido; la decisión que
 * había detrás —cerrado cuando ya no pide nada— se conserva, pero la toma quien pinta el panel.
 */
defineExpose({ quedanPorSubir });
</script>

<template>
  <div>
    <p class="text-slate-500 text-xs leading-relaxed mb-5">
      {{ maestroStore.t('cot_mis_documentos_motivo')
        || 'Los necesitamos para emitir tus boletos y para el control migratorio. Sólo los ve el equipo que arma tu viaje, y se borran un mes después de tu regreso.' }}
    </p>

    <div class="space-y-2">
      <div v-for="doc in DOCUMENTOS_PEDIDOS" :key="doc.tipo"
           class="flex items-center gap-3 p-3 rounded-2xl border transition-colors"
           :class="subidos.has(doc.tipo) ? 'bg-emerald-50 border-emerald-200' : 'bg-slate-50 border-slate-200'">
        <span class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0"
              :class="subidos.has(doc.tipo) ? 'bg-emerald-100 text-emerald-600' : 'bg-white text-slate-400 border border-slate-200'">
          <!-- El icono lo dice el documento: una cámara invita a hacer una foto, y el e-ticket
               no se fotografía — se busca en el correo. -->
          <i class="fas" :class="subidos.has(doc.tipo) ? 'fa-check' : `fa-${doc.icono}`"></i>
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
  </div>

  <!-- ═══ LA PREVISUALIZACIÓN ═══
       A pantalla completa y en grande, porque es la ÚNICA vez que se puede comprobar que la foto
       vale: después no se devuelve. -->
  <!-- ⚠️ **`Teleport` y banda de modal.** Este componente se monta DENTRO de la guía, entre
       tarjetas; un ancestro con `sticky`, `transform` o `filter` crea contexto de apilamiento y
       encierra este `fixed` dentro de él. Y aquí eso no es un detalle estético: esta
       previsualización es la ÚNICA oportunidad de comprobar que el escaneo se lee, porque después
       no se le devuelve. Si queda debajo de algo, el pasajero manda una foto movida sin saberlo. -->
  <Teleport to="body">
  <div v-if="vistaPrevia" class="fixed inset-0 z-[1000] bg-slate-900/90 flex flex-col p-4">
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
  </Teleport>
</template>
