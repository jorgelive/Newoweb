# Plan — la propuesta operativa, la publicación y el filtrado por subgrupo

Cómo se representa **lo que de verdad va a pasar en el viaje** cuando deja de parecerse a lo que se
vendió, sin que el cliente pierda los precios que aprobó y sin duplicar trabajo.

**Alcance:** plan de ejecución con fases y criterios de «hecho». El modelo de propuestas está en
`docs/Cotizaciones.md` §6.j.0.

**Estado:** redactado el 02/09/2026 tras una conversación que descartó tres diseños antes de éste
(§8). Sin empezar.

---

## Índice

1. [El caso, tal como ocurre](#1-el-caso-tal-como-ocurre)
2. [Los tres ejes que hoy están mezclados](#2-los-tres-ejes-que-hoy-están-mezclados)
3. [Cómo queda el dato](#3-cómo-queda-el-dato)
4. [El borrador operativo sale gratis](#4-el-borrador-operativo-sale-gratis)
5. [Fases](#5-fases)
6. [Lo que NO entra, y cómo entraría](#6-lo-que-no-entra-y-cómo-entraría)
7. [Dónde choca con el cálculo compartido](#7-dónde-choca-con-el-cálculo-compartido)
8. [Diseños descartados, y por qué](#8-diseños-descartados-y-por-qué)
9. [Decisiones abiertas](#9-decisiones-abiertas)

---

## 1. El caso, tal como ocurre

Se cotiza un grupo: todo junto, un vuelo, N pax. Después la realidad se separa —el grupo se parte
en vuelos nacional e internacional, algunos se integran ya en destino— y el servicio «Vuelo» acaba
con **dos componentes**. Las cantidades por componente **dejan de sumar el total del grupo**.

Sobre eso hay que emitir las órdenes. Pero:

> **Ya está vendido y ya se cobró. Lo que pase en la operación es un tema proveedor–agencia.** El
> cliente sigue viendo el financiero de la confirmada, y el descriptivo de la operativa.

## 2. Los tres ejes que hoy están mezclados

`estado` hace **dos trabajos a la vez**, y por eso chocan. Se ve en una queja concreta del día a
día: *«para ver la cotización antes de mandarla tengo que ponerle enviada»* — mentir sobre un acto
comercial para conseguir una visibilidad.

```
estado       dónde está comercialmente    pendiente · enviado · confirmado · OPERATIVA · cerrado…
publicado    ¿el cliente puede verlo?     bool, INDEPENDIENTE del estado
congelada    consecuencia de estar vendida — por convención, no por candado (§5, F3)
```

⚠️ **Separarlos disuelve un problema que parecía difícil.** Con `publicado` independiente ya no
hace falta «desempatar» qué fila ve el cliente cuando hay varias públicas en una propuesta: cada
eje decide lo suyo y la pregunta no existe.

## 3. Cómo queda el dato

```
propuesta 1
 ├── HISTORICO ×N    fotos del proceso de venta
 ├── CONFIRMADA      lo vendido · el financiero que ve el cliente
 │                   en la práctica, «un histórico que además calculó precios»
 └── OPERATIVA       viva · aquí ocurre TODO lo posterior · aquí viven las órdenes
                     derivadaDe → la confirmada
```

Y la vista del cliente **se compone de las dos**:

```
financiero  ←  SIEMPRE la confirmada
itinerario  ←  la operativa SI está publicada · si no, la confirmada
```

Ejemplo con datos reales de `2KVBMX`, que hoy tiene 47 filas de operación colgando de su
confirmada:

```
Servicio «Vuelo»
  en la CONFIRMADA    Componente «Vuelo LIM–PUJ»                              40 pax
  en la OPERATIVA     Componente «Vuelo LIM–PUJ»  grupo=#Vuelo Nacional       22 pax
                      Componente «Vuelo LIM–PUJ»  grupo=#Vuelo Internacional  18 pax
                            │                            │
                      OperacionServicio ×2 — cada una con su hora, su punto y su orden
```

⚠️ **La operativa nace al CONFIRMAR, sin publicar.** Y eso es lo que hace que **nunca haya que
traspasar filas de operación**: nacen ya en la operativa. La alternativa —crearla a demanda—
dejaba una ventana con dos filas con operación viva a la vez, que es exactamente el escenario que
`CotizacionConfirmadaEventListener` describe como *«riesgo de pedirle y pagarle dos veces lo mismo
al proveedor»*.

El coste de crearla siempre es una operativa sin usar en expedientes individuales. **Nace
invisible**, así que no molesta a nadie — y sigue siendo donde viven las órdenes, que es lo que de
verdad hace.

## 4. El borrador operativo sale gratis

No hace falta ningún estado «borrador» ni despublicar nada:

```
Confirmas     → se congela lo vendido · nace la operativa SIN publicar
                el cliente ve exactamente lo mismo que antes

Reorganizas   → partes vuelos, mueves horarios, asignas subgrupos
                el cliente sigue viendo el itinerario de la confirmada
                no ve una página a medias ni un enlace roto

Publicas      → ahora ve el itinerario real
                y sus precios siguen siendo los que aprobó
```

**El borrador es la operativa antes de publicarla.** Nada más.

## 5. Fases

### F1 · La publicación como eje propio

| | Acción | Hecho cuando |
|---|---|---|
| 1.1 | `Cotizacion.publicado` (bool) + migración: `true` donde estado ∈ (enviado, confirmado) | El día del despliegue nadie ve nada distinto |
| 1.2 | `esVisibleParaCliente()` mira `publicado`, no el estado | |
| 1.3 | **Previsualización del operador**: el provider deja pasar lo no publicado si quien pide está autenticado | Ver la vista cliente sin tocar el estado |

⚠️ 1.3 no necesita enlace ni token especial: `util` y `pax` comparten dominio de cookie
(`FRAMEWORK_SESION_COOKIE_DOMAIN`), así que basta una condición.

### F2 · La propuesta operativa

| | Acción | Hecho cuando |
|---|---|---|
| 2.1 | ⚠️ **El `match` de `CotizacionConfirmadaEventListener`, con el caso explícito** | Un estado nuevo no puede caer en `default => null` |
| 2.2 | `OPERATIVA` en `CotizacionEstadoEnum` + sus tres `match` | Compila |
| 2.3 | Confirmar **crea la operativa** (clon, sin publicar, `derivadaDe`) y las filas de operación nacen ahí | Nunca hay dos filas con operación viva |
| 2.4 | Vista cliente **compuesta**: financiero de la confirmada, itinerario de la operativa publicada | |
| 2.5 | Front: estado en el mapa, botón de publicar, y que el editor diga dónde está | |

⚠️ **2.1 es el único punto mudo de toda la fase.** Los tres `match` del enum y la unión generada de
los `api.d.ts` hacen saltar el compilador; el `default => null` del listener, no — y su propio
comentario advierte que un estado nuevo *«dejaría las filas activas sin que nada lo denunciara»*.

### F3 · Sin candado, y es una decisión

**La confirmada NO se bloquea técnicamente.** Queda congelada por convención: la operativa existe
justamente para no tener que tocarla. Si el operador la edita igualmente, es porque quiso — y
asume el doble trabajo de actualizar proveedores por los dos lados.

⚠️ Consecuencia aceptada: editar la confirmada **sí** regenera su `clasificacionFinancieraCliente`
y le cambia los precios al cliente. Eso es correcto: si alguien edita a conciencia lo vendido, está
renegociando, y el cliente debe ver lo nuevo.

Se descartó un candado en el servidor por eso — ver §8.

### F4 · Quién ve qué en un expediente grupal

**El mapa de acceso**, decidido el 02/09/2026:

```
Portada del expediente                         sin identidad
 ├── «Ver cotización» → la OPERATIVA           ← formulario de identificación
 └── abajo: confirmadas e históricas           ← sin identidad
```

La operativa es la única puerta cerrada, y por un motivo claro: es la que lleva **datos por
persona** —tu vuelo, tu código, tu horario—. Lo comercial es el mismo documento para todos.

⚠️ **En grupales se quita el manifiesto de la portada.** Hoy `PaxFilePortadaView` lista a los 133
pasajeros **con su número de documento**, a la vista de cualquiera con el enlace. En un expediente
individual el «manifiesto» es la familia y está bien; en uno grupal no es ni útil ni prudente.

| | Acción |
|---|---|
| 4.1 | `FileModoEnum::ocultaManifiesto()` → `GRUPO`, y la portada lo respeta |
| 4.2 | Endpoint: documento + fecha de nacimiento → identifica al pasajero |
| 4.3 | El provider sirve la operativa sólo a quien se identificó, y filtrada a lo suyo |
| 4.4 | Límite de intentos |

**El tercero de una familia.** `ocultaManifiesto()` se suma a `exigeIdentificacion()` y
`ocultaTotalDeGrupo()`, que ya existen y ya devuelven `GRUPO`. Tres comportamientos en un solo
sitio, cada uno **nombrado por lo que hace** en vez de por `esGrupo()`.

#### Dónde vive la identidad: la SESIÓN, no una cookie propia

`util` y `pax` ya comparten dominio de cookie (`FRAMEWORK_SESION_COOKIE_DOMAIN`), y el host de la
API está bajo el firewall `main`, que es *stateful* — sólo `^/platform/api_stateless` es sin
estado. **La máquina ya está montada.**

```php
$session->set("pax_identificado.{$fileId}", $pasajeroId);
```

| | Sesión | Cookie firmada (JWT) |
|---|---|---|
| Estado en servidor | sí (~130 por expediente: trivial) | no |
| **Revocable** | **al instante** | no hasta que caduque |
| Cripto que mantener | ninguna | secreto, caducidad, firma |

La revocabilidad pesa: si el enlace se filtra en el grupo de WhatsApp, con sesión se corta y ya.

⚠️ **La clave lleva el `fileId` dentro.** Con una sola clave global, identificarse en un expediente
abriría otro — bastaría el enlace de otro grupo.

⚠️ **Nada de `localStorage`**: cualquier script de la página lo lee. Una cookie de sesión es
`HttpOnly` y no. Para algo que abre datos personales de 130 familias esa diferencia no es teórica.

⚠️ **Caducidad de días, no de meses**, renovándose con el uso: un padre entra desde el móvil y
luego desde un ordenador del trabajo que se comparte.

⚠️ **El 401 no distingue** «ese documento no está» de «la fecha no coincide». Distinguirlos
convierte el formulario en un comprobador de quién viaja. Y 4.4 no es paranoia: la llave son datos
que circulan por WhatsApp en un grupo de colegio.

### F5 · El filtrado por subgrupo

| | Acción |
|---|---|
| 5.1 | `CotizacionCotcomponente.grupo` → `?CotizacionFileGrupo`, **null = aplica a todos** · migración |
| 5.2 | `CotizacionPasajeroGrupo.codigo` → el localizador aéreo **de esa persona en ese vuelo** · migración |
| 5.3 | Editor: asignar subgrupo a un componente |
| 5.4 | Chequeo: **unión(subgrupos) ⊇ pasajeros** — huecos y solapes |
| 5.5 | Guía: el pasajero identificado ve sólo lo suyo, con su código |
| 5.6 | Órdenes: salen con la gente del subgrupo |

El eje ya existe y admite el multitramo **sin tocar código**: `reserva_aerea` con subeje libre
(`#Vuelo Nacional`, `#Vuelo Internacional`). Cargar quién vuela en qué se puede hacer hoy.

⚠️ **5.4 cambia la invariante, y ése es el punto.** Lo correcto no es `suma(cantidades) == numPax`
—falso en cuanto hay dos vuelos— sino `unión(subgrupos) ⊇ pasajeros`. Que las cantidades no cuadren
deja de ser una anomalía a tolerar: pasa a ser lo normal, y lo que se vigila es otra cosa.

⚠️ **El código de vuelo va en el PIVOTE.** Una persona tiene un código por vuelo y un vuelo tiene
un código por persona: es un dato de la intersección, que es lo que `CotizacionPasajeroGrupo` es.

## 6. Lo que NO entra, y cómo entraría

**Los que se integran a mitad de viaje.** Descartado a conciencia.

Con subgrupos habría que **describir una ausencia**: etiquetar todos los hoteles y tours que esas
personas no hacen, N anotaciones que crecen con el viaje. Compáralo con los vuelos, donde el mismo
diseño cuesta **dos** porque describes lo que pasa.

> **Si una función te obliga a anotar todo, el dato está en la entidad equivocada.**

Si vuelve, vuelve barato y sin rehacer nada:

```
CotizacionFilepasajero + participaDesde / participaHasta   (null = todo el viaje)

ve un componente si:  (sin subgrupo O está en él)  Y  (el día cae en su tramo)
```

Un campo, en una persona, y ningún componente se entera.

## 7. Dónde choca con el cálculo compartido

**Un componente acotado a 12 de 40 pax hay que valorarlo por 12**, y el cálculo financiero vive
sólo en el navegador: el servidor no lo alcanza.

**F5 se construye entera salvo el precio.** Vuelos, horarios, namelist y órdenes funcionan; la
valoración espera a la fase 5 de `docs/PlanProcesamientoCompartido.md`. No es un bloqueo, pero
conviene no descubrirlo a mitad.

## 8. Diseños descartados, y por qué

Se dejan escritos porque el motivo sigue siendo útil.

| Diseño | Por qué se cayó |
|---|---|
| **Una cotización operativa como documento nuevo** | Dos árboles que sincronizar a mano. La operativa es otra fila de la misma propuesta, como el histórico |
| **Editar la confirmada y congelar sólo su snapshot** | Requería un candado en el servidor contra un editor que regenera ese campo en cada guardado. Con la confirmada congelada entera, el problema no se protege: desaparece |
| **Crear la operativa a demanda** | Deja una ventana con dos filas con operación viva y obliga a coordinar un traspaso de las filas. Naciendo al confirmar, nunca hay nada que traspasar |
| **Un estado «borrador»** | `publicado` ya lo da: el borrador es la operativa antes de publicarla |
| **Desempatar entre filas públicas** | Con `publicado` independiente no puede haber dos. La pregunta desaparece |

## 9. Decisiones abiertas

| | La pregunta |
|---|---|
| 1 | **Qué ve quien NO se identifica**: ¿nada, la portada sin padrón, o el itinerario común sin datos personales? |
| 2 | **Si dos propuestas aprobadas son complementarias** (Lima + Bolivia), ¿la guía las fusiona por fecha o las enseña sueltas? |

La 2 es la más grande: el pasajero vive **un viaje**, no dos documentos — pero eso hay que quererlo,
no deducirlo.

---

## Dónde tocar para cambiar X

| Necesidad | Archivo |
|---|---|
| Si el cliente ve una propuesta | `Cotizacion::$publicado` — **no** el estado |
| Qué pasa al cambiar de estado | `CotizacionConfirmadaEventListener` — el `match`, **explícito siempre** |
| Que confirmar abra la operación | el processor de confirmación (F2.3) |
| A quién aplica un componente | `CotizacionCotcomponente::$grupo` |
| El código de vuelo de una persona | `CotizacionPasajeroGrupo::$codigo` |
| Los ejes de agrupación | `GrupoTipoEnum` — el subeje es texto libre, no hace falta un `case` por tramo |
