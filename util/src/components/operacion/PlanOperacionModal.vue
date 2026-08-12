<script setup lang="ts">
/**
 * Panel de revisión de cambios de operación.
 *
 * Es la mitad visible de la reconciliación: el backend calcula un plan firmado y aquí
 * una persona decide qué se aplica. Nada se escribe hasta que se pulsa «Aplicar».
 *
 * Tres reglas que no conviene relajar:
 *
 *  1. **La aprobación es por CAMPO**, no por fila. Si la cotización movió la fecha y
 *     alguien ya había pactado la hora por teléfono, aprobar «la fila» es ambiguo.
 *  2. **Los conflictos llegan desmarcados.** Entre la cotización y quien habló con el
 *     proveedor, gana quien habló con el proveedor mientras nadie diga lo contrario.
 *  3. **Los borrados nunca se premarcan**, y los bloqueados ni siquiera tienen casilla.
 *
 * Ver docs/Operacion.md §3.5.
 */
import { ref, computed, watch } from 'vue';
import { useCotizacionFileStore } from '@/stores/cotizacion/fileStore';
import type { CambioPropuesto, PlanReconciliacion, ResultadoAplicacion } from '@/types/operacionModel';

const props = defineProps<{
  /** UUID de la cotización. `null` mantiene el panel cerrado. */
  cotizacionId: string | null;
  /** Rótulo para la cabecera (versión o expediente). */
  titulo?: string;
}>();

const emit = defineEmits<{
  (e: 'cerrar'): void;
  (e: 'aplicado', resultado: ResultadoAplicacion): void;
}>();

const fileStore = useCotizacionFileStore();

const plan = ref<PlanReconciliacion | null>(null);
const cargando = ref(false);
const aplicando = ref(false);
const errorMsg = ref<string | null>(null);
const resultado = ref<ResultadoAplicacion | null>(null);

/** idDelCambio → campos marcados. Para crear/huérfano la lista va vacía. */
const aprobados = ref<Record<string, string[]>>({});

// ============================================================================
// CARGA DEL PLAN
// ============================================================================
const cargarPlan = async () => {
  if (!props.cotizacionId) return;

  cargando.value = true;
  errorMsg.value = null;
  resultado.value = null;
  plan.value = null;
  aprobados.value = {};

  const p = await fileStore.planificarOperacion(props.cotizacionId);
  cargando.value = false;

  if (!p) {
    errorMsg.value = fileStore.error || 'No se pudo calcular el plan.';
    return;
  }

  plan.value = p;
  aprobados.value = marcadosPorDefecto(p.cambios);
};

/**
 * El estado inicial de las casillas ES la política de seguridad. Crear no destruye
 * nada y llega marcado; un campo que alguien editó a mano, o cualquier cosa en una
 * OS emitida, llega sin marcar; borrar, nunca.
 */
const marcadosPorDefecto = (cambios: CambioPropuesto[]): Record<string, string[]> => {
  const marcas: Record<string, string[]> = {};

  for (const c of cambios) {
    if (c.bloqueado || c.tipo === 'huerfano') continue;
    if (c.tipo === 'crear') {
      if (c.aprobadoPorDefecto) marcas[c.id] = [];
      continue;
    }
    // Con una OS de por medio no se premarca nada: se mira entero.
    if (c.enOrdenServicio) continue;

    const sinConflicto = c.campos.filter(f => !f.enConflicto).map(f => f.campo);
    if (sinConflicto.length) marcas[c.id] = sinConflicto;
  }

  return marcas;
};

watch(() => props.cotizacionId, (id) => { if (id) cargarPlan(); }, { immediate: true });

// ============================================================================
// SELECCIÓN
// ============================================================================
const estaAprobado = (c: CambioPropuesto): boolean => c.id in aprobados.value;

const campoAprobado = (c: CambioPropuesto, campo: string): boolean =>
    (aprobados.value[c.id] ?? []).includes(campo);

const alternarCambio = (c: CambioPropuesto) => {
  if (c.bloqueado) return;

  if (estaAprobado(c)) {
    const copia = { ...aprobados.value };
    delete copia[c.id];
    aprobados.value = copia;
    return;
  }
  // Al marcar la fila entera se aceptan todos sus campos, conflictos incluidos:
  // marcar a sabiendas es una decisión válida; lo que no vale es que ocurra sola.
  aprobados.value = { ...aprobados.value, [c.id]: c.campos.map(f => f.campo) };
};

const alternarCampo = (c: CambioPropuesto, campo: string) => {
  if (c.bloqueado) return;

  const actuales = aprobados.value[c.id] ?? [];
  const nuevos = actuales.includes(campo)
      ? actuales.filter(x => x !== campo)
      : [...actuales, campo];

  const copia = { ...aprobados.value };
  if (nuevos.length === 0) delete copia[c.id];
  else copia[c.id] = nuevos;
  aprobados.value = copia;
};

const totalAprobado = computed(() => Object.keys(aprobados.value).length);

const hayBorradosAprobados = computed(() =>
    (plan.value?.cambios ?? []).some(c => c.tipo === 'huerfano' && estaAprobado(c))
);

// ============================================================================
// APLICAR
// ============================================================================
const aplicar = async () => {
  if (!props.cotizacionId || !plan.value || totalAprobado.value === 0) return;

  if (hayBorradosAprobados.value
      && !confirm('Vas a borrar filas de La Biblia. Los ajustes manuales de esas filas se pierden. ¿Continuar?')) {
    return;
  }

  aplicando.value = true;
  errorMsg.value = null;

  const r = await fileStore.aplicarPlanOperacion(props.cotizacionId, {
    firma: plan.value.firma,
    aprobados: aprobados.value,
  });

  aplicando.value = false;

  if (!r) {
    // El caso típico: la firma caducó porque alguien tocó la operación mientras se
    // revisaba. El backend lo explica; aquí sólo se ofrece recalcular.
    errorMsg.value = fileStore.error || 'No se pudieron aplicar los cambios.';
    return;
  }

  resultado.value = r;
  emit('aplicado', r);
};

// ============================================================================
// PRESENTACIÓN
// ============================================================================
const TIPO_UI: Record<string, { label: string; icon: string; cls: string }> = {
  crear:      { label: 'Nuevo',     icon: 'fa-plus',          cls: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
  actualizar: { label: 'Cambia',    icon: 'fa-pen',           cls: 'bg-sky-50 text-sky-700 border-sky-200' },
  huerfano:   { label: 'Sobra',     icon: 'fa-trash',         cls: 'bg-rose-50 text-rose-700 border-rose-200' },
};

const uiDe = (t: string) => TIPO_UI[t] ?? TIPO_UI.actualizar;

const vacio = (v: string | null): string => (v === null || v === '' ? '—' : v);
</script>

<template>
  <div v-if="cotizacionId" class="fixed inset-0 z-1000 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white w-full max-w-3xl rounded-3xl shadow-2xl overflow-hidden flex flex-col max-h-[88vh]">

      <header class="bg-slate-900 text-white px-6 py-4 flex items-center gap-3 shrink-0">
        <i class="fas fa-code-compare text-[#E07845]"></i>
        <div class="min-w-0">
          <h3 class="font-black text-sm tracking-tight">Revisar cambios de operación</h3>
          <p v-if="titulo" class="text-[10px] font-bold text-slate-400 uppercase tracking-widest truncate">{{ titulo }}</p>
        </div>
        <button @click="emit('cerrar')" class="ml-auto text-slate-400 hover:text-white shrink-0">
          <i class="fas fa-xmark"></i>
        </button>
      </header>

      <!-- Cargando -->
      <div v-if="cargando" class="flex-1 flex items-center justify-center py-16">
        <div class="text-center">
          <i class="fas fa-spinner fa-spin text-3xl text-[#376875] mb-3"></i>
          <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Comparando con La Biblia…</p>
        </div>
      </div>

      <!-- Resultado tras aplicar -->
      <div v-else-if="resultado" class="flex-1 flex items-center justify-center p-8">
        <div class="text-center max-w-md">
          <div class="w-14 h-14 bg-emerald-50 rounded-2xl flex items-center justify-center mx-auto mb-4 border border-emerald-200">
            <i class="fas fa-check text-2xl text-emerald-600"></i>
          </div>
          <p class="font-black text-slate-700 mb-2">Cambios aplicados</p>
          <p class="text-sm text-slate-500 leading-relaxed">{{ resultado.mensaje }}</p>
          <div class="mt-5 flex justify-center gap-2">
            <button @click="cargarPlan" class="px-4 py-2 text-xs font-black uppercase tracking-widest text-slate-500 border border-slate-200 rounded-lg hover:bg-slate-50">
              Volver a revisar
            </button>
            <button @click="emit('cerrar')" class="px-5 py-2 bg-[#376875] text-white text-xs font-black uppercase tracking-widest rounded-lg shadow-sm">
              Cerrar
            </button>
          </div>
        </div>
      </div>

      <!-- Error -->
      <div v-else-if="errorMsg && !plan" class="flex-1 flex items-center justify-center p-8">
        <div class="text-center max-w-md">
          <i class="fas fa-triangle-exclamation text-3xl text-amber-500 mb-3"></i>
          <p class="text-sm font-bold text-slate-600 leading-relaxed">{{ errorMsg }}</p>
          <button @click="emit('cerrar')" class="mt-5 px-5 py-2 text-xs font-black uppercase tracking-widest text-slate-500 border border-slate-200 rounded-lg hover:bg-slate-50">
            Cerrar
          </button>
        </div>
      </div>

      <!-- Plan -->
      <template v-else-if="plan">
        <div class="px-6 py-3 bg-slate-50 border-b border-slate-200 shrink-0">
          <p class="text-xs font-bold text-slate-600">{{ plan.mensaje }}</p>
          <p v-if="errorMsg" class="mt-2 text-xs font-bold text-rose-600">
            <i class="fas fa-triangle-exclamation mr-1"></i>{{ errorMsg }}
            <button @click="cargarPlan" class="ml-2 underline uppercase tracking-wide">Recalcular</button>
          </p>
        </div>

        <div v-if="plan.cambios.length === 0" class="flex-1 flex items-center justify-center py-16">
          <div class="text-center">
            <i class="fas fa-check-double text-3xl text-emerald-500 mb-3"></i>
            <p class="text-sm text-slate-500">La Biblia ya coincide con la cotización.</p>
          </div>
        </div>

        <div v-else class="flex-1 overflow-y-auto p-5 space-y-3">
          <article
              v-for="c in plan.cambios"
              :key="c.id + c.tipo"
              class="border rounded-2xl overflow-hidden transition-colors"
              :class="c.bloqueado ? 'border-slate-200 bg-slate-50 opacity-75'
                    : estaAprobado(c) ? 'border-[#376875]/40 bg-[#376875]/4' : 'border-slate-200 bg-white'"
          >
            <div class="px-4 py-3 flex items-start gap-3">
              <!-- Bloqueado: ni casilla. No es una opción desactivada, es que no se ofrece. -->
              <i v-if="c.bloqueado" class="fas fa-lock text-slate-300 text-sm mt-1 w-4 text-center" :title="c.motivo ?? ''"></i>
              <input
                  v-else
                  type="checkbox"
                  :checked="estaAprobado(c)"
                  @change="alternarCambio(c)"
                  class="mt-1 w-4 h-4 accent-[#376875] cursor-pointer shrink-0"
              />

              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="px-1.5 py-0.5 inline-flex items-center gap-1 text-[9px] font-black rounded border uppercase tracking-wider" :class="uiDe(c.tipo).cls">
                    <i class="fas text-[8px]" :class="uiDe(c.tipo).icon"></i>{{ uiDe(c.tipo).label }}
                  </span>
                  <p class="text-sm font-black text-slate-800 leading-tight">{{ c.descripcion }}</p>
                  <span v-if="c.fecha" class="text-[10px] font-bold text-slate-400 tabular-nums">{{ c.fecha }}</span>
                </div>
                <p v-if="c.contexto" class="text-[10px] font-bold text-slate-400 mt-0.5 truncate">
                  <i class="fas fa-diagram-project mr-1"></i>{{ c.contexto }}
                </p>
                <p v-if="c.motivo" class="text-[10px] font-bold mt-1 leading-snug" :class="c.bloqueado ? 'text-slate-400' : 'text-amber-600'">
                  <i class="fas fa-circle-info mr-1"></i>{{ c.motivo }}
                </p>
              </div>
            </div>

            <!-- Diff campo a campo: valor actual contra valor propuesto -->
            <div v-if="c.campos.length" class="border-t border-slate-100 bg-slate-50/60 px-4 py-2 space-y-1">
              <label
                  v-for="f in c.campos"
                  :key="f.campo"
                  class="flex items-start gap-2.5 py-1 cursor-pointer group"
                  :class="c.bloqueado ? 'cursor-not-allowed' : ''"
              >
                <input
                    type="checkbox"
                    :disabled="c.bloqueado"
                    :checked="campoAprobado(c, f.campo)"
                    @change="alternarCampo(c, f.campo)"
                    class="mt-0.5 w-3.5 h-3.5 accent-[#376875] cursor-pointer shrink-0 disabled:cursor-not-allowed"
                />
                <div class="min-w-0 flex-1">
                  <p class="text-[10px] font-black uppercase tracking-wider" :class="f.enConflicto ? 'text-amber-600' : 'text-slate-400'">
                    {{ f.etiqueta }}
                    <span v-if="f.enConflicto" title="Lo editó alguien en Operaciones">
                      <i class="fas fa-triangle-exclamation ml-1"></i> editado a mano
                    </span>
                  </p>
                  <p class="text-xs font-bold text-slate-600 flex flex-wrap items-center gap-1.5">
                    <span class="line-through text-slate-400 decoration-rose-300">{{ vacio(f.valorActual) }}</span>
                    <i class="fas fa-arrow-right text-[9px] text-slate-300"></i>
                    <span class="text-[#376875]">{{ vacio(f.valorPropuesto) }}</span>
                  </p>
                </div>
              </label>
            </div>
          </article>
        </div>

        <footer class="px-6 py-3 bg-slate-50 border-t border-slate-200 flex flex-wrap items-center gap-3 shrink-0">
          <span class="text-[10px] font-black uppercase tracking-widest" :class="totalAprobado ? 'text-[#376875]' : 'text-slate-400'">
            {{ totalAprobado }} de {{ plan.cambios.length }} seleccionado{{ totalAprobado === 1 ? '' : 's' }}
          </span>
          <span v-if="hayBorradosAprobados" class="text-[10px] font-bold text-rose-600">
            <i class="fas fa-triangle-exclamation mr-1"></i>Incluye borrados
          </span>

          <div class="ml-auto flex items-center gap-2">
            <button @click="emit('cerrar')" class="px-4 py-2 text-xs font-black uppercase tracking-widest text-slate-500 hover:text-slate-800">
              Cancelar
            </button>
            <button
                @click="aplicar"
                :disabled="aplicando || totalAprobado === 0"
                class="px-5 py-2 bg-[#376875] hover:bg-[#2d5660] disabled:opacity-40 disabled:cursor-not-allowed text-white text-xs font-black uppercase tracking-widest rounded-lg shadow-sm"
            >
              <i v-if="aplicando" class="fas fa-spinner fa-spin mr-1"></i>
              Aplicar
            </button>
          </div>
        </footer>
      </template>
    </div>
  </div>
</template>
