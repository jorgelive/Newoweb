# Guía del Huésped y Catálogo Público de Unidades

Cómo el mismo CMS de guía alimenta dos audiencias con contratos distintos —el huésped con
reserva y el visitante anónimo—, quién decide qué ve cada uno, y por qué esa decisión se tomó
del lado del servidor.

Alcance: `src/Pms/Guia/`, `src/Pms/Enum/PmsGuiaVisibilidad.php`, `src/Api/Provider/Pms/`,
las entidades `PmsGuia*` / `PmsUnidad` / `PmsEstablecimiento`, `src/Api/Controller/Pax/`
(heredado), y en `pax/`: `views/huesped/`, `stores/huesped/`, `core/RichContentEngine.ts`.

---

## Índice

1. [Vocabulario](#1-vocabulario)
2. [Las dos audiencias y el enum que las separa](#2-las-dos-audiencias-y-el-enum-que-las-separa)
3. [La matriz de acceso](#3-la-matriz-de-acceso)
4. [Flujo de una petición](#4-flujo-de-una-petición)
5. [Rutas, slugs y endpoints](#5-rutas-slugs-y-endpoints)
6. [Qué se arregló y qué sigue roto](#6-qué-se-arregló-y-qué-sigue-roto)
7. [Gotchas](#7-gotchas)
8. [Dónde tocar para cambiar X](#8-dónde-tocar-para-cambiar-x)

---

## 1. Vocabulario

| Término | Significado |
|---|---|
| **Guía** | `PmsGuia`. Una por unidad (`OneToOne`). Es el CMS: título + secciones ordenadas. |
| **Sección** | `PmsGuiaSeccion`. Agrupa ítems. Reutilizable entre guías vía `PmsGuiaHasSeccion` (`esComun`). |
| **Ítem** | `PmsGuiaItem`. La unidad de contenido: título, cuerpo HTML i18n, galería, botón. |
| **Visibilidad** | `PmsGuiaVisibilidad`. Vive en el **ítem**. Público / privado / a la llegada. |
| **Acceso** | `PmsGuiaAcceso`. Situación de quien pide la guía. Se calcula por petición, no se guarda. |
| **Ventana** | Desde 24 h antes del check-in hasta el final del día del check-out. |
| **Placeholder** | `{{ door_code }}` dentro del cuerpo de un ítem. Lo resuelve el backend. |

---

## 2. Las dos audiencias y el enum que las separa

Hasta ahora `PmsGuia::getSeccionesApi()` devolvía **todas** las secciones activas con **todos**
sus ítems, y ese mismo árbol servía a los dos públicos. Las credenciales nunca viajaron
—`codigoPuerta`, `codigoCaja` y `wifiNetworks` no tienen `#[Groups]`— pero sí la estructura
completa: normas de la casa, avisos internos y el texto que rodea a la caja fuerte, accesibles
para cualquiera con el UUID de una unidad.

`PmsGuiaVisibilidad` es el corte:

| Caso | Para qué | Ejemplos |
|---|---|---|
| `Publico` | Escaparate, sin reserva | Fotos, descripción, servicios, cómo llegar |
| `Privado` | Huésped con estancia | Normas, instrucciones de la lavadora |
| `Llegada` | Solo dentro de la ventana | Código de puerta, caja fuerte, WiFi |

**El enum vive en el ítem y NO en la sección.** `PmsGuiaSeccion` deriva su visibilidad de los
ítems que le quedan tras filtrar (`PmsGuiaArbolFiltro::podar()`): si se queda sin ninguno,
desaparece. Con el flag en los dos sitios habría dos campos capaces de contradecirse y secciones
vacías en el catálogo.

> El default es `Privado`, y la migración `Version20260803230000` deja **todo** el contenido
> existente ahí. El catálogo público arranca vacío a propósito: publicar es una decisión
> editorial que se toma ítem a ítem. La única excepción del backfill son los ítems cuyo cuerpo
> usa un placeholder sensible, que pasan a `Llegada` porque es exactamente lo que el sistema
> anterior ya enmascaraba fuera de ventana.

---

## 3. La matriz de acceso

`PmsGuiaAcceso::paraEvento()` clasifica la estancia; `permite()` cruza esa clasificación con la
visibilidad del ítem. **Es el único sitio donde se decide qué se ve.**

| Estado \ visibilidad | `Publico` | `Privado` | `Llegada` |
|---|---|---|---|
| `Publico` (sin reserva) | ✓ | ✗ | ✗ |
| `NoConfirmada` (cancelada, sin pago, o `guiaDisabled`) | ✓ | ✓ | ✗ |
| `Pendiente` (faltan >24 h) | ✓ | ✓ | ✗ |
| `Activa` (ventana abierta) | ✓ | ✓ | ✓ |
| `Expirada` (post check-out) | ✓ | ✓ | ✗ |

`NoConfirmada` conserva lo `Privado` a propósito: cómo llegar y las normas no son secretos, y
quien tiene el localizador lo sacó de su correo de confirmación. **Lo que el pago protege son
los códigos, y esos son `Llegada`.**

### Ítem bloqueado: ¿se oculta o se anuncia?

`PmsGuiaAcceso::debeAnunciarBloqueo()`:

- **`Pendiente`** → el ítem **se muestra**, con `bloqueadoHasta` y el cuerpo interpolado con
  `[Disponible el 12/08 a las 15:00]`. El huésped ve que el dato existe y cuándo lo tendrá.
- **`Expirada` / `NoConfirmada`** → el ítem **desaparece** del árbol. No hay nada que prometer,
  y un candado permanente sin explicación solo genera tickets de soporte.

En los dos casos el valor real **nunca sale del servidor**.

### Tres comprobaciones, no una

`calcularAcceso()` (la versión anterior, en `GuiaHelperResponseTrait`) solo miraba el estado de
pago. `PmsGuiaAcceso::paraEvento()` comprueba, en orden:

1. `estado ∈ PmsEventoEstado::MOSTRAR_EVENTO_GUIA` — ni cancelada ni bloqueo.
2. `!isGuiaDisabled()` — el operador puede excluirla a mano.
3. `estadoPago ∈ PmsEventoEstadoPago::ESTADOS_PAGO_CONFIABLES`.

Los dos primeros son nuevos y arreglan una fuga real. Ver §6.

---

## 4. Flujo de una petición

```
GET /platform/client/pax/pms/pms_guia/{localizador}
        │
        ▼
┌─────────────────────────────────────────────────────────┐
│ PmsGuiaHuespedProvider::provide()                       │
└─────────────────────────────────────────────────────────┘
        │
        ├─ PmsReserva por localizador ──────────► null ⇒ 404
        │
        ├─ getEventosActivosGuia()  ◄── filtra estado + guiaDisabled
        │       └─ elegirEvento(slug) ─────────► null ⇒ 404
        │
        ├─ PmsGuiaAcceso::paraEvento(evento)   ── LA REGLA (§3)
        ├─ PmsGuiaContexto::construir(unidad, evento)
        │       ├─ $valores    (nombre, unidad, fechas)  → siempre
        │       └─ $sensibles  (door/safe/keybox)        → solo si Activa
        │
        ▼
┌─────────────────────────────────────────────────────────┐
│ PmsGuiaArbolFiltro::podar()                             │
│   por cada sección → por cada ítem:                     │
│     acceso->permite(visibilidad) ?                      │
│        sí → interpolar y conservar                      │
│        no → debeAnunciarBloqueo() ? bloquear : DESCARTAR│
│   sección sin ítems ⇒ se descarta la sección            │
└─────────────────────────────────────────────────────────┘
        │
        ▼  PmsGuiaInterpolador::interpolar()   ({{ door_code }} → valor | mensaje)
        │
        ▼  propiedades virtuales de PmsGuia (no persistidas)
    seccionesParaCliente · contextoParaCliente · redesWifiParaCliente · accesoParaCliente
        │
        ▼  normalizationContext: ['groups' => ['pax_guia:read']]
```

El catálogo público (`PmsUnidadCatalogoProvider`) recorre lo mismo con
`PmsGuiaAcceso::publico()` y `PmsGuiaContexto::construir($unidad, null)` — que ni siquiera carga
las credenciales en memoria — y serializa con `pax_catalogo:read`.

### Interpolación: por qué se movió a PHP

`RichContentEngine.interpolateString()` sustituía los placeholders **en el navegador**. Para
poder hacerlo, el backend tenía que mandarle al cliente el diccionario completo de valores
—`text_fixed` con `door_code`, `safe_code`, `keybox_*`— y el front decidía cuál pintar. Bastaba
abrir las herramientas de desarrollo para leer el código de la puerta antes de tiempo.

`PmsGuiaInterpolador` hace la sustitución antes de serializar. Reparto actual:

| Placeholder | Quién lo resuelve | Por qué |
|---|---|---|
| `{{ door_code }}`, `{{ guest_name }}`, … | **PHP** (`PmsGuiaInterpolador`) | Son datos, y algunos son sensibles |
| `{{ video: url }}`, `{{ img: }}`, `{{ map: }}`, `{{ widget: wifi }}` | **Vue** (`RichContentEngine`) | Son maquetación; los componentes están en el front |

Las dos expresiones regulares tienen que seguir siendo compatibles: la de PHP no admite `:`
dentro de la clave, que es justo lo que deja pasar los bloques de maquetación. **Si se toca una,
se toca la otra**, o habrá placeholders que un lado sustituye y el otro enseña crudos.

Se conserva la forma i18n `[{language, content}]` en lugar de resolver el idioma en el servidor:
el selector de idioma de la guía cambia el texto en caliente y no debe disparar una petición.

---

## 5. Rutas, slugs y endpoints

| Ruta pax | Qué es | Endpoint |
|---|---|---|
| **`/huesped/reserva/:localizador`** | **Entrada canónica** — índice de estancias | `GET /platform/client/pax/pms/pms_reserva/{loc}` |
| `/huesped/reserva/:localizador/:unidad` | **Guía de una estancia** (`guia_huesped`) | `GET /platform/client/pax/pms/pms_guia/{localizador}/{unidadSlug}` |
| `/huesped/reserva/:localizador/guia` | La misma, sin elegir unidad (`guia_huesped_corta`) | `GET /platform/client/pax/pms/pms_guia/{localizador}` |
| `/:establecimiento/:unidad` | **Catálogo público** | `GET /platform/public/pax/pms/pms_guia/{est}/{unidad}` |

Esas son **todas**. No hay circuito heredado: `/pax/client/guiahelper/{uuid}`,
`/pax/public/guiahelper/{uuid}` y `/public/pax/pms/pms_guia/pms_unidad/{uuid}` están borrados,
igual que el grupo de serialización `pax_evento:read` que los alimentaba.

### 5.1. El front de la guía

| Pieza | Archivo |
|---|---|
| Vista | `pax/src/views/huesped/HuespedGuiaView.vue` |
| Store | `pax/src/stores/huesped/paxHuespedGuiaStore.ts` |
| Tipos | `pax/src/types/paxHuespedGuiaModel.ts` (espejo de `pax_guia:read`) |
| Enlace de entrada | `PmsReservaView::verGuiaEvento()` |

**Navegación de 3 niveles**, heredada de la `GuiaUnidadView` anterior porque es lo que el
huésped ya conocía:

| Nivel | Qué muestra |
|---|---|
| 1 · Portada | hero (tap → sección descriptiva), aviso de acceso, anfitrión, **ingreso destacado con sus pasos como botones directos al nivel 3**, rejilla del resto de secciones |
| 2 · Sección | lista de sus ítems; con **un solo ítem se salta el paso** y se pinta el contenido |
| 3 · Ítem | texto (`RichTextRenderer`, que expande `{{ widget: wifi }}`), galería con lightbox y botón |

Los niveles 2 y 3 son **capas que entran deslizándose sobre la misma ruta**, y su estado vive en
la **query** (`?section=…&item=…`), no en un `ref` del componente. El motivo no es estético: así
el «atrás» del móvil retrocede **un nivel** en vez de sacar al huésped de la guía. `volver()`
usa `router.back()` si hay historial y cae a `replace` si se entró por enlace directo.

> **Gotcha — al abrir un nivel hay que scrollear la VENTANA, no el contenedor.** El marco es
> `min-h-screen`, así que en móvil el `h-full` del nivel 1 no acota altura: quien scrollea es la
> ventana y el contenedor crece con el contenido (en escritorio sí manda el div, porque
> `md:h-[90vh]` da altura definida). Como las capas son `absolute inset-0`, se montan al
> principio del contenedor: si la ventana sigue a media página, se ve el hueco en blanco de
> debajo de una capa más corta que la portada. `resetScroll()` scrollea la ventana **y** los tres
> contenedores, con `behavior: 'auto'` — con desplazamiento suave el blanco se ve igual durante
> la animación de entrada. Ya se perdió una vez al reescribir la vista.

Consecuencia a respetar: **la recarga de datos observa solo la estancia**
(`[props.localizador, props.unidad]`). Observar `route.params` entero —o la ruta completa—
dispararía una recarga con spinner en cada navegación entre secciones.

Decisiones que no se ven leyendo el componente:

- **Ni un `v-if` de permisos.** El árbol llega podado y con los `{{ placeholders }}` resueltos:
  si un código está en el payload, se enseña. `acceso.estado` solo elige el aviso de cabecera y
  `redesWifi` llega vacío con la ventana cerrada — no hay contraseña enmascarada que interpretar,
  que era el contrato viejo (`texto.includes('*')`).
- **Vista separada del escaparate**, no un `mode: 'public' | 'guest'`. Ese parámetro fue el origen
  de la fuga que documenta §2.
- **El store no persiste y limpia al desmontar.** El anterior guardaba los códigos en
  `localStorage` sin caducidad: un móvil prestado seguía abriendo la puerta meses después.
- **El enlace va por `{localizador}/{slug de unidad}`**, no por el UUID del evento. Para eso
  `PmsUnidad::getSlug()` se añadió al grupo `pax_reserva:read`: sin él, el índice de estancias no
  puede construir la URL. El botón apuntaba a una ruta `guia_evento` borrada con el contrato
  viejo, y reventaba el router con `No match for {"name":"guia_evento"}`.
- **La sección `ingreso` se destaca** en la portada (en el escaparate encabeza la `descriptivo`):
  quien abre esto recién llegado busca cómo entrar, no la descripción del sitio. Por eso sus
  ítems son botones directos al nivel 3, sin pasar por la lista.
- **El WiFi no tiene tarjeta propia en la portada.** Se pinta donde el editor puso
  `{{ widget: wifi }}` dentro de un ítem; duplicarlo arriba mostraba las credenciales dos veces.

### `/huesped/reserva/:localizador` es intocable

Es la URL que se reparte a los clientes: va en correos de confirmación y en mensajes ya
enviados, así que **no se renombra, no se mueve y no se redirige**. Todo lo demás de la rama del
huésped cuelga de ella.

De ahí salen dos consecuencias de diseño:

- La guía de una estancia es una **hija** de esa ruta (`…/:localizador/:unidadSlug`), no una
  rama hermana con otro prefijo. El enlace que ya circula sigue siendo el ancla.
- El prefijo `huesped` está **reservado** en el router (ver el `path` de `catalogo_unidad`): un
  establecimiento cuyo slug fuera "huesped" no puede secuestrar esa rama.

El backend ya sirve las dos formas: `PmsGuiaHuespedProvider` responde a `{localizador}` a secas
—devolviendo la primera estancia cronológica, para que un enlace corto siempre lleve a algún
sitio útil— y a `{localizador}/{unidad}` para elegir una concreta.

### Slugs

`PmsEstablecimiento::$slug` + `PmsUnidad::$slug`, ambos `UNIQUE` global, generados por
`PmsSlugListener` con `AsciiSlugger`. El de la unidad se prefija con el del establecimiento para
que dos "Habitación 1" de hoteles distintos no compitan en un espacio de nombres que es global.

**El slug no se regenera al renombrar.** Una vez publicada, la URL es una dirección que la gente
comparte y que indexan los buscadores; cambiar el nombre comercial no debe romper enlaces vivos.
Para cambiarla, se edita el slug a mano.

El slug es **solo del escaparate**. La guía del huésped va por localizador de reserva.

### Orden en el router de Vue

`/:establecimiento/:unidad` va **la última antes del fallback**: es la ruta más genérica que
existe (dos segmentos libres). `vue-router` puntúa los segmentos estáticos por encima de los
dinámicos, así que `/file/XJ922M` y `/catalogo/...` ganan igualmente — pero declararla al final
lo deja explícito.

---

## 6. Qué se arregló y qué sigue roto

### Arreglado

| Problema | Dónde estaba | Arreglo |
|---|---|---|
| **Reserva cancelada pero pagada entregaba los códigos reales** | `calcularAcceso()` solo miraba `estadoPago`; cancelar no lo resetea | `PmsGuiaAcceso::paraEvento()`, comprobación 1 |
| **`guiaDisabled` se saltaba por URL directa** | `GuiaHelperClientController` hacía `find($id)` sin filtrar | comprobación 2 + `getEventosActivosGuia()` en el provider |
| **El árbol completo era público** | `getSeccionesApi()` no filtraba nada | `PmsGuiaVisibilidad` + `PmsGuiaArbolFiltro` |
| **Los códigos viajaban al navegador** | `text_fixed` los mandaba siempre | `PmsGuiaInterpolador` (§4) |
| **La tarjeta de anfitrión nunca se pintaba** | `buildResponse()` jamás puso `host_name`/`host_whatsapp` en `text_fixed`, pero `GuiaUnidadView` los leía | `PmsGuiaContexto::construir()` los cablea al establecimiento |

| **Enumeración de localizadores** | 6 caracteres sobre 31 símbolos, sin freno | `PmsGuiaThrottle` (§6.1) |

### 6.1. Por qué hay un throttle

El localizador **es** la credencial. `LocatorTrait` lo genera con 6 caracteres sobre un alfabeto
de 31: 31⁶ ≈ 887 millones. Suena a mucho hasta que se cuenta el espacio **útil** — al atacante no
le hace falta adivinar uno concreto, le vale cualquiera que exista:

| Reservas vivas | Intentos por acierto | A 100 req/s |
|---|---|---|
| 1.000 | ~887.500 | 2,5 h |
| 5.000 | ~177.500 | **30 min** |
| 20.000 | ~44.375 | **7 min** |

Y detrás de un acierto está el nombre del huésped, sus fechas y —dentro de la ventana— el código
de la puerta.

`PmsGuiaThrottle` cuenta **solo los fallos** por IP, no las peticiones: un huésped legítimo pide
siempre un localizador que existe, así que nunca consume presupuesto y puede recargar su guía
sin límite. Un enumerador produce casi solo fallos y se queda seco en 10 intentos por ventana de
15 minutos.

Dos detalles que no son adorno:

- **Todos los caminos de 404 consumen presupuesto**, no solo "la reserva no existe". Si un
  localizador válido pero sin estancias visibles saliera gratis, esa diferencia sería un oráculo
  para separar los reales de los inventados. De eso se encarga `PmsGuiaHuespedProvider::rechazar()`.
- **La ventana se fija en el primer fallo y no se renueva.** Si se renovara con cada intento,
  quien siguiera probando estiraría su propio castigo hasta hacerlo permanente.

Está sobre el pool de caché y no sobre `symfony/rate-limiter` para no añadir una dependencia; si
algún día entra ese componente, esta clase es el único punto a sustituir.

> **`CotizacionFile` tiene exactamente la misma exposición** y hoy no está protegido: su
> localizador sale del mismo `LocatorTrait` y `CotizacionFilePublicProvider` responde sin freno.
> `PmsGuiaThrottle` es reutilizable tal cual.

### Pendiente

1. ~~La guía del huésped no tiene front.~~ **Hecha**: `HuespedGuiaView.vue` +
   `paxHuespedGuiaStore.ts` + `paxHuespedGuiaModel.ts`. Ver §5.1.

2. **Zona horaria.** La ventana se calcula con `new \DateTimeImmutable()` del servidor.
   `PmsEstablecimiento::getTimezone()` existe pero no participa. Mientras servidor y alojamiento
   compartan `America/Lima` el cálculo es correcto; con un establecimiento en otra zona, no.

3. **`Cache-Control: no-store` en el provider privado — sigue pendiente en el SERVIDOR.**
   El lado cliente ya está cubierto: `paxHuespedGuiaStore` pide con `cache: 'no-store'`, no
   persiste en `localStorage` y limpia el payload al desmontar la vista. Falta que la respuesta
   viaje con la cabecera, que es lo que protege de proxies y cachés intermedias.

---

## 7. Gotchas

- **`store.loading` no cubre el arranque de la vista.** Los stores de pax nacen con
  `loading: false` y `guia/reserva: null` — combinación idéntica a "no encontrada". Toda vista
  que haga trabajo previo a la petición (p. ej. `maestroStore.cargarConfiguracion()`) pinta la
  rama de error un instante antes del spinner, salvo que mantenga un flag local que arranque en
  `true` y baje en el `finally` de su `cargar()`. Así lo hacen `HuespedGuiaView` (`cargando`) y
  `PmsReservaView` (`isReady`); una vista nueva debe repetir el patrón.
- **Propiedades virtuales, no columnas.** `seccionesParaCliente`, `contenidoParaCliente`,
  `bloqueadoHasta`… no están mapeadas. Los providers escriben en entidades gestionadas por el
  EntityManager, pero no las ensucian: no hay `flush()` en el camino de lectura de la API. Mismo
  patrón que `CotizacionFile::$versionesParaCliente` (ver `docs/Cotizaciones.md`).

- **`getItemsApi()` / `getSeccionesApi()` no tienen grupo, y es a propósito.** Devuelven el árbol
  **sin filtrar**; son la entrada de `PmsGuiaArbolFiltro`. Lo mismo con `PmsGuiaItem::getTitulo()`
  y `getDescripcion()`, que devuelven el contenido crudo con los `{{ placeholders }}` sin
  resolver. **Ponerles un `#[Groups]` a cualquiera de los cuatro reabre el agujero**: el primero
  publicaría el árbol entero, el segundo enseñaría `{{ door_code }}` literal en pantalla.

- **`PmsGuiaItem` se comparte entre secciones y guías.** La visibilidad es del ítem, así que
  marcar uno como público lo publica en **todas** las unidades que reutilicen esa sección común.

- **Doctrine congela el changeset antes de `preUpdate`.** `PmsSlugListener` llama a
  `recomputeSingleEntityChangeSet()`; sin eso, un slug asignado en `preUpdate` no llegaría al
  `UPDATE`. Mismo motivo por el que lo hace `PmsEventoCalendarioIntegrityListener`.

- **El backfill de slugs no translitera acentos.** MySQL no trae una función para eso, así que
  `Casita Ñusta` queda como `casita-usta`. Son pocas filas y se corrige en el panel; las altas
  nuevas sí pasan por `AsciiSlugger`, que sí translitera.

- **El catálogo devuelve 404 si no hay nada publicado.** No sirve la cáscara con título y foto:
  solo serviría para que los buscadores indexaran una página vacía.

---

## 8. Dónde tocar para cambiar X

| Necesito… | Archivo | Símbolo |
|---|---|---|
| **Cambiar quién ve qué** | `src/Pms/Guia/PmsGuiaAcceso.php` | `permite()` (la matriz, §3) |
| Cambiar las horas de anticipación | mismo archivo | `HORAS_ANTICIPACION` |
| Cambiar qué invalida una estancia | mismo archivo | `paraEvento()`, las tres comprobaciones |
| Que un ítem bloqueado se oculte en vez de anunciarse | mismo archivo | `debeAnunciarBloqueo()` |
| Añadir un nivel de visibilidad | `src/Pms/Enum/PmsGuiaVisibilidad.php` **y** `PmsGuiaAcceso::permite()` | el `match` de los dos |
| Añadir un placeholder de datos | `src/Pms/Guia/PmsGuiaContexto.php` | `construir()`, `$valores` o `$sensibles` |
| Marcar un placeholder como sensible | `src/Pms/Guia/PmsGuiaInterpolador.php` | `CLAVES_SENSIBLES` |
| Cambiar los textos de bloqueo o añadir un idioma | `src/Pms/Guia/PmsGuiaMensajes.php` | las constantes |
| Añadir un bloque de maquetación (`{{ algo: valor }}`) | `pax/src/core/RichContentEngine.ts` | `COMPONENT_REGISTRY` |
| Cambiar el podado del árbol | `src/Pms/Guia/PmsGuiaArbolFiltro.php` | `podar()`, `podarItems()` |
| Cambiar qué estancia abre el enlace corto | `src/Api/Provider/Pms/PmsGuiaHuespedProvider.php` | `elegirEvento()` |
| Cambiar qué exige el catálogo para existir | `src/Api/Provider/Pms/PmsUnidadCatalogoProvider.php` | `provide()`, los guards |
| Añadir un campo al payload del huésped | `src/Pms/Entity/PmsGuia.php` | propiedad virtual + `pax_guia:read` |
| Añadir un campo al catálogo | entidad correspondiente | grupo `pax_catalogo:read` |
| Cambiar cómo se generan los slugs | `src/Pms/EventListener/PmsSlugListener.php` | `generarUnico()`, `normalizar()` |
| **Ajustar el freno anti-enumeración** | `src/Pms/Guia/PmsGuiaThrottle.php` | `MAX_FALLOS`, `VENTANA` |
| Proteger también el localizador de cotizaciones | `src/Api/Provider/Cotizacion/CotizacionFilePublicProvider.php` | reutilizar `PmsGuiaThrottle` (§6.1) |
| Clasificar contenido desde el panel | `src/Pms/Controller/Crud/PmsGuiaItemCrudController.php` | `ChoiceField::new('visibilidad')` |
| **Cambiar el diseño de la guía del huésped** | `pax/src/views/huesped/HuespedGuiaView.vue` | `secciones`, `avisoAcceso`, `mensajeError` |
| Llamadas HTTP de la guía | `pax/src/stores/huesped/paxHuespedGuiaStore.ts` | `cargar()`, `limpiar()` |
| Cambiar tipos de la guía | `pax/src/types/paxHuespedGuiaModel.ts` | espejo de `pax_guia:read` |
| Cambiar a dónde lleva el botón «Ver guía» | `pax/src/views/huesped/PmsReservaView.vue` | `verGuiaEvento()` |
| Cambiar el diseño del escaparate | `pax/src/views/huesped/CatalogoUnidadView.vue` | `fotos`, `seccionesOrdenadas`, `whatsappUrl` |
| Cambiar tipos del catálogo | `pax/src/types/paxCatalogoUnidadModel.ts` | espejo de `pax_catalogo:read` |
| Llamadas HTTP del catálogo | `pax/src/stores/huesped/paxCatalogoUnidadStore.ts` | `cargar()` |
| Retirar el circuito heredado | §6, lista de pendientes | — |
