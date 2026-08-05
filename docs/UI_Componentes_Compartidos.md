# UI — Componentes compartidos de `util/`

Convenciones de interfaz que valen para **toda** la app interna, no para un módulo concreto.
Si un patrón se repite en dos vistas, su sitio es aquí y no duplicado en cada una.

---

## Índice

1. [FechaHoraPicker — el selector de fecha/hora del proyecto](#1-fechahorapicker--el-selector-de-fechahora-del-proyecto)
2. [Historial del navegador — nunca reemplaces `history.state`](#2-historial-del-navegador--nunca-reemplaces-historystate)
3. [AppSwitcher — saltar entre módulos](#3-appswitcher--saltar-entre-módulos)
4. [Dónde tocar para cambiar X](#4-dónde-tocar-para-cambiar-x)

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

## 2. Historial del navegador — nunca reemplaces `history.state`

> **Regla: para marcar algo propio en el historial se ESCRIBE SOBRE el estado existente.**
> ```ts
> history.replaceState({ ...history.state, view: 'sidebar' }, '');   // ✅
> history.replaceState({ view: 'sidebar' }, '');                     // ❌ rompe el router
> ```

Vue Router guarda en `history.state` sus propias claves —`current`, `position`, `back`,
`forward`, `scroll`— y las lee para construir la URL de la navegación siguiente. Reemplazar el
objeto se las lleva por delante.

**Qué se ve cuando pasa** (costó semanas de caza): la app navega a un dominio que no existe,
`https://util.openperu.peundefined`, y el navegador responde `DNS_PROBE_FINISHED_NXDOMAIN`.
Parece un problema de DNS, de caché, del Service Worker o de las notificaciones push — y no es
ninguno. El cálculo está en `vue-router`, en `changeLocation()`:

```js
url = createBaseLocation() + base + to
//    'https://util.openperu.pe'  +  ''  +  undefined
```

Dos detalles hacen que el `undefined` se pegue al **host** y no al path, que es lo que despista:

- `createBaseLocation()` devuelve `protocol + '//' + host`, **sin barra final**.
- `base` es **cadena vacía**: `normalizeBase('/')` le quita la barra a `/`.

Y `to` sale `undefined` porque `push()` hace `changeLocation(currentState.current, …)` con el
`current` que alguien borró. El disparador no es quien rompe el estado, sino **el siguiente
`router.push()`**: un botón «volver al inicio», el clic en un toast de notificación… De ahí que
el síntoma aparezca en sitios que no tienen nada que ver con el causante.

vue-router **sí** avisa por consola («history.state seems to have been manually replaced without
preserving the necessary values»), pero el `warn` vive dentro de un `if (NODE_ENV !==
'production')`: en el bundle desplegado no queda rastro. Si se persigue un `undefined` en una
URL, **reproducirlo en dev y mirar la consola es el atajo**.

Hoy la única vista que toca el historial a mano es el chat —`marcarVistaEnHistorial()` en
`util/src/views/ChatView.vue`, que apila una entrada «fantasma» para que el gesto «atrás» del
móvil cierre la conversación en vez de salir de la app—, y ya lo hace bien. Cualquier vista
nueva que quiera el mismo truco: copia ese helper, no el `replaceState` a pelo.

---

## 3. AppSwitcher — saltar entre módulos

**Archivos:** `util/src/components/common/AppSwitcher.vue` · `util/src/types/modulosApp.ts`

> **Regla: la cabecera de una vista raíz lleva `<AppSwitcher />`, no un botón propio de
> «volver al inicio».**

Cada módulo tenía su botón de casa, así que el único camino de Reservas a Cotizaciones era
pasar por el portal y volver a entrar. El selector despliega los módulos agrupados por negocio
y marca en cuál estás; «Inicio» es una opción más del panel, no el único camino.

**El catálogo de módulos es una fuente única**: `MODULOS_APP` en `util/src/types/modulosApp.ts`.
Lo consumen el mosaico de `HomeView` y este componente, así que **un módulo nuevo se declara
una vez** y aparece en los dos sitios. Antes la lista vivía dentro de `HomeView`, que es
justamente por lo que no había navegación lateral.

Detalles que no se ven leyendo el componente:

- **`moduloDeRuta()` compara por PREFIJO**, no por igualdad: estando en
  `/cotizacion/<id>/version/<x>` el módulo activo sigue siendo Cotizaciones. En `/` devuelve
  `null` — el portal es el índice, no un módulo.
- **`variante`** (`'oscura'` por defecto, `'clara'`): las cabeceras del portal no son iguales
  —calendarios y chat sobre `slate-900`, cotizaciones sobre blanco— y el botón tiene que
  contrastar con la suya.
- **El panel es `fixed` y se ancla al BOTÓN, midiéndolo al abrir**
  (`getBoundingClientRect()`), no a un `top` fijo. Cada cabecera del portal mide distinto —la de
  Reservas lleva además la barra de búsqueda debajo— y con una coordenada fija el panel nacía
  detrás de ella y se comía sus primeras filas, la de «Inicio» incluida. Un panel `absolute`
  tampoco vale: se recorta contra el `overflow` de los contenedores.
- **Va en `z-[60]`**, por encima de todo lo que montan las vistas: cabeceras (z-20/30), barras
  de búsqueda (z-30), overlays de menú contextual (z-30) y drawers (z-50).
- **Y aun así hace falta `<Teleport to="body">`.** Es el gotcha caro de este componente: la
  cabecera que lo monta suele ser un **flex item con `z-20`**, y un flex item con `z-index`
  crea contexto de apilamiento **aunque su `position` sea `static`** — la excepción de la spec
  que nadie recuerda. Encerrado ahí dentro, el panel queda por debajo de cualquier cosa de la
  vista con z-index mayor sin importar el suyo: en Reservas, la barra de búsqueda (`z-30`) le
  tapaba la primera fila, la de «Inicio», y parecía que el selector «no tenía Inicio». Fuera
  del árbol el problema desaparece; es el mismo motivo por el que tippy monta en
  `document.body`. Como consecuencia, el cierre por clic fuera comprueba **dos** elementos (el
  botón y el panel): teletransportado, el panel ya no cuelga del componente.
- **El cierre por clic fuera escucha en `document` con `capture: true`.** Las vistas de
  calendario montan overlays a pantalla completa que se tragan el clic antes de que burbujee;
  sin la fase de captura el panel se quedaba abierto detrás de ellos.
- **Convive con los guardas de salida.** `ReservasView` y `TarifasView` cancelan la primera
  navegación si tienen un menú o un drawer abierto (§ *Botón atrás* de
  `docs/Calendar_architecture.md`): ahí el primer clic en un módulo cierra esa capa y hay que
  repetirlo. Es el mismo comportamiento que ya tenía el botón de inicio.

---

### 1.5 Fechas que no se pueden vaciar

`borrable=false` quita la «x» del picker. Es el caso de las fechas de una estancia: una estancia
sin entrada o sin salida no existe, y ese botón sólo servía para dejar el formulario en un estado
que el backend rechaza. El resto de usos (Operación) siguen pudiendo limpiar el campo.

Junto a `diaBloqueado` (§ del doc de sincronización, 7.1.b) son las dos formas de acotar el
campo: una prohíbe cambiar el día, la otra prohíbe dejarlo vacío.

## 4. Dónde tocar para cambiar X

| Necesidad | Archivo | Método/Campo |
|---|---|---|
| Cambiar el formato visible de la fecha | `FechaHoraPicker.vue` | `aTextoVisible()` / `format` del `VueDatePicker` |
| Cambiar el rango de años admitido al teclear | `FechaHoraPicker.vue` | bloque `Y` de la máscara (hoy 2024-2099) |
| Cambiar el ancho al que saltan de línea (§1.4) | Vista que los coloca | `minmax(11rem, 1fr)` de la rejilla |
| Permitir segundos | `FechaHoraPicker.vue` | `PATRON_ISO`, `aTextoVisible()`, `onPickerChange()` |
| Marcar una vista propia en el historial (gesto «atrás») | `ChatView.vue` | `marcarVistaEnHistorial()` — conserva `history.state`, §2 |
| Añadir un módulo al portal y al selector | `util/src/types/modulosApp.ts` | `MODULOS_APP` — sale en HomeView y en AppSwitcher a la vez |
| Cambiar el aspecto del selector en una cabecera clara | Vista que lo monta | `<AppSwitcher variante="clara" />` |
| Usar el logotipo del portal como selector | Vista que lo monta | `<AppSwitcher variante="marca" sin-inicio />` — `sinInicio` para no ofrecer «Inicio» estando en él |
