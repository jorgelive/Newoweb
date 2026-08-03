# UI — Componentes compartidos de `util/`

Convenciones de interfaz que valen para **toda** la app interna, no para un módulo concreto.
Si un patrón se repite en dos vistas, su sitio es aquí y no duplicado en cada una.

---

## Índice

1. [FechaHoraPicker — el selector de fecha/hora del proyecto](#1-fechahorapicker--el-selector-de-fechahora-del-proyecto)
2. [Dónde tocar para cambiar X](#2-dónde-tocar-para-cambiar-x)

---

## 1. FechaHoraPicker — el selector de fecha/hora del proyecto

**Archivo:** `util/src/components/common/FechaHoraPicker.vue`

> **Regla: cualquier campo de fecha (con o sin hora) en `util/` usa este componente.**
> No se usa `<input type="datetime-local">` ni `<input type="date">` nativos.

### 1.1 Por qué no el input nativo

- **El formato depende del idioma del sistema operativo.** En un equipo configurado en inglés
  el nativo muestra AM/PM; aquí se trabaja siempre en **24 h**. El operador que teclea "14:00"
  en un equipo y "02:00 PM" en otro comete errores.
- **Cada navegador lo pinta y lo maneja distinto**, así que la interfaz deja de ser predecible.
- **No admite máscara al escribir.** Con `imask` se teclea de corrido `13072026 1400` sin tocar
  separadores, que es como se rellena un formulario a velocidad.

Se apoya en `@vuepic/vue-datepicker` (calendario) + `imask` (máscara estricta). Ambas ya estaban
en el proyecto: el patrón nació en `CotizacionEditorView.vue` y se extrajo a componente para no
volver a copiarlo.

### 1.2 Uso

```vue
<FechaHoraPicker v-model="form.inicio" />                      <!-- fecha + hora 24 h -->
<FechaHoraPicker v-model="form.fecha" solo-fecha />            <!-- sólo fecha -->
<FechaHoraPicker v-model="form.fin" :min-date="form.inicio" :invalido="!!error" />
```

| Prop | Para qué |
|---|---|
| `modelValue` | Hora de pared `"YYYY-MM-DDTHH:mm"` (admite segundos y los ignora) |
| `soloFecha` | Oculta el reloj |
| `minDate` | Límite inferior, mismo formato |
| `disabled` / `invalido` | Bloqueado / marcado en rojo por el formulario que lo contiene |

**Ojo al combinar con un handler propio:** si necesitas reaccionar al cambio, **no** pongas
`v-model` y `@update:model-value` a la vez — el orden de ejecución no está garantizado y el
handler puede leer el valor anterior. Usa la forma explícita:

```vue
<FechaHoraPicker :model-value="form.inicio"
    @update:model-value="(v) => { form.inicio = v; alCambiar(); }" />
```

### 1.3 🕒 Hora de pared, nunca instantes

El `modelValue` es una cadena **sin zona horaria**, y todas las conversiones internas se hacen
**recortando texto, jamás con `new Date()`**. Es deliberado: las 14:00 de un check-in son las
14:00 en recepción, no un instante universal. Pasarlas por `Date` les aplicaría el offset del
navegador y desplazaría la hora — el fallo real que documenta §12.5.5 de
`PmsBeds24ReservasSync.md`, donde una hora guardada como 14:00 se mostraba como 09:00.

El componente encaja de fábrica con ese criterio: `model-type="yyyy-MM-dd'T'HH:mm:ss"` de
`vue-datepicker` es justo una fecha naive.

### 1.4 Ancho mínimo y salto de línea en móvil

`"DD/MM/AAAA HH:MM"` son 16 caracteres. A `text-sm` con `tabular-nums` ocupan ~134 px, más el
padding horizontal (`px-3` ×2 = 24 px) y el borde: **~160 px de contenido**. Se redondea a
**`11rem` (176 px)** como mínimo cómodo.

Para que dos selectores se pongan uno debajo del otro cuando no caben, **no se usan media
queries**: basta una rejilla que calcule el corte sola.

```html
<div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr))">
    <label>…check-in…</label>
    <label>…check-out…</label>
</div>
```

`auto-fit` + `minmax(11rem, 1fr)` mantiene las dos columnas mientras el contenedor supere
`2 × 11rem + gap` (≈ 23rem / 368 px) y baja a una sola por debajo. Si cambias el tamaño de
fuente del input, recalcula ese `11rem` o el texto se cortará antes de saltar.

---

## 2. Dónde tocar para cambiar X

| Necesidad | Archivo | Método/Campo |
|---|---|---|
| Cambiar el formato visible de la fecha | `FechaHoraPicker.vue` | `aTextoVisible()` / `format` del `VueDatePicker` |
| Cambiar el rango de años admitido al teclear | `FechaHoraPicker.vue` | bloque `Y` de la máscara (hoy 2024-2099) |
| Cambiar el ancho al que saltan de línea (§1.4) | Vista que los coloca | `minmax(11rem, 1fr)` de la rejilla |
| Permitir segundos | `FechaHoraPicker.vue` | `PATRON_ISO`, `aTextoVisible()`, `onPickerChange()` |
