<script setup lang="ts">
// ============================================================================
// Reporte financiero interno V2 — acordeones + switch global S/. ↔ $
//  · Un solo switch de moneda para TODA la vista (tablas a la mitad de ancho)
//  · Detalle por pax: badges (modo + P/U) sobre el monto, en una sola celda;
//    servicio (azul acero) arriba y componente (gris) debajo, también en una.
//  · Inclusiones: acordeón por servicio con contadores, montos alineados a la
//    derecha como chip (ocultos si son 0), fecha como chip.
// ============================================================================
import { ref, computed } from 'vue';
import { useCotizacionEditorStore } from '@/stores/cotizacion/cotizacionEditorStore';
import {
  filasResumenGeneral, formatModCat,
  LineaDetalleClaseInterna, InclusionLinea
} from '@/types/cotizacionEditorModel';

const store = useCotizacionEditorStore();
const fin = computed(() => store.resumenFinanciero);
const lang = computed(() => store.cotizacion?.idiomaEdicion || 'es');

// ── Switch global de moneda ──────────────────────────────────────────────────
const monedaVista = ref<'PEN' | 'USD'>('USD');
const n2 = (v: number) => (Math.round(v * 100) / 100).toFixed(2);
/** Elige soles o dólares según el switch y formatea */
const mv = (soles: number, dolares: number) =>
    monedaVista.value === 'PEN' ? `S/ ${n2(soles)}` : `$ ${n2(dolares)}`;

// ── Acordeones ───────────────────────────────────────────────────────────────
const abiertos = ref<Set<string>>(new Set(['general']));
const toggle = (k: string) => abiertos.value.has(k) ? abiertos.value.delete(k) : abiertos.value.add(k);
const isOpen = (k: string) => abiertos.value.has(k);

// ── Configs UI ───────────────────────────────────────────────────────────────
const MODO_UI: Record<string, { label: string; badge: string }> = {
  incluido:    { label: 'Incluido',    badge: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
  no_incluido: { label: 'No incluido', badge: 'bg-red-50 text-red-600 border-red-200' },
  cortesia:    { label: 'Cortesía',    badge: 'bg-sky-50 text-sky-700 border-sky-200' },
  opcional:    { label: 'Opcional',    badge: 'bg-amber-50 text-amber-700 border-amber-200' }
};

const filasPorModo = (clase: any) => ([
  { key: 'normal',   label: 'Normal',   ...clase.resumenPorModo.normal },
  { key: 'ctaPax',   label: 'Cta Pax',  ...clase.resumenPorModo.ctaPax },
  { key: 'cortesia', label: 'Cortesía', ...clase.resumenPorModo.cortesia }
].filter(f => f.costoSoles !== 0 || f.ventaSoles !== 0));

const rangoEdadLabel = (clase: any) => {
  if (clase.edadMin <= 0 && clase.edadMax >= 120) return 'Sin restricción de edad';
  if (clase.edadMin > 0 && clase.edadMax < 120) return `${clase.edadMin} - ${clase.edadMax} años`;
  if (clase.edadMin > 0) return `desde ${clase.edadMin} años`;
  return `hasta ${clase.edadMax} años`;
};

/** "2 x 60.00" — la moneda y (P)/(U) van en badges, no en el texto */
const montoLinea = (d: LineaDetalleClaseInterna) => {
  const prefijo = d.cantidadComponente > 1 ? `${d.cantidadComponente} x ` : '';
  return `${prefijo}${parseFloat(d.montoCotizado).toFixed(2)}`;
};

const labelTarifa = (d: LineaDetalleClaseInterna) =>
    store.getI18nText(d.tarifaTitulo as any, lang.value) || d.nombreInterno || '';

const labelInclusion = (l: InclusionLinea) => store.getI18nText(l.nombre as any, lang.value);

const seccionesInclusion = (srv: any) => ([
  { key: 'incluidos',   titulo: 'Incluido',    icono: 'fa-check-circle text-emerald-500', lineas: srv.incluidos },
  { key: 'noIncluidos', titulo: 'No incluido', icono: 'fa-times-circle text-red-500',     lineas: srv.noIncluidos },
  { key: 'cortesias',   titulo: 'Cortesía',    icono: 'fa-gift text-sky-500',             lineas: srv.cortesias },
  { key: 'opcionales',  titulo: 'Opcional',    icono: 'fa-circle-question text-amber-500', lineas: srv.opcionales }
].filter(s => s.lineas.length > 0));

const contadorInclusiones = (srv: any) => ({
  ok: srv.incluidos.length, no: srv.noIncluidos.length,
  cort: srv.cortesias.length, opc: srv.opcionales.length
});

/** Chip de monto para inclusiones: null si no hay monto o es 0 (evita "US$ 0.00") */
const chipMonto = (t: { montoCotizado: string | null; moneda: string | null }): string | null => {
  if (!t.montoCotizado) return null;
  const v = parseFloat(t.montoCotizado);
  if (!v) return null;
  return `${t.moneda === 'PEN' ? 'S/' : 'US$'} ${v.toFixed(2)}`;
};
</script>

<template>
  <div v-if="fin" class="text-slate-800">

    <!-- ══ Toolbar sticky: switch único de moneda ══ -->
    <div class="sticky top-0 z-20 -mx-1 px-1 pb-3 bg-[#F8FAFC]/95 backdrop-blur-sm flex items-center justify-between">
      <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">
        al {{ fin.comisionGlobal.toFixed(2) }}% · TC {{ fin.tipoCambio }}
      </p>
      <div class="flex items-center bg-white border border-slate-200 rounded-xl p-1 gap-1 shadow-sm">
        <button @click="monedaVista = 'PEN'"
                :class="monedaVista === 'PEN' ? 'bg-slate-900 text-white shadow' : 'text-slate-400 hover:text-slate-600'"
                class="px-3 py-1.5 rounded-lg text-[10px] font-black tracking-widest transition-all">S/. SOLES</button>
        <button @click="monedaVista = 'USD'"
                :class="monedaVista === 'USD' ? 'bg-slate-900 text-white shadow' : 'text-slate-400 hover:text-slate-600'"
                class="px-3 py-1.5 rounded-lg text-[10px] font-black tracking-widest transition-all">$ DÓLARES</button>
      </div>
    </div>

    <!-- ══ Aviso de no publicable ══ -->
    <div v-if="!fin.publicable" class="bg-red-50 border border-red-200 rounded-2xl p-4 mb-4">
      <p class="text-[10px] font-black text-red-600 uppercase tracking-widest mb-2">
        <i class="fas fa-ban mr-1"></i> No publicable
      </p>
      <ul class="space-y-1">
        <li v-for="(adv, i) in fin.advertencias" :key="i"
            class="text-[11px] font-bold text-red-700 bg-white p-2 rounded-lg border border-red-100">{{ adv }}</li>
      </ul>
    </div>

    <div class="space-y-3">

      <!-- ══ 1 · Resumen General (acordeón) ══ -->
      <section class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
        <button @click="toggle('general')" class="w-full px-5 py-4 flex items-center justify-between hover:bg-slate-50 transition-colors">
          <span class="font-black text-sm uppercase tracking-wide flex items-center gap-2">
            <i class="fas fa-chart-pie text-[#376875]"></i> Resumen General
          </span>
          <span class="flex items-center gap-3">
            <span class="text-xs font-black" :class="fin.ganancia >= 0 ? 'text-emerald-600' : 'text-red-600'">
              {{ mv(fin.resumenGeneral.incluido.gananciaSoles + fin.resumenGeneral.cortesia.gananciaSoles,
                fin.ganancia) }}
            </span>
            <i class="fas fa-chevron-down text-slate-300 transition-transform" :class="isOpen('general') ? 'rotate-180' : ''"></i>
          </span>
        </button>

        <div v-show="isOpen('general')" class="border-t border-slate-100">
          <table class="w-full text-sm">
            <thead>
            <tr class="text-[10px] font-black text-slate-400 uppercase tracking-wide border-b border-slate-100">
              <th class="text-left px-5 py-3">Tipo</th>
              <th class="text-right px-5 py-3">Costo</th>
              <th class="text-right px-5 py-3">Venta</th>
              <th class="text-right px-5 py-3">Ganancia</th>
            </tr>
            </thead>
            <tbody>
            <tr v-for="fila in filasResumenGeneral(fin)" :key="fila.tipo"
                class="border-b border-slate-50 last:border-0 odd:bg-slate-50/50 tabular-nums">
              <td class="px-5 py-2.5">
                  <span class="text-[10px] font-black px-2 py-1 rounded-lg border" :class="MODO_UI[fila.tipo].badge">
                    {{ fila.label }}
                  </span>
              </td>
              <td class="text-right px-5 py-2.5 font-bold text-slate-600">{{ mv(fila.costoSoles, fila.costoDolares) }}</td>
              <td class="text-right px-5 py-2.5 font-bold text-slate-800">{{ mv(fila.ventaSoles, fila.ventaDolares) }}</td>
              <td class="text-right px-5 py-2.5 font-black"
                  :class="fila.gananciaDolares < 0 ? 'text-red-600' : 'text-emerald-700'">
                {{ mv(fila.gananciaSoles, fila.gananciaDolares) }}
              </td>
            </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- ══ 2 · Por tipo de pasajero (un acordeón por clase) ══ -->
      <section v-for="clase in fin.clasesPasajeros" :key="clase.tipo"
               class="bg-white border rounded-2xl shadow-sm overflow-hidden"
               :class="clase.tipo.includes('anomalo') ? 'border-red-300' : 'border-slate-200'">
        <button @click="toggle('clase:' + clase.tipo)" class="w-full px-5 py-4 flex items-center justify-between hover:bg-slate-50 transition-colors">
          <span class="flex items-center gap-2 min-w-0">
            <span class="px-2 py-0.5 rounded text-[10px] font-black uppercase flex-shrink-0"
                  :class="clase.tipo.includes('anomalo') ? 'bg-red-100 text-red-700' : 'bg-indigo-100 text-indigo-700'">
              {{ clase.cantidad }}x
            </span>
            <span class="font-black text-sm truncate">{{ clase.tipoPaxNombre }}</span>
            <span class="text-[10px] font-bold text-slate-400 hidden sm:inline">· {{ rangoEdadLabel(clase) }}</span>
          </span>
          <span class="flex items-center gap-3 flex-shrink-0">
            <span class="text-[10px] font-bold text-slate-400 uppercase hidden sm:inline">Venta/pax</span>
            <span class="text-xs font-black text-slate-800">
              {{ mv(clase.resumenPorModo.normal.ventaSoles, clase.resumenPorModo.normal.ventaDolares) }}
            </span>
            <i class="fas fa-chevron-down text-slate-300 transition-transform" :class="isOpen('clase:' + clase.tipo) ? 'rotate-180' : ''"></i>
          </span>
        </button>

        <div v-show="isOpen('clase:' + clase.tipo)" class="border-t border-slate-100">
          <table class="w-full text-[13px]">
            <thead>
            <tr class="text-[10px] font-black text-slate-400 uppercase tracking-wide border-b border-slate-100">
              <th class="text-left px-5 py-3 w-32">Monto Cotizado</th>
              <th class="text-left px-5 py-3">Detalle</th>
              <th class="text-right px-5 py-3 w-28">Venta/pax</th>
            </tr>
            </thead>
            <tbody>
            <tr v-for="(d, i) in clase.detalle" :key="i"
                class="border-b border-slate-50 odd:bg-slate-50/50 tabular-nums align-top"
                :class="d.rol === 'operativo' ? 'opacity-50' : ''">

              <!-- Celda única: badges arriba, monto neutro debajo -->
              <td class="px-5 py-3">
                <div class="flex flex-wrap gap-1 mb-1">
                    <span class="text-[8px] font-black px-1.5 py-0.5 rounded border uppercase" :class="MODO_UI[d.modo].badge">
                      {{ MODO_UI[d.modo].label }}
                    </span>
                  <span class="text-[8px] font-black px-1.5 py-0.5 rounded border uppercase bg-slate-50 text-slate-500 border-slate-200"
                        :title="d.esGrupal ? 'Prorrateado (costo por grupo)' : 'Unitario (costo por pax)'">
                      {{ d.esGrupal ? 'Prorrateado' : 'Unitario' }}
                    </span>
                  <span v-if="d.rol === 'operativo'" class="text-[8px] font-black px-1.5 py-0.5 rounded border uppercase bg-slate-100 text-slate-400 border-slate-200">
                      <i class="fas fa-wrench"></i> Op
                    </span>
                </div>
                <span class="font-black text-slate-800">{{ montoLinea(d) }}</span>
                <span class="text-[10px] font-bold text-slate-400 ml-1">{{ d.moneda === 'PEN' ? 'S/.' : 'US$' }}</span>
              </td>

              <!-- Celda única: servicio (azul acero) arriba, componente (gris) debajo -->
              <td class="px-5 py-3">
                <p class="text-[11px] font-black uppercase tracking-tight" style="color:#376875">
                  {{ store.getI18nText(d.servicioNombre as any, lang) }}
                </p>
                <p class="text-slate-500 font-medium leading-snug">
                  {{ store.getI18nText(d.componenteNombre as any, lang) }}
                  <span v-if="labelTarifa(d)" class="text-slate-400">({{ labelTarifa(d) }})</span>
                </p>
                <p v-if="formatModCat(d.modalidad, d.categoria) || d.comisionOverride" class="text-[10px] font-bold mt-0.5">
                  <span class="text-sky-700">{{ formatModCat(d.modalidad, d.categoria) }}</span>
                  <span v-if="d.comisionOverride" class="text-purple-600 ml-2">com. {{ d.comisionOverride }}%</span>
                </p>
              </td>

              <td class="text-right px-5 py-3 font-black text-slate-800">{{ mv(d.ventaSoles, d.ventaDolares) }}</td>
            </tr>
            </tbody>
          </table>

          <!-- Subtotales por modo (POR PAX) -->
          <div class="px-5 py-3 bg-slate-50 border-t border-slate-100">
            <div class="flex flex-wrap gap-2">
              <div v-for="f in filasPorModo(clase)" :key="f.key"
                   class="flex items-center gap-2 bg-white border border-slate-200 rounded-xl px-3 py-2 shadow-sm">
                <span class="text-[9px] font-black uppercase"
                      :class="f.key === 'ctaPax' ? 'text-red-500' : f.key === 'cortesia' ? 'text-sky-600' : 'text-emerald-600'">
                  {{ f.label }}
                </span>
                <span class="text-[10px] font-bold text-slate-400">costo {{ mv(f.costoSoles, f.costoDolares) }}</span>
                <span class="text-[11px] font-black text-slate-800">venta {{ mv(f.ventaSoles, f.ventaDolares) }}</span>
              </div>
            </div>
          </div>

          <div v-if="clase.conflictos?.length" class="px-5 py-3 border-t border-red-100 bg-red-50/50">
            <p class="text-[9px] font-black text-red-500 uppercase tracking-widest mb-1.5">Origen del conflicto:</p>
            <p v-for="(c, i) in clase.conflictos" :key="i" class="text-[10px] font-bold text-red-700">• {{ c }}</p>
          </div>
        </div>
      </section>

      <!-- ══ 3 · Inclusiones (acordeón por servicio, con contadores) ══ -->
      <section v-for="srv in fin.inclusiones" :key="srv.servicioId"
               class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
        <button @click="toggle('inc:' + srv.servicioId)" class="w-full px-5 py-4 flex items-center justify-between hover:bg-slate-50 transition-colors">
          <span class="font-black text-sm text-emerald-800 truncate pr-3">
            {{ store.getI18nText(srv.servicioNombre as any, lang) }}
          </span>
          <span class="flex items-center gap-2 flex-shrink-0 text-[10px] font-black">
            <span v-if="contadorInclusiones(srv).ok" class="text-emerald-600"><i class="fas fa-check-circle"></i> {{ contadorInclusiones(srv).ok }}</span>
            <span v-if="contadorInclusiones(srv).no" class="text-red-500"><i class="fas fa-times-circle"></i> {{ contadorInclusiones(srv).no }}</span>
            <span v-if="contadorInclusiones(srv).cort" class="text-sky-500"><i class="fas fa-gift"></i> {{ contadorInclusiones(srv).cort }}</span>
            <span v-if="contadorInclusiones(srv).opc" class="text-amber-500"><i class="fas fa-circle-question"></i> {{ contadorInclusiones(srv).opc }}</span>
            <i class="fas fa-chevron-down text-slate-300 ml-1 transition-transform" :class="isOpen('inc:' + srv.servicioId) ? 'rotate-180' : ''"></i>
          </span>
        </button>

        <div v-show="isOpen('inc:' + srv.servicioId)" class="border-t border-slate-100 px-5 py-4 space-y-4">
          <div v-for="sec in seccionesInclusion(srv)" :key="sec.key">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">{{ sec.titulo }}</p>
            <ul class="space-y-1">
              <li v-for="(l, i) in sec.lineas" :key="i"
                  class="rounded-xl px-3 py-2 hover:bg-slate-50 transition-colors">
                <div class="flex items-start justify-between gap-3">
                  <p class="text-[13px] font-bold text-slate-800 flex items-start gap-2 min-w-0">
                    <i class="fas mt-0.5 flex-shrink-0" :class="sec.icono"></i>
                    <span>
                      {{ labelInclusion(l) }}
                      <b v-if="l.cantidadComponente > 1" class="text-slate-500">x {{ l.cantidadComponente }}</b>
                    </span>
                  </p>
                  <span class="text-[9px] font-bold text-slate-400 bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5 flex-shrink-0 whitespace-nowrap">
                    {{ l.fecha }}
                  </span>
                </div>

                <!-- Sub-líneas: tarifa(s) del componente / herencia del item -->
                <div v-if="l.tarifas.length === 0 && (l.tarifaTitulo.length || formatModCat(l.modalidad, l.categoria))"
                     class="ml-7 mt-0.5 text-[12px] text-slate-500">
                  <span v-if="l.tarifaTitulo.length">- {{ store.getI18nText(l.tarifaTitulo as any, lang) }}</span>
                  <span v-if="formatModCat(l.modalidad, l.categoria)" class="text-sky-700 font-bold ml-1">
                    {{ formatModCat(l.modalidad, l.categoria) }}
                  </span>
                </div>
                <div v-for="(t, ti) in l.tarifas" :key="ti"
                     class="ml-7 mt-0.5 flex items-center justify-between gap-3 text-[12px] text-slate-500">
                  <span class="min-w-0">
                    - {{ store.getI18nText(t.tarifaTitulo as any, lang) }}
                    <span v-if="formatModCat(t.modalidad, t.categoria)" class="text-sky-700 font-bold">{{ formatModCat(t.modalidad, t.categoria) }}</span>
                    <b v-if="!t.esGrupal" class="text-slate-600">x {{ t.cantidad }}</b>
                    <span v-if="t.notaRol.length" class="block ml-2 text-[11px] text-slate-400 italic">
                      {{ store.getI18nText(t.notaRol as any, lang) }}
                    </span>
                  </span>
                  <span v-if="chipMonto(t)" class="text-[10px] font-black text-orange-600 bg-orange-50 border border-orange-100 rounded px-1.5 py-0.5 flex-shrink-0 whitespace-nowrap">
                    {{ chipMonto(t) }}
                  </span>
                </div>
              </li>
            </ul>
          </div>
        </div>
      </section>

      <!-- ══ 4 · Upgrades (acordeón) ══ -->
      <section v-if="fin.opcionesUpgrade.length" class="bg-white border border-purple-200 rounded-2xl shadow-sm overflow-hidden">
        <button @click="toggle('upgrades')" class="w-full px-5 py-4 flex items-center justify-between hover:bg-purple-50/40 transition-colors">
          <span class="font-black text-sm uppercase tracking-wide flex items-center gap-2 text-purple-700">
            <i class="fas fa-right-left"></i> Opciones de upgrade
          </span>
          <span class="flex items-center gap-2">
            <span class="text-[10px] font-black bg-purple-100 text-purple-700 rounded-full px-2 py-0.5">{{ fin.opcionesUpgrade.length }}</span>
            <i class="fas fa-chevron-down text-slate-300 transition-transform" :class="isOpen('upgrades') ? 'rotate-180' : ''"></i>
          </span>
        </button>
        <div v-show="isOpen('upgrades')" class="border-t border-slate-100 p-4 grid gap-3 md:grid-cols-2">
          <div v-for="(o, i) in fin.opcionesUpgrade" :key="i" class="bg-slate-50 border border-slate-200 rounded-xl p-4">
            <p class="text-[11px] font-black uppercase tracking-tight" style="color:#376875">
              {{ store.getI18nText(o.servicioNombre as any, lang) }}
            </p>
            <p class="text-[13px] font-bold text-slate-600">{{ store.getI18nText(o.componenteNombre as any, lang) }}</p>
            <p class="text-sm font-black text-slate-800 mt-1">
              {{ store.getI18nText(o.tarifaTitulo as any, lang) }}
              <span v-if="formatModCat(o.modalidad, o.categoria)" class="text-[10px] text-sky-700 font-bold ml-1">{{ formatModCat(o.modalidad, o.categoria) }}</span>
            </p>
            <p v-if="o.notaRol.length" class="text-[11px] text-slate-500 italic">{{ store.getI18nText(o.notaRol as any, lang) }}</p>
            <div class="mt-2 pt-2 border-t border-slate-200 flex justify-between items-center">
              <span class="text-[10px] font-bold text-slate-400">std $ {{ n2(o.ventaPorPaxEstandar) }} → alt $ {{ n2(o.ventaPorPaxAlternativa) }}</span>
              <span class="text-sm font-black" :class="o.deltaVentaPorPax >= 0 ? 'text-purple-700' : 'text-emerald-700'">
                {{ o.deltaVentaPorPax >= 0 ? '+' : '−' }}$ {{ n2(Math.abs(o.deltaVentaPorPax)) }}/pax
              </span>
            </div>
          </div>
        </div>
      </section>

    </div>
  </div>
</template>
