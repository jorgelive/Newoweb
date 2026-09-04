import { describe, it, expect, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useCotizacionEditorStore } from './cotizacionEditorStore';

/**
 * La fecha del servicio la manda el componente ORIGINAL, nunca una copia.
 *
 * ── El caso real que lo motivó ──────────────────────────────────────────────
 * Un vuelo sale a las 22:00 de un día y el otro a las 02:00 del siguiente. Son el mismo servicio y
 * dos componentes con fecha distinta — eso es correcto y es justo lo que el reparto vino a
 * permitir.
 *
 * Lo que no puede pasar es que la copia **arrastre al servicio**. Si contara, bastaría con que
 * saliera antes que el original para llevarse el servicio al día anterior, y con él los segmentos,
 * el relato y el orden del itinerario. Cada reparto movería la escaleta entera.
 *
 * ⚠️ Y no sólo en el caso raro: en uno normal, una copia recién creada con la hora todavía sin
 * ajustar mandaría sobre el original mientras se la termina de configurar.
 *
 * ── Por qué un test y no una lectura ────────────────────────────────────────
 * Es aritmética de fechas sobre una lista filtrada: se rompe sin dar error, y el síntoma —el
 * servicio en otro día— aparece lejos de la línea que lo causó.
 */

/** Un componente con lo justo que lee `sincronizarFechaServicio`. */
const comp = (fechaHoraInicio: string, duplicadoDe: string | null = null) => ({
  id: crypto.randomUUID(),
  duplicadoDe,
  fechaHoraInicio,
  fechaHoraFin: fechaHoraInicio,
} as never);

const servicio = (...componentes: unknown[]) => ({
  id: crypto.randomUUID(),
  fechaInicioAbsoluta: '2026-09-17',
  cotcomponentes: componentes,
} as never);

describe('el ancla de la fecha del servicio', () => {
  beforeEach(() => setActivePinia(createPinia()));

  it('toma la fecha del componente original', () => {
    const store = useCotizacionEditorStore();
    const s = servicio(comp('2026-09-18T22:00:00'));

    store.sincronizarFechaServicio(s);

    expect((s as { fechaInicioAbsoluta: string }).fechaInicioAbsoluta).toBe('2026-09-18');
  });

  it('🔥 una copia ANTERIOR no arrastra al servicio', () => {
    const store = useCotizacionEditorStore();
    const original = comp('2026-09-18T22:00:00');
    const s = servicio(original, comp('2026-09-17T02:00:00', (original as { id: string }).id));

    store.sincronizarFechaServicio(s);

    // Sin la regla, el mínimo de todos daría el 17 y el servicio se mudaría de día.
    expect((s as { fechaInicioAbsoluta: string }).fechaInicioAbsoluta).toBe('2026-09-18');
  });

  it('una copia POSTERIOR tampoco lo mueve', () => {
    const store = useCotizacionEditorStore();
    const original = comp('2026-09-18T22:00:00');
    const s = servicio(original, comp('2026-09-19T02:00:00', (original as { id: string }).id));

    store.sincronizarFechaServicio(s);

    expect((s as { fechaInicioAbsoluta: string }).fechaInicioAbsoluta).toBe('2026-09-18');
  });

  it('con VARIOS originales manda el más temprano, como siempre', () => {
    const store = useCotizacionEditorStore();
    const s = servicio(comp('2026-09-18T22:00:00'), comp('2026-09-17T06:00:00'));

    store.sincronizarFechaServicio(s);

    expect((s as { fechaInicioAbsoluta: string }).fechaInicioAbsoluta).toBe('2026-09-17');
  });

  it('⚠️ si SÓLO quedan copias, se usan: mejor una fecha que ninguna', () => {
    const store = useCotizacionEditorStore();
    // Pasa si alguien borra el original de un reparto ya hecho.
    const s = servicio(comp('2026-09-20T02:00:00', crypto.randomUUID()));

    store.sincronizarFechaServicio(s);

    expect((s as { fechaInicioAbsoluta: string }).fechaInicioAbsoluta).toBe('2026-09-20');
  });
});
