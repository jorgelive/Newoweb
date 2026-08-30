# UI — Componentes compartidos de `util/`

Convenciones de interfaz que valen para **toda** la app interna, no para un módulo concreto.
Si un patrón se repite en dos vistas, su sitio es aquí y no duplicado en cada una.

---

## Índice

1. [FechaHoraPicker — el selector de fecha/hora del proyecto](#1-fechahorapicker--el-selector-de-fechahora-del-proyecto)
    · [1.6 `min-date` con hora arrastra la hora del otro campo](#16--min-date-con-hora-arrastra-la-hora-del-otro-campo)
2. [Historial del navegador — nunca reemplaces `history.state`](#2-historial-del-navegador--nunca-reemplaces-historystate)
3. [AppSwitcher — saltar entre módulos](#3-appswitcher--saltar-entre-módulos)
3.b [Enfocar lo que se acaba de abrir — `utils/scrollEnfoque.ts`](#3b-enfocar-lo-que-se-acaba-de-abrir--utilsscrollenfoquets)
3.c [AsistenteBar — preguntar al PMS en lenguaje natural](#3c-asistentebar--preguntar-al-pms-en-lenguaje-natural)
3.d [ConversacionVistaPrevia — asomarse a un chat sin abrirlo](#3d-conversacionvistaprevia--asomarse-a-un-chat-sin-abrirlo)
3.e [InfoTooltip — la ayuda detrás de una «i», y el hover que no existe en táctil](#3e-infotooltip--la-ayuda-detrás-de-una-i-y-el-hover-que-no-existe-en-táctil)
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
<FechaHoraPicker v-model="form.fin" :min-date="form.inicio.slice(0, 10)" :invalido="!!error" />
```

| Prop | Para qué |
|---|---|
| `modelValue` | Hora de pared `"YYYY-MM-DDTHH:mm"` (admite segundos y los ignora) |
| `soloFecha` | Oculta el reloj |
| `minDate` | Límite inferior, mismo formato. **Léete §1.6 antes de pasarle la hora.** |
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

## 2.b ProveedorFormulario — los campos de identidad de una empresa

`util/src/components/common/ProveedorFormulario.vue`. Lo usan dos pantallas:

| Quién | Para qué |
|---|---|
| `Catalogo/ProveedoresView.vue` | el alta y edición de siempre |
| `Cotizaciones/CotizacionEditorView.vue` | alta **inline**, sin salir del editor (`compacto`) |

### Por qué es un componente y no un bloque copiado

Porque de ello depende una regla de datos, no una comodidad. `Proveedor::POST` lo dice:
**el prestador debe quedar SIEMPRE identificado contra el maestro**; mientras el alta vivía
sólo en el catálogo, la salida rápida era escribir texto libre, que deja
`prestadorMaestroId` vacío y rompe el histórico financiero.

La única forma de que nadie escriba texto suelto es que **dar de alta cueste lo mismo**. Y
en cuanto hay dos sitios que dan de alta, o el formulario es uno o divergen — y el que
diverge es siempre el de la ventanita.

### Contrato

```
v-model                        ProveedorWrite
v-model:lugaresSeleccionados   string[]  · IRIs de TravelLugar (cobertura)
:lugares                       LugarOpcion[]  · vacío = no se pinta la sección
:compacto                      oculta razón social, dirección y web
```

⚠️ **El título público se edita en plano y se convierte dentro.** El campo es
`I18nContent[]` pero se escribe sólo en español —`AutoTranslate` rellena el resto al
guardar—. Esa conversión era un paso suelto en el `guardar()` del catálogo, y es
exactamente lo que se olvida al copiar un formulario: ahora vive en el componente.

⚠️ **Servicios y galería se quedan fuera.** Los dos necesitan el IRI del proveedor ya
creado para colgarse de él; incluirlos obligaría al alta inline a pintar secciones muertas.

## 2.c Permisos en la UI — botón apagado, nunca error al pulsar

`util/src/stores/permisosStore.ts` + `GET /tipo/user/enum/permisos`.

Hasta 2026-08-16 el SPA no sabía nada de roles: el backend rechazaba lo que no tocaba y el
usuario se enteraba **al pulsar**. Para un botón de alta eso se lee como «la aplicación
está rota», no como «no tienes permiso».

```ts
const permisos = usePermisosStore();
onMounted(() => permisos.cargar());   // idempotente, se llama en cada vista

:disabled="!permisos.puede('ROLE_MAESTROS_WRITE')"
:title="permisos.motivo('ROLE_MAESTROS_WRITE', 'dar de alta empresas en el catálogo')"
```

**Este es el comportamiento para todas las altas**, no sólo la de proveedores: control
apagado + `title` explicando por qué. Apagar sin decir el motivo genera más consultas que
el error que se quería evitar, así que `motivo()` obliga a escribirlo.

⚠️ **No es el candado.** Sólo sirve para pintar; quien decide es el `#[IsGranted]` de cada
endpoint, porque cualquiera puede mentirle a su propio navegador. Si una comprobación vive
**sólo** aquí, deja de ser una comprobación.

⚠️ **Optimista mientras carga**: antes de la primera respuesta `puede()` devuelve `true`.
Devolver `false` haría parpadear cada pantalla con todo deshabilitado, y eso se lee como
«no tengo permisos» en vez de «aún no lo sé». El peor caso de equivocarse hacia este lado
es un error del backend — exactamente lo que había antes.

⚠️ Los permisos se resuelven con `isGranted()`, **no** leyendo `user.roles`. Jorge tiene
`ROLE_MAESTROS_DELETE` y no tiene escrito `ROLE_MAESTROS_WRITE`: lo hereda por
`role_hierarchy`. Mirar la columna diría que no puede crear proveedores, y sí puede.

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

- **`SearchableSelect.vue` sigue el mismo patrón** (2026-08-18). Su lista desplegable era
  `absolute z-[110]` dentro del propio componente y se recortaba contra el `overflow-y-auto` del
  panel del editor de cotización, además de quedar tapada por la barra de totales inferior. Ahora
  la lista va en `<Teleport to="body">` con `position: fixed`, anclada al disparador por
  `getBoundingClientRect()` (`posicionar()`), y **se abre hacia arriba** si no cabe abajo —el caso
  de un selector pegado al footer en móvil—. El cierre por clic fuera comprueba disparador **y**
  lista, y se reposiciona en `scroll`/`resize` mientras está abierta. Es un componente muy
  reutilizado (tarifas, prestador, comprador, servicios): cualquier cambio ahí se prueba en varias
  pantallas.

### ⚠️ La factura del teletransporte: la `z` deja de ser relativa (2026-08-29)

Sacar la lista al `body` arregla el recorte y **crea un problema nuevo**: fuera del modal ya no
hereda su contexto de apilamiento, así que pasa a competir con él **de tú a tú**.

Nació con `z-[200]`, y los modales de la app van de `z-1000` a `z-1500`. Resultado: dentro de
cualquier modal la lista se abría **detrás de un panel opaco**.

**El síntoma engaña**: un selector que no lista nada. Ni error en consola, ni fallo en la API —
parecía que el catálogo estaba vacío. Se fue a buscar al backend (grupos de serialización, caché
de metadatos, la plantilla en producción) antes de mirar la capa que lo tapaba. Costó una sesión.

Y **es general, no de una pantalla**: son 13 instancias dentro de modales — 8 en el editor de
cotizaciones, 3 en la ficha de expediente, 2 en Operación.

La escalera de la app, que ahora vive escrita en el propio componente:

```
10–50       adornos dentro de una tarjeta
100–500     cabeceras pegajosas, barras de totales
1000–1500   modales a pantalla completa
5000        la lista de SearchableSelect     ← por encima de cualquier modal que la contenga
9999        avisos globales: toasts, sesión caducada, banner de PWA
```

**La regla que sale de aquí:** todo lo que se teletransporte al `body` tiene que declarar una `z`
por encima de la capa más alta que pueda contenerlo. Un componente teletransportado no puede
razonar «estoy dentro de X»; ya no lo está.

⚠️ Si algún día hace falta un modal por encima de 5000, hay que **subir la lista con él**.
- **`limpiable` — la «×» para soltar la selección** (2026-08-23). Opt-in y por buenas razones: la
  mayoría de estos selectores son **obligatorios** —un servicio maestro, una moneda— y ahí un botón
  de limpiar sólo sirve para dejar el formulario inválido. Se activa donde desvincular es una
  operación legítima, hoy el insumo maestro de un componente de cotización.
  ⚠️ Emite `null` por `update:modelValue` **y por `change`**: los padres cuelgan de `change` sus
  efectos en cascada —recargar tarifas, resetear el prestador—, y desvincular tiene que dispararlos
  igual que vincular. Y el botón lleva `@click.stop`, porque el disparador entero es el que abre la
  lista: sin él, limpiar la abriría acto seguido.
- **`multiple` — elegir varios** (2026-08-25). También opt-in: con la bandera, `modelValue` es una
  **lista** y cada clic añade o quita. Sin ella el componente se comporta exactamente como antes,
  con un valor suelto — las instancias existentes no se tocan. Hoy la usa el selector de
  ubicaciones de un componente manual de cotización.
  - **La lista NO se cierra al elegir.** Elegir tres seguidas es el caso normal, y cerrarla entre
    una y otra obliga a reabrir y a volver a buscar.
  - **El disparador enseña los NOMBRES, no «3 seleccionadas».** Con la lista cerrada es lo único
    que se ve; un contador obliga a abrirla para saber qué hay dentro. Si no caben, el `truncate`
    los corta — verlos a medias sigue diciendo más que contarlos.
  - **Dentro, la selección es SIEMPRE una lista** (`seleccion`), en los dos modos; sólo `select()`
    y `limpiar()` se bifurcan. Repetir el `if (multiple)` por todo el componente es donde se cuela
    la diferencia de comportamiento entre un modo y el otro.
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

⚠️ **Y hay que acordarse también donde NO se usa este componente.** «Inicio Exacto» y «Fin
Exacto» del editor de cotización montan `VueDatePicker` **directo**, no `FechaHoraPicker`, así que
no heredaban nada: `clearable` viene a `true` por defecto y la «x» seguía ahí. Los dos campos
están marcados obligatorios con `*` y un componente sin inicio o sin fin no existe — el botón sólo
servía para dejar el formulario en un estado que el backend rechaza, con el error a dos pantallas
de distancia. Corregido el 29/08/2026 con `:clearable="false"` en los dos.

**La lección es sobre el envoltorio, no sobre la fecha:** una decisión que vive en un componente
compartido no alcanza a quien usa la librería por debajo. Al añadir una regla a `FechaHoraPicker`,
comprobar quién sigue montando `VueDatePicker` a pelo.

#### ⚠️ Y aquel arreglo NO funcionó: en vue-datepicker 14, `clearable` va dentro de `input-attrs` (30/08/2026)

`:clearable="false"` es **inerte** con la versión que usamos. En la 14 dejó de ser prop de primer
nivel y se mudó a `InputAttributesConfig`, el objeto que se pasa por `input-attrs`:

```vue
<!-- ❌ no hace nada: Vue lo trata como atributo de paso y la «x» sigue -->
<VueDatePicker :clearable="false" />

<!-- ✅ -->
<VueDatePicker :input-attrs="{ clearable: false }" />
```

Estaba mal en los **tres** sitios: los dos del editor de cotización y el `:clearable="borrable"`
de `FechaHoraPicker` —así que las fechas de estancia tampoco estaban protegidas, y eso llevaba
más tiempo—.

**Lo que hace este caso digno de la lista de «un atributo mal puesto no falla»:** no dio error de
compilación, ni aviso de ESLint, ni nada en consola. El bundle desplegado contenía `clearable:!1`
—lo comprobé— y la «x» seguía en pantalla. Se descartó caché entrando de incógnito, y aun así.
Lo que quedaba era leer el `dist` de la librería y ver que la prop se consultaba como
`C.value.clearable`, dentro de un objeto de configuración, no en la raíz.

⚠️ **Un atributo desconocido en un componente Vue no es un error**: se convierte en atributo de
paso del elemento raíz. Por eso `vue-tsc` no dijo nada. La forma buena **sí** está tipada —
`Partial<InputAttributesConfig>`—, y escribir `clearabl` dentro del objeto la caza al vuelo:

```
error TS2561: Object literal may only specify known properties, but 'clearabl' does not exist
in type 'Partial<InputAttributesConfig>'. Did you mean to write 'clearable'?
```

Es decir: pasar por el objeto no sólo arregla el fallo, **cambia de categoría el error futuro**,
de mudo a ruidoso. Cuando una librería ofrece las dos formas, la agrupada es la que se comprueba.

### 1.6 ⚠️ `min-date` con hora arrastra la hora del otro campo

`minPicker` propaga a `VueDatePicker` la hora que venga en `minDate`, y ese componente la usa
también como suelo del **reloj**, no sólo del calendario. Consecuencia en un par
entrada/salida:

```vue
<!-- MAL: el suelo lleva la hora de entrada -->
<FechaHoraPicker v-model="form.fin" :min-date="form.inicio" />
```

Cambiar la hora de check-in movía la hora de check-out. No tiene sentido operativo: la hora de
salida es del establecimiento y no depende de a qué hora llegue el huésped. Lo que sí debe
cumplirse —que la salida sea posterior a la entrada— es validación del formulario, no del
picker.

```vue
<!-- BIEN: el suelo es el DÍA -->
<FechaHoraPicker v-model="form.fin" :min-date="soloDia(form.inicio)" />
```

En `ReservaEditDrawer` el helper es `soloDia()`. La regla hermana vive en `onCambiarInicio()`:
**sólo un cambio de DÍA arrastra el check-out**, medido con `diasEntre()`, que ignora la hora;
si sólo cambió la hora se sale antes incluso de la red de seguridad del rango.

## 3.b Enfocar lo que se acaba de abrir — `utils/scrollEnfoque.ts`

**Archivo:** `util/src/utils/scrollEnfoque.ts`

En un formulario largo, desplegar un acordeón o abrir un mini-formulario **no mueve el scroll**:
el bloque crece hacia abajo y quien lo abrió se queda mirando la mitad de unos campos, sin ver
la cabecera que dice de qué son. Pasaba en el acordeón de estancias del drawer de reservas y en
los formularios de cargo y pago del panel financiero, así que la aritmética vive una sola vez.

```ts
import { enfocarEnScroller } from '@/utils/scrollEnfoque';

await nextTick();                       // el llamador espera al repintado
enfocarEnScroller(el);                  // busca el scroller subiendo por los ancestros
enfocarEnScroller(el, { scroller, suave: false });
```

| Opción | Para qué |
|---|---|
| `scroller` | Pásalo si ya lo tienes; si no, se busca por los ancestros |
| `margen` | Aire sobre el bloque (12 px por defecto) |
| `suave` | `false` cuando algo más se está animando a la vez |

Cuatro decisiones que no se ven leyendo la firma:

- **No usa `scrollIntoView()`.** Ese método escala hasta el primer ancestro desplazable y, con
  un drawer abierto sobre la página, acaba moviendo también el fondo.
- **Un scroller tiene que desplazar de verdad** (`scrollHeight > clientHeight`), no basta con
  que declare `overflow-y: auto`: si no, se elige un contenedor que no mueve nada y el bloque
  sigue escondido.
- **Comprueba `isConnected`.** Un `ref` de función conserva el último nodo visto; si ese bloque
  ya se cerró, medirlo devuelve ceros y el scroll salta al principio de la lista.
- **El `await nextTick()` es del llamador.** La posición se mide con `getBoundingClientRect()`,
  y antes del repintado el bloque está plegado o ni existe.

### ⚠️ `ref` dentro de un `v-for` es un array

Si el elemento que quieres enfocar vive dentro de un `v-for` —el formulario de edición de un
cargo, por ejemplo—, **`ref="nombre"` no te da el nodo, te da un array**. El typecheck no lo
detecta (el `ref` lo declaras tú como `HTMLElement | null`) y revienta en ejecución al medirlo.
Usa un `ref` de función:

```ts
function setFormCargoRef(el: Element | ComponentPublicInstance | null): void {
    if (el instanceof HTMLElement) formCargoEl.value = el;   // el desmontaje (null) se ignora
}
```

```vue
<div v-else :ref="setFormCargoRef">…</div>
```

Ignorar el `null` del desmontaje es deliberado: al pasar de un formulario a otro, Vue puede
avisar del cierre **después** de la apertura y dejaría el ref vacío justo antes de enfocar. De
que el nodo guardado siga vivo se encarga `isConnected`.

## 3.c AsistenteBar — preguntar al PMS en lenguaje natural

`components/common/AsistenteBar.vue`. Una barra donde el operador escribe —o **dicta**— una
pregunta («¿qué casitas tengo libres del 12 al 15 de marzo?») y el backend la resuelve
consultando el PMS de verdad. Hoy la monta `HomeView`; es autónoma y se puede poner en
cualquier vista sin props.

**No es el chat del huésped.** Aquí pregunta un compañero autenticado y la respuesta sólo se
pinta en pantalla. El backend, los guardias y las herramientas están en `docs/Mensajeria.md`
§10 y §11.

### El hilo lo guarda el cliente, no el servidor

No es una caja de pregunta-respuesta: mantiene la conversación, porque el asistente
**repregunta** cuando algo es ambiguo («hay dos Carlos González, ¿cuál?»). Sin memoria, el
«el de la casita 3» siguiente llegaría sin contexto.

**El estado vive en el componente** (`hilo`) y viaja entero en cada petición dentro de
`historial`. Consecuencias que conviene tener presentes:

- El endpoint **no guarda estado**: no hay sesión de conversación que caducar ni limpiar.
- **Se pierde al recargar la página.** Es deliberado: son consultas de operación, no un
  historial que valga la pena persistir. Si algún día hace falta, se guarda en el store.
- **No hay que desconfiar de ese historial**: los permisos salen del `AgentActor` que el
  backend construye con la sesión, nunca de lo que mande el cliente. Manipular el propio
  hilo sólo confunde a quien lo manipula.

Dos detalles de la interacción que parecen menores y no lo son:

- La pregunta se pinta en el hilo **antes** de que llegue la respuesta, y el input se vacía:
  si no, el operador se queda mirando una caja con su texto sin saber si se envió.
- Si la petición **falla**, la pregunta se retira del hilo y vuelve al input. Dejarla
  mandaría al modelo, en la siguiente vuelta, un turno de usuario que nunca tuvo respuesta.

### El dictado no tiene backend

Usa la **Web Speech API** del navegador: transcribe en el cliente y al servidor viaja texto.
Ni coste de audio ni endpoint que mantener.

```ts
const w = window as unknown as {
    SpeechRecognition?: ConstructorReconocimiento;
    webkitSpeechRecognition?: ConstructorReconocimiento;
};
return w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null;
```

Dos cosas que no son opcionales:

- **El botón desaparece donde no hay soporte** (Firefox, navegadores viejos) en vez de fallar
  al pulsarlo — de ahí el `v-if="soportaDictado"`.
- **`onBeforeUnmount` corta el reconocimiento.** Si se navega fuera mientras dicta, el
  micrófono se queda abierto: el navegador mantiene el indicador de grabación encendido y el
  usuario no sabe por qué.

### Tipos: `lib.dom` no trae la Web Speech API

No hay tipos estables para `SpeechRecognition`, y ahí es fácil colar un `any`. El componente
declara **sólo la superficie que usa** (`ReconocimientoVoz`, `ResultadoVozLike`) y acota el
cast a la línea de arriba, que es un diccionario abierto del navegador y no un contrato
nuestro. `RespuestaAsistente` es espejo de `PanelAssistantController::__invoke()`.

## 3.d ConversacionVistaPrevia — asomarse a un chat sin abrirlo

`util/src/components/common/ConversacionVistaPrevia.vue`. Es el «modo stalker»: ver qué se
está hablando en una conversación **sin entrar en ella**. Nació dentro de `ChatView` y se
sacó de ahí porque el gesto no es del inbox — desde el calendario de reservas, antes de
escribirle a un huésped, hace exactamente la misma falta.

### 🕵️ La regla que le da nombre: previsualizar NO deja rastro

Llama sólo a los métodos de **lectura** del store —`fetchConversacionParaStalk`,
`fetchConversacionPorContexto` y `fetchLatestMessagesForStalk`—, nunca a `selectConversation()`. Esa última marca la
conversación como leída, baja el contador global y abre Mercure: con ella, **un simple hover
le borraba los «sin leer» al compañero que iba a contestar**. Tampoco toca el estado del
store, para que una previsualización abierta desde el calendario no altere el inbox.

Si añades un método nuevo para este componente, tiene que cumplir lo mismo.

### Se identifica de dos maneras, y la segunda es la que lo hace universal

| Prop | Cuándo |
|---|---|
| `conversacion-id` | Quien lo abre ya sabe cuál es |
| `contexto` | `{ tipo: 'pms_reserva', id }` — el asunto en **su** dominio |
| `conversacion` | El anfitrión ya la tiene cargada y se ahorra el viaje |

El `tipo` viaja **opaco** hasta el filtro de la API: el componente no sabe qué es una reserva,
así que sirve igual para cotizaciones o para lo que venga. Es la misma regla que los
identificadores de dominio del backend (CLAUDE.md, «Dominios y contratos»).

### `accion-chat`: un botón que no lleva a ninguna parte enseña a desconfiar

- `navegar` — empuja a `/chat?id=…`. Lo que quiere cualquier vista que no sea el chat.
- `delegar` — sólo emite, para que `ChatView` abra la conversación en su panel sin recargar
  la ruta.
- `ninguna` — **esconde el botón**. Es para cuando el anfitrión ya *es* el chat y esa
  conversación ya está abierta: el botón seguiría ahí prometiendo llevarte a donde ya estás.

⚠️ **`/chat/:conversationId` está declarada pero no hace nada.** La ruta existe en
`router/index.ts` con `props: true`, y aun así `ChatView` **sólo escucha `route.query.id`**: el
parámetro no lo lee nadie. Navegar a `/chat/<uuid>` abre el chat sin conversación seleccionada,
y es peor que un 404 porque parece que funciona. Los accesos del portal apuntaban ahí; hoy abren
la vista previa. Para enlazar a una conversación, **`/chat?id=…`**.

### Dos trampas que ya costaron

- **`mantener`**: en modo hover hay *dos* temporizadores de cierre —el del anfitrión y el del
  propio componente—. El cursor que viaja del ítem a la ventana dispara el primero, y sin este
  aviso la ventana se cerraba en la cara justo al llegar a ella, con el extra de que era
  imposible hacerle scroll — que es para lo que se abre.
- **El contador `peticion`**: con hover se abren y cierran muchas seguidas. Sin él, la
  respuesta lenta de la primera pintaba sus mensajes dentro de la segunda: el historial de un
  huésped bajo el nombre de otro, que en un chat es de lo peor que puede pasar. Mismo patrón
  que `onEventClick()` en `ReservasView`.

Y una de medir: el scroll al último mensaje va **fuera** del `try`, después de que `cargando`
pase a `false`. Mientras es `true` el scroller no está en el DOM, así que el `ref` vale `null`,
no falla nada, y la ventana abre en el mensaje más viejo.

---

## 3.e InfoTooltip — la ayuda detrás de una «i», y el hover que no existe en táctil

`util/src/components/common/InfoTooltip.vue`.

### La regla: la ayuda se lee UNA vez, y luego estorba

El panel financiero llegó a tener **cinco franjas de texto explicativo** apiladas: cómo se
convierte cada moneda, qué protege el candado de los cargos, qué protege el de los pagos, en qué
moneda es la contabilidad, qué pasa al editar el depósito. Cada una tenía su motivo. Juntas se
comían la primera pantalla de un panel cuyo trabajo es enseñar importes.

El criterio que las ordenó:

| Va **a la vista** | Va **detrás de la «i»** |
|---|---|
| Un problema **de esta reserva** que hay que ir a arreglar («2 registros no suman: les falta el tipo de cambio») | Cómo funciona el sistema en general |
| Una consecuencia **de lo que estás a punto de hacer** («al guardar, el depósito deja de cuadrarse») | Por qué existe un candado |
| Un estado que cambia | Un texto que es idéntico en todas las reservas |

Regla corta: **si el texto es el mismo en todas las reservas, no gana su sitio en pantalla.**

### 👆 El gotcha que le da la mitad del código: en táctil no hay hover

Un tooltip hecho con `:hover` (o `group-hover` de Tailwind) **parece** funcionar en el móvil, y
es un espejismo: el navegador táctil **emula** el hover en el primer toque. Los síntomas son de
los que se investigan dos veces antes de sospechar del CSS:

- **Todo pide dos toques.** El primero «hoverea», el segundo dispara el clic. Cualquier botón
  bajo un elemento con `:hover` deja de responder al primer toque.
- **El hover se queda pegado.** No hay «salir con el ratón», así que el tooltip persiste tapando
  lo que hay debajo hasta que tocas en otro sitio.
- **El toque largo abre el menú contextual** del sistema encima del tooltip.
- Y hay **300 ms de retardo** heredados del doble toque para hacer zoom.

Por eso el estado de este componente es **explícito** y no una regla de CSS:

```
pointer: fine   (ratón/trackpad)  → abre en mouseenter, cierra en mouseleave
táctil                            → abre y cierra al TOCAR (un toque), y cierra al
                                    tocar fuera o con Escape
```

Tres detalles que no son opcionales:

1. **`matchMedia('(pointer: fine)')`, no «¿es móvil?».** Un portátil con pantalla táctil tiene
   las dos cosas; lo que decide el comportamiento correcto es si existe un puntero capaz de
   pasar por encima sin tocar.
2. **`preventDefault()` en el toque.** Sin él, el navegador dispara después el hover y el clic
   emulados, y el mismo gesto abre y cierra la burbuja.
3. **`touch-action: manipulation` + `-webkit-touch-callout: none`** en el disparador: quitan el
   retardo de 300 ms y el menú contextual del toque largo. Son la diferencia entre un tooltip y
   uno que «a veces no sale».

### Uso

```vue
<InfoTooltip lado="izquierda">
    Protege los cargos <b class="text-white">sincronizados desde el canal</b>.
    <span class="block mt-1.5 text-slate-400">Los manuales se editan siempre.</span>
</InfoTooltip>
```

- `lado` — `derecha` (por defecto) ancla la burbuja por su borde derecho: es lo que hay que usar
  cuando la «i» está cerca del borde del panel, porque centrada se saldría de la pantalla.
  `izquierda` cuando la «i» está al principio de una línea.
- `texto` para un texto plano; el slot por defecto para algo con formato.
- `claseIcono` para el color (los candados usan ámbar, el resto gris).

El disparador es un `<button type="button">`: se llega con el tabulador, lo anuncia el lector de
pantalla, y el `type` evita que envíe el formulario en el que vive.

### Dónde se gana de verdad: dentro de un modal

El Constructor de Storytelling (`CotizacionEditorView.vue`) lo usa en el pie de cada párrafo para
avisar de que la papelera se lleva también sus componentes. Ahí el `Teleport` a `body` no es un
detalle de implementación sino **el motivo de usarlo**: el modal tiene varios `overflow-hidden` y
`overflow-y-auto` anidados, y una burbuja `absolute` se recorta contra ellos. Regla práctica: si
la «i» vive dentro de un panel con scroll propio, `InfoTooltip` y no un tooltip a mano.

⚠️ **El disparador es siempre la «i» y no se puede sustituir.** Para un badge que además sea el
disparador —«3 componentes» y al tocarlo el detalle— hoy hay que poner el badge al lado. Si eso
se repite, el arreglo es un slot `#disparador` en el componente, no una burbuja nueva a mano.

---

## 3.f `useVolverAtras` — «atrás» vuelve a donde estabas (2026-08-17)

`util/src/composables/useVolverAtras.ts`. Directiva para **toda** la app: un botón de volver
regresa a la pantalla ANTERIOR del usuario, no a una ruta fija que eligió el programador.

El fallo que corrige: los `handleVolver` navegaban a `/cotizacion` a secas, así que llegar al
editor desde La Biblia y pulsar atrás te dejaba en el dashboard de cotizaciones —un sitio en el
que nunca estuviste—.

```ts
const volver = useVolverAtras();
const handleVolver = () => volver('/cotizacion');   // el fallback del deep link
```

Si hay historial de SPA (`history.state.back != null`) hace `router.back()`; si no —entraste
por un enlace directo— cae al destino fijo, que ahí es lo correcto porque no hay «atrás»
posible. Se usa `history.state.back` y no `window.history.length`, que cuenta también páginas
de fuera del SPA.

**Al añadir un botón de volver, usa esto, no un `router.push` a ruta fija.** Excepción: una
navegación FORZADA —tras eliminar el recurso, tras un error de carga— sí va a ruta fija, porque
volver a donde estabas no tiene sentido si eso ya no existe.

⚠️ Los **modales** son otro caso: no navegan con el router, así que empujan su propia entrada
de history con `pushState` y cierran en `popstate`. Ver docs/Operacion.md §3.17 — mismo
objetivo (el «atrás» hace lo esperado), mecánica distinta.

## 3.g `GestoDeRecarga` — tirar para recargar, que sólo funcionaba en el Home (2026-08-21)

### Por qué había que escribirlo

El gesto nativo **sólo existía en el Home**, y no por casualidad: es la única vista cuya raíz
scrollea (`h-screen overflow-y-auto`), así que el navegador la promociona a *root scroller* y le
da el tirón-para-recargar gratis.

Las otras **nueve** son `h-screen … overflow-hidden` con scrollers internos —la forma normal de
una app con cabecera fija— y ahí no hay root scroller que promocionar: el gesto sencillamente no
existe. No estaba desactivado; nunca llegó a haberlo.

### Vive en el shell, no en las vistas

Se monta una vez en `App.vue` y se engancha a `document`, buscando el scroller **bajo el dedo**.
La alternativa era cablearlo al scroller «principal» de cada vista, y ahí `CotizacionEditorView`
tiene **doce**: elegir a mano doce veces es olvidarse en la trece.

```
touchstart  →  sube por los padres hasta el primer scroller vertical
               ¿está arriba del todo (o no hay scroller)? → armado
touchmove   →  decide la dirección UNA vez y no la revisa
               vertical y hacia abajo → crece el indicador, con roce 2.4
touchend    →  ¿pasó de 88 px? → recarga. Si no, vuelve solo.
```

### Recarga la página; NO refresca datos

A propósito, y es la decisión que más se podría discutir. Un `refetch` por vista sería más
elegante y más peligroso: si a alguna se le olvida una fuente, **el gesto miente** —dice que
refrescó y algo se quedó viejo— y eso no se descubre hasta que alguien decide sobre un dato
caducado. Recargar no puede mentir, y de paso trae versión nueva si la hay.

El día que se prefiera lo otro, el único punto que cambia es la llamada de `alSoltar()`.

### Las tres trampas

⚠️ **La dirección se decide una vez y no se revisa.** Hay diez scrollers horizontales en el
front —el calendario, las tablas anchas—; sin esto, un arrastre lateral empezaba a armar el gesto
en cuanto temblaba el pulgar.

⚠️ **`touchmove` no puede ser pasivo**, porque necesita `preventDefault()` para que el scroller
no se mueva mientras se tira. Y sólo se llama cuando ya se está tirando de verdad (> 4 px), para
no robarle el primer píxel de scroll a quien sólo quería subir la lista. Los tres listeners van
en **captura y sin detener la propagación**, así que los `@touchstart` de las vistas —el pulsado
largo del chat— siguen funcionando.

⚠️ **`VETADO` es un `Symbol`, no `null`.** `scrollerDe()` devuelve `null` para «no hay scroller»,
que es razón para **armar** —una pantalla corta también se tira—; si el veto devolviera `null`
activaría justo lo que venía a impedir.

### `data-sin-recarga`: dónde NO se tira

El gesto es una recarga de página, y a media edición se lleva por delante lo que no esté
guardado — justo arriba del formulario, que es donde se arma, «arrastrar hacia abajo» es el
movimiento más natural del mundo. Llevan el atributo `ReservaEditDrawer` y
`CotizacionEditorView`. Se hereda: basta ponerlo en la raíz del overlay.

### ⚠️ El Home apaga el nativo

`HomeView` lleva `overscroll-y-contain`. Sin eso tendría **dos** gestos a la vez —el del
navegador y éste—, y el tirón significaría una cosa allí y otra en el resto de la app.

### Verificación

⚠️ **Sólo se puede comprobar en un móvil de verdad.** Chrome de escritorio no despacha eventos
táctiles sin el modo dispositivo de las DevTools, así que ni ESLint ni `vue-tsc` —que sí pasan—
dicen nada sobre si el gesto se siente bien. El umbral (88 px con roce 2.4, o sea ~211 px de
dedo) es el número a ajustar si resulta duro o si salta solo.

## 3.h `ContactoDeIdentidad` — el contacto se enseña, no se edita (2026-08-21)

Teléfono y correo de un asunto, resueltos desde la **identidad** de la persona, con el aviso de
«sin verificar» cuando lo que se ve es todavía la semilla. Un botón lleva al editor de
identificadores; si el asunto no tiene hilo, lo abre antes.

Props: `contextType` (`pms_reserva`, `cotizacion_file`, `travel_organizacion`… opaco, aquí no se
interpreta), `contextId`, y `conCorreo` para ocultar el correo donde el dominio no lo use.

### ⚠️ Bloquea con la IDENTIDAD, no con la pantalla

`v-model:telefono` y `v-model:correo` son la **semilla**. Mientras un dato no tenga identidad, el
componente lo pinta como `<input>` y lo emite al padre, que lo guarda con su propio «Guardar».

La primera versión bloqueaba en cuanto la pantalla era de edición, y dejaba atrapados a los
asuntos antiguos: sin dato de contacto no hay hilo, y sin hilo no hay editor de identificadores
—abrirlo exige justamente un dato de contacto—. Ni aquí ni allí: sólo quedaba EasyAdmin.

Sin los `v-model`, el componente es de sólo lectura (así se usa en la ficha del proveedor, que es
para mirar).

### Por qué el `<input>` tenía que desaparecer, no deshabilitarse

El campo del asunto es la semilla con la que nació la identidad; el envío lee la identidad. Con
el `<input>` puesto, el operador cambiaba el número, **veía su cambio guardado**, y los mensajes
seguían saliendo al viejo. Un dato que se puede editar y no sirve es peor que uno que no se puede
editar.

Deshabilitarlo tampoco valía: seguiría enseñando el valor del asunto, que es el desfasado.

### La excepción que confirma la regla

En el **alta** de un proveedor los campos sí se teclean: ahí son la semilla con la que va a nacer
la identidad, y sin ellos no hay a quién escribir. Lo parte el prop `organizacionId` de
`OrganizacionFormulario` — sin id se teclea, con id se enseña.

### La regla vive en PHP

`ContactoDelAsunto::para()`, una sola para los tres dominios (ver `docs/Mensajeria.md` §24). Aquí
**no se recalcula nada**: el componente pinta lo que llega, incluido el `origen`, porque
compararlo en el navegador daría «semilla» cuando sí hay identidad —lo normal es que ambos
valores coincidan—.

## 3.i Un modal en el móvil: `dvh`, no `vh` (2026-08-24)

Reportado desde un móvil: al tocar un campo del modal «Aperturar Expediente», el teclado tapaba
la mitad de arriba y **«Nombre del Grupo» quedaba inalcanzable**. No había forma de subir.

La causa no es que faltara scroll, es la unidad:

> **`100vh` ignora el teclado.** Es el alto de la ventana *large*, el que tendría con las barras
> del navegador retraídas, y **no cambia cuando se abre el teclado**. El modal seguía midiendo la
> pantalla entera, así que su mitad superior quedaba debajo del teclado y no había nada que
> recorrer: el contenido no desbordaba, sólo estaba tapado.

`100dvh` —*dynamic viewport height*— sí encoge. Con el tope puesto ahí, el modal cabe en lo que
queda visible y el cuerpo se puede recorrer.

El patrón, en los tres modales del flujo de expedientes (`DashboardView`, y el de pasajero y el de
documento en `FileDetalle`):

```html
<div class="fixed inset-0 flex items-center justify-center p-4 …">
  <div class="… flex flex-col max-h-[calc(100dvh-2rem)]">   <!-- el tope, y columna -->
    <div class="… shrink-0">cabecera</div>                  <!-- no se encoge -->
    <form class="p-6 overflow-y-auto">…</form>              <!-- lo que se recorre -->
```

Las tres piezas son necesarias: sin `flex flex-col` el `overflow-y-auto` no tiene de qué colgar,
sin `shrink-0` la cabecera se aplasta antes que el cuerpo, y sin el tope en `dvh` no hay desbordo
que recorrer.

⚠️ **`overflow-hidden` en la tarjeta no estorba** —es lo que recorta las esquinas redondeadas— y
convive con el `overflow-y-auto` del hijo. Y donde estaba puesto `overflow-visible` a propósito
(el modal de pasajero) **se queda**: el desplegable de `SearchableSelect` se teletransporta a
`body` con `fixed`, así que no lo recorta nadie.

⚠️ El patrón **no está aplicado en toda la app**. Los drawers de Tarifas y Reservas, el modal de
plan de operación y los de chat siguen sin tope. Se van arreglando al tocarlos.

## 3.j Girar la ficha para editarla, en vez de abrirla editando (2026-08-24)

Los paneles de datos —el del expediente y el del pasajero— abrían **en formulario**. Con 131
fichas que se recorren con las flechas, eso es lo contrario de lo que se hace: en un formulario
los datos están repartidos entre campos que hay que interpretar, y leerlos de corrido se hace de
un vistazo. **Ahora abren leyendo, y editar tiene su botón.**

### El giro es de MEDIA vuelta, no de dos caras

Una tarjeta con anverso y reverso exige que las dos caras **midan lo mismo** —van superpuestas en
absoluto— y aquí no se parecen: seis líneas en lectura contra seis campos, un buscador de países y
el panel de contacto en edición. Fijar una altura para las dos deja la de lectura con un hueco
enorme o la de edición cortada.

Se gira **90°**, se cambia el contenido con la tarjeta **de canto**, y se vuelve:

```
const girar = (aVista: boolean) => {
    girando.value = true;                                    // rotateY(90deg), 180 ms
    setTimeout(() => { modoVista.value = aVista;             // ← de canto: nadie ve el cambio
                       girando.value = false; }, 180);       // vuelve a 0
};
```

Se ve igual que un volteo y no pelea con la altura. El CSS vive en el `<style scoped>` de
`FileDetalle.vue`: `.panel-giratorio` pone la `perspective`, `.cara` la transición, `.de-canto` el
`rotateY(90deg)`.

⚠️ **Respeta `prefers-reduced-motion`**: quien pide menos movimiento no quiere una tarjeta
girando, así que ahí el contenido se cambia y ya.

⚠️ **Cancelar DESCARTA.** Volver a lectura enseñando lo que se tecleó y no se guardó sería mentir:
si hay cambios, se pregunta y se recarga.

## 3.k `useCapasEnHistorial` — «atrás» cierra la capa, no la pantalla (2026-08-24)

En un móvil, «atrás» es el gesto con el que se cierra cualquier cosa: el botón del sistema y el
deslizar desde el borde. Con un modal abierto **no cerraba el modal, navegaba**: estabas revisando
la ficha de un pasajero, deslizabas para volver a la lista, y aparecías en el Home — con el
trabajo a medias y sin forma de volver a donde estabas.

`util/src/composables/useCapasEnHistorial.ts` lo resuelve para cualquier capa: un modal, una ficha,
un modo de edición.

### ⚠️ Las capas viven en la QUERY, no en `history.pushState`

La primera versión empujaba entradas con `history.pushState` directamente. Funcionaba a medias y
fallaba de una forma desconcertante: **cerrar el modal te sacaba al listado de expedientes**.

vue-router lleva su propia contabilidad dentro de `history.state` —`back`, `current`, `forward`,
`position`— y escucha `popstate` para navegar. Una entrada empujada por fuera copia ese estado sin
actualizar `position`, así que al retroceder el router cree que ha vuelto atrás en SU historia y
navega a la ruta anterior. No se arregla sin reimplementar sus interioridades.

Ahora las capas van en la query (`?capa=pax.pax-edicion`): el router las empuja y las quita como
cualquier navegación, no hay nada que desincronizar, y de regalo la URL dice qué hay abierto —
recargar o compartir el enlace deja de mentir.

⚠️ `cerrar()` usa `router.go(-n)`, **nunca un `push` con la query recortada**: empujando quedaría
una entrada más y el «atrás» siguiente volvería a ABRIR el modal.

⚠️ **La pila es un `ref` propio, no se deriva de la query.** `router.push()` es asíncrono, así que
componer la segunda capa leyendo la ruta la calcula con la ruta todavía sin actualizar:

```
abrir('pax')          push  ?capa=pax          ← aún no ha llegado
abrir('pax-edicion')  lee la ruta: vacía   →   push ?capa=pax-edicion   ← «pax» PERDIDA
cerrar('pax')         no la encuentra      →   retrocede de menos       ← sales al listado
```

Con la pila propia, `abrir()` es síncrono y la query se ESCRIBE desde ella. La ruta se sigue
leyendo, pero sólo para detectar que el usuario ha retrocedido: si trae menos capas de las que
creemos abiertas, se cierran las que faltan.

```ts
const capas = useCapasEnHistorial();

abrir:  capas.abrir('pax', () => { showPaxModal.value = false; });
cerrar: capas.cerrar('pax');     // ← también desde la ✕ y desde «Cancelar»
```

⚠️ **El cierre programático NO cierra: llama a `history.back()`.** La ✕, «Cancelar» y el guardado
pasan todos por `capas.cerrar()`, así que el cierre real ocurre siempre en el mismo sitio —el
`popstate`— venga del gesto o del botón.

Esa asimetría es deliberada, y es el error que se comete al hacer esto a mano: cerrando el modal
*y* dejando la entrada en el historial, el siguiente «atrás» consume una entrada fantasma y **no
hace nada visible**. Hay que pulsar dos veces para salir de la pantalla, y nadie relaciona eso con
el modal que cerró hace un minuto.

**Las capas se anidan.** En la ficha del pasajero, leer es una capa y editar es otra encima:
«atrás» dentro de la edición vuelve a la lectura, y el siguiente sale de la ficha — el camino por
el que se entró, al revés. `cerrar(nombre)` retrocede tantas entradas como capas haya que quitar,
así que cerrar la de abajo se lleva las de arriba.

⚠️ **La animación se separa del historial.** El giro de §3.j tiene su función privada (`girarPax`)
que sólo anima; la pública decide además qué hacer con la pila. Juntándolas, el callback de cierre
volvía a llamar al que cierra y se comía la animación o entraba en bucle.

Aplicado en `FileDetalle.vue` a la ficha del pasajero, su modo edición, el modal de documento y el
panel del expediente, y en `OperacionView.vue` (25/08/2026) a la ficha del servicio, su formulario
y los seis modales del Centro de Operaciones. ⚠️ **No está en el resto de la app**: los drawers de
Tarifas y Reservas y los modales de chat siguen sacándote de la pantalla. Se van enganchando al
tocarlos.

⚠️ **Y no puede convivir con el `pushState` a mano.** El Centro de Operaciones tenía su propio
mecanismo —`hayModalAbierto` + `history.pushState` + un `popstate`— que es exactamente el que este
composable existe para reemplazar. Dos contabilidades sobre el mismo historial no se reparten el
trabajo: se pisan. Al enganchar la ficha había que migrar **los seis modales a la vez**, no sólo lo
nuevo.

## 3.l La versal va donde NO se edita (2026-08-24)

`uppercase` es sólo CSS: el valor se guarda tal cual se tecleó. Eso suena inofensivo y es
justamente el problema **cuando el campo es editable**: escribiendo el título de un párrafo no
había forma de ver si quedaba «La Olla de Juanita» o «la olla de juanita». La pantalla lo enseñaba
mayúsculo, la base guardaba lo tecleado, y **nadie puede corregir lo que no ve** — el error sólo
aparece en el documento del cliente.

La regla:

| | |
|---|---|
| **Rótulos, cabeceras, badges** | `uppercase` — es decoración sobre texto que nadie edita |
| **Un campo que se escribe** | sin `uppercase`, salvo que el valor **se normalice** al guardar |

La excepción está en `FileDetalle`: la clave de un subgrupo sí lleva `uppercase`, porque
`CotizacionFileGrupo::$clave` la sube al guardar —de ella depende la unicidad—. Ahí el campo
enseña exactamente lo que se va a guardar, que es lo contrario del caso anterior.

Barrido hecho el 24/08/2026 sobre `util/` y `pax/`: los cinco campos editables que quedan con
versal son todos **códigos que se normalizan** (clave de subgrupo, localizadores de búsqueda).

## 3.m `AsistenteFlotante` — el asistente en toda la app (2026-08-24)

`AsistenteBar` vivía embebido en el Home, así que preguntarle algo mientras editabas una
cotización o revisabas el padrón exigía **salir de donde estabas y volver**. Y lo que se le
pregunta —«¿quién está en el grupo 6?», «¿qué casitas tengo libres?»— es justo lo que hace falta
sin soltar la pantalla.

`AsistenteFlotante` lo envuelve y se monta en `App.vue`, junto a `GestoDeRecarga`: una lengüeta
anclada al borde superior que al tocarla baja el panel.

⚠️ **Anclada ARRIBA y no abajo.** Abajo compiten el teclado del móvil, la barra de gestos del
sistema y el aviso de notificaciones que ya vive ahí. Abriendo hacia abajo desde el borde
superior, el teclado se queda donde está.

⚠️ **Se engancha a `useCapasEnHistorial`** (§3.k): «atrás» lo colapsa en vez de sacarte de la
pantalla.

⚠️ **Sólo se pinta con `ROLE_RESERVAS_SHOW`.** El asistente consulta el PMS con las skills del
usuario; a quien no las tiene le contestaría «no puedo» a todo, y una lengüeta que sólo sabe
negarse es peor que ninguna.

### El refresco lo declara cada vista, no el armazón

Embebido en el Home, la vista sabía qué refrescar. Desde el armazón no — pero eso **no obliga a
recargar la página**: la vista lo dice, y sólo mientras está montada.

```ts
useRefrescoDelAsistente(() => cargarPanelHoy());
```

⚠️ **La pieza que lo hace funcionar es `hayQuienEscuche()`.** Si la pantalla actual se refresca
sola, el asistente no enseña nada; si no hay nadie registrado, ofrece recargar la página, que es
lo único que puede prometer ahí. Sin esa comprobación habría que elegir entre molestar siempre o
dejar datos viejos en las vistas no registradas — y las dos son peores.

⚠️ **El recargón es un botón, nunca un efecto.** Desde el armazón no se sabe si la pantalla tiene
un formulario a medias, y perderlo por un refresco que nadie pidió es peor que el dato viejo.

### Quién está registrado, y por qué ésos

Se sacó de las skills que **escriben** (`NivelRiesgo::Escritura`), no de las pantallas que
existen: una vista que el asistente nunca toca no necesita registrarse.

| Vista | Qué recarga | Skills que la ensucian |
|---|---|---|
| **Home** | el panel de llegadas y salidas | `confirmar_estancia`, `modificar_reserva`, `aplicar_cambio_horario` |
| **Reservas** | el calendario | `crear_reserva`, `crear_estancia`, `modificar_reserva`, `confirmar_estancia` |
| **Tarifas** | el calendario de precios | `ajustar_tarifas` |
| **Finanzas** | movimientos y saldo | `registrar_pago`, `registrar_cargo`, `generar_enlace_prepago` |
| **Operación** | la pestaña que se esté mirando | `aplicar_cambio_horario` |
| **Ficha del expediente** | el padrón | las de cotización |

⚠️ **El chat NO se registra, y no es un olvido.** Va por Mercure: un mensaje que manda
`enviar_mensaje_huesped` entra por el mismo túnel que cualquier otro y aparece solo. Registrarlo
añadiría una recarga redundante sobre datos que ya llegaron.

⚠️ **Operación recarga sólo la pestaña activa.** Pedir las órdenes mientras se mira La Biblia es
tráfico que nadie ve.

Las vistas que no están —Cotizaciones, Catálogo, Organizaciones— caen al botón de recargar.
Añadir una es una línea, y el criterio es el de arriba: ¿hay alguna skill de escritura que toque
lo que esa pantalla enseña?

## 4. Dónde tocar para cambiar X

| Necesidad | Archivo | Método/Campo |
|---|---|---|
| **Que una pantalla deje de editar teléfono/correo** | la vista | `ContactoDeIdentidad.vue` con `contextType` + `contextId` (§3.h) |
| **Cambiar cuánto hay que tirar para que recargue** | `GestoDeRecarga.vue` | `UMBRAL` (88 px) y `ROCE` (2.4) — sólo se juzga en un móvil real (§3.g) |
| Que el gesto refresque datos en vez de recargar la página | `GestoDeRecarga.vue` | `alSoltar()` — es el único punto que cambia (§3.g) |
| Impedir el gesto en una pantalla concreta | la raíz de ese overlay | atributo `data-sin-recarga`, que se hereda (§3.g) |
| Que el asistente recuerde más (o menos) turnos | `PanelAssistant` | `MAX_TURNOS` — el hilo se recorta en el backend, no en el componente (§3.c) |
| Persistir la conversación del asistente entre recargas | `AsistenteBar.vue` | `hilo` — hoy es estado local a propósito (§3.c) |
|---|---|---|
| Cambiar el formato visible de la fecha | `FechaHoraPicker.vue` | `aTextoVisible()` / `format` del `VueDatePicker` |
| Cambiar el rango de años admitido al teclear | `FechaHoraPicker.vue` | bloque `Y` de la máscara (hoy 2024-2099) |
| Cambiar el ancho al que saltan de línea (§1.4) | Vista que los coloca | `minmax(11rem, 1fr)` de la rejilla |
| Permitir segundos | `FechaHoraPicker.vue` | `PATRON_ISO`, `aTextoVisible()`, `onPickerChange()` |
| Que la hora de un campo deje de arrastrar la del otro (§1.6) | Vista que los coloca | `:min-date` — pásale sólo el día |
| Cambiar el aire o la animación al enfocar un bloque (§3.b) | `utils/scrollEnfoque.ts` | `enfocarEnScroller()` — opciones `margen` / `suave` |
| Marcar una vista propia en el historial (gesto «atrás») | `ChatView.vue` | `marcarVistaEnHistorial()` — conserva `history.state`, §2 |
| Abrir la vista previa de un chat desde una vista nueva (§3.d) | `ConversacionVistaPrevia.vue` | `contexto` — `{ tipo, id }`, sin conocer la conversación |
| Que el botón «Ir al chat» navegue, delegue o desaparezca (§3.d) | Vista que lo monta | `accion-chat` |
| Añadir un módulo al portal y al selector | `util/src/types/modulosApp.ts` | `MODULOS_APP` — sale en HomeView y en AppSwitcher a la vez |
| Cambiar el aspecto del selector en una cabecera clara | Vista que lo monta | `<AppSwitcher variante="clara" />` |
| Usar el logotipo del portal como selector | Vista que lo monta | `<AppSwitcher variante="marca" sin-inicio />` — `sinInicio` para no ofrecer «Inicio» estando en él |
| Añadir una ayuda a un panel sin gastarle una franja (§3.e) | `InfoTooltip.vue` | `<InfoTooltip lado="…">` — el slot admite formato |
| Que un tooltip no se salga por el borde de un panel (§3.e) | Vista que lo monta | `lado="derecha"` (ancla por el borde derecho) |
| Cambiar cómo se comporta el tooltip en táctil (§3.e) | `InfoTooltip.vue` | `alTocar()` + `conPuntero` — ⚠️ NO se vuelve a `:hover`, lee el gotcha |
| Decidir si un campo lleva `uppercase` (§3.l) | La vista que lo monta | Rótulo → sí. Campo editable → sólo si el valor se normaliza al guardar |
| Que el asistente aparezca (o no) en una vista (§3.m) | `AsistenteFlotante.vue` en `App.vue` | `visible` — hoy es el rol; es el único sitio que decide |
| Que «atrás» cierre un modal en vez de salir de la pantalla (§3.k) | La vista que lo monta | `useCapasEnHistorial()` — `abrir(nombre, alCerrar)` / `cerrar(nombre)`. ⚠️ TODOS los cierres pasan por `cerrar()`, incluida la ✕ |
| Que una ficha abra leyendo y se gire para editar (§3.j) | La vista que la monta | `modoVista` + `girar()`, y las clases `.panel-giratorio` / `.cara` / `.de-canto`. ⚠️ Media vuelta, NO dos caras |
| Que un modal no quede tapado por el teclado del móvil (§3.i) | La tarjeta del modal | `flex flex-col max-h-[calc(100dvh-2rem)]` + `shrink-0` en la cabecera + `overflow-y-auto` en el cuerpo. ⚠️ `dvh`, nunca `vh` |

## 🐛 El calendario se escondía bajo la cabecera (29/08/2026)

`VueDatePicker` renderiza su menú **dentro del DOM del componente**, así que lo recorta cualquier
padre con `overflow` y lo tapa cualquier cosa con más `z-index`. En el editor de cotizaciones, con
su cabecera pegajosa, el calendario abría hacia arriba y **la mitad de arriba quedaba debajo del
header**: sólo se veían las últimas semanas del mes.

Dos props lo arreglan, y hacen falta las dos:

```vue
<VueDatePicker teleport="body" :teleport-center="esEstrecha" …>
```

- **`teleport="body"`** saca el menú del contexto de apilamiento del componente. Con eso deja de
  recortarse y se coloca respecto al campo, volteando solo si no cabe.
- **`:teleport-center`** en pantallas estrechas lo centra como un modal. Hace falta porque en un
  móvil con el teclado abierto **no hay hueco arriba ni abajo**, y voltear no resuelve nada.

⚠️ Estaba sin poner en **los tres** sitios que usan el componente. Se veía sólo en el editor porque
es el único con cabecera pegajosa — los otros dos tenían el mismo fallo esperando su turno.

### ⚠️ Un `hr` con `flex-1` empuja lo que venga después fuera de la pantalla

La cabecera de día del editor llevaba `Día N · fecha · <hr flex-1> · botones` en una fila `flex`
sin `flex-wrap`. En un móvil los botones quedaban **cortados fuera del ancho**: la línea
decorativa reclamaba todo el espacio sobrante y la fila no tenía por dónde encogerse.

Las tres piezas del arreglo, y ninguna sobra:

- **`flex-wrap`** en el contenedor, para que los botones bajen a su renglón cuando no caben.
- **El `hr` sólo desde `md`.** Es puro relleno; en estrecho no aporta nada y es justo lo que
  ocupaba el sitio.
- **Los botones en su propio `div` con `ml-auto`**, para que sigan yendo a la derecha caiga su
  renglón donde caiga. Sueltos, al envolver se pegarían a la izquierda.

**La regla:** un separador elástico y un `flex` que no envuelve son incompatibles con una pantalla
estrecha. Si la fila lleva acciones, el relleno se esconde antes que ellas.

### `usePantallaEstrecha()`

`util/src/composables/usePantallaEstrecha.ts`. Existe para lo que hay que pasar como **prop**, que
es donde CSS no llega; lo que se pueda resolver con una clase de Tailwind se resuelve con la clase.

⚠️ El umbral es **640 px, el `sm` de Tailwind**, a propósito: con otro número el comportamiento
salta a un ancho distinto del que salta el diseño, y nadie entiende por qué.
